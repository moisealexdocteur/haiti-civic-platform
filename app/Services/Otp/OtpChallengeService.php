<?php

namespace App\Services\Otp;

use App\Services\AuditService;
use App\Services\IdentityInputNormalizer;
use App\Services\TenantContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OtpChallengeService
{
    public const PURPOSE_CITIZEN_PHONE =
        'citizen_phone_verification';

    public const TTL_SECONDS = 300;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public const MAX_PER_PHONE_PER_HOUR = 5;

    public const MAX_PER_REQUEST_FINGERPRINT_PER_HOUR = 20;

    public const MAX_ATTEMPTS = 5;

    private const LOCK_TIMEOUT_SECONDS = 5;

    private BaseConnection $db;
    private IdentityInputNormalizer $normalizer;
    private OtpChallengeCrypto $crypto;
    private AuditService $audit;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?IdentityInputNormalizer $normalizer = null,
        ?OtpChallengeCrypto $crypto = null,
        ?AuditService $audit = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->normalizer = $normalizer
            ?? new IdentityInputNormalizer();
        $this->crypto = $crypto
            ?? new OtpChallengeCrypto($tenantContext);
        $this->audit = $audit
            ?? new AuditService($tenantContext, $this->db);
    }

    /**
     * Le code en clair n'est retourné qu'au caller afin d'être remis
     * immédiatement au transport. Il n'est jamais persisté par ce service.
     *
     * @return array{
     *   uuid:string,
     *   code:string,
     *   normalized_phone:string,
     *   purpose:string,
     *   requested_channel:string,
     *   expires_at:string,
     *   ttl_seconds:int
     * }
     */
    public function issue(
        string $phone,
        string $purpose = self::PURPOSE_CITIZEN_PHONE,
        ?OtpChannel $requestedChannel = null,
        ?string $requestFingerprint = null
    ): array {
        $normalizedPhone =
            $this->normalizer->normalizeHaitiPhone($phone);

        if ($normalizedPhone === null) {
            throw new InvalidArgumentException(
                'Phone number is required for OTP.'
            );
        }

        $purpose = $this->normalizePurpose($purpose);
        $requestedChannel ??= OtpChannel::WHATSAPP;
        $requestFingerprint = $this->normalizeFingerprint(
            $requestFingerprint
        );

        $phoneFingerprint =
            $this->crypto->phoneFingerprint($normalizedPhone);

        $tenantId = $this->tenantContext->id();
        $lockName = $this->issueLockName(
            $tenantId,
            $phoneFingerprint
        );

        $this->acquireLock($lockName);

        try {
            $this->db->transBegin();
            $this->assertTenantActive($tenantId);

            $nowTs = time();
            $now = gmdate('Y-m-d H:i:s', $nowTs);
            $hourAgo = gmdate('Y-m-d H:i:s', $nowTs - 3600);

            $phoneCount = $this->db
                ->table('otp_challenges')
                ->where('tenant_id', $tenantId)
                ->where('phone_fingerprint', $phoneFingerprint)
                ->where('purpose', $purpose)
                ->where('created_at >=', $hourAgo)
                ->countAllResults();

            if ($phoneCount >= self::MAX_PER_PHONE_PER_HOUR) {
                throw new RuntimeException(
                    'OTP issue rate limit exceeded.'
                );
            }

            if ($requestFingerprint !== null) {
                $requestCount = $this->db
                    ->table('otp_challenges')
                    ->where('tenant_id', $tenantId)
                    ->where('request_fingerprint', $requestFingerprint)
                    ->where('created_at >=', $hourAgo)
                    ->countAllResults();

                if (
                    $requestCount
                    >= self::MAX_PER_REQUEST_FINGERPRINT_PER_HOUR
                ) {
                    throw new RuntimeException(
                        'OTP requester rate limit exceeded.'
                    );
                }
            }

            $latest = $this->db
                ->table('otp_challenges')
                ->select('id, created_at')
                ->where('tenant_id', $tenantId)
                ->where('phone_fingerprint', $phoneFingerprint)
                ->where('purpose', $purpose)
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getFirstRow('array');

            if ($latest !== null) {
                $latestTs = strtotime(
                    (string) $latest['created_at'] . ' UTC'
                );

                if (
                    $latestTs !== false
                    && $latestTs
                        > $nowTs - self::RESEND_COOLDOWN_SECONDS
                ) {
                    throw new RuntimeException(
                        'OTP resend cooldown is active.'
                    );
                }
            }

            $this->db
                ->table('otp_challenges')
                ->where('tenant_id', $tenantId)
                ->where('phone_fingerprint', $phoneFingerprint)
                ->where('purpose', $purpose)
                ->where('consumed_at', null)
                ->where('invalidated_at', null)
                ->where('locked_at', null)
                ->where('expires_at >', $now)
                ->update(['invalidated_at' => $now]);

            $uuid = $this->uuid();
            $code = str_pad(
                (string) random_int(0, 999999),
                6,
                '0',
                STR_PAD_LEFT
            );

            $expiresAt = gmdate(
                'Y-m-d H:i:s',
                $nowTs + self::TTL_SECONDS
            );

            $inserted = $this->db
                ->table('otp_challenges')
                ->insert([
                    'uuid' => $uuid,
                    'tenant_id' => $tenantId,
                    'purpose' => $purpose,
                    'phone_fingerprint' => $phoneFingerprint,
                    'code_digest' => $this->crypto->codeDigest(
                        $uuid,
                        $code
                    ),
                    'attempts_used' => 0,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'requested_channel' => $requestedChannel->value,
                    'request_fingerprint' => $requestFingerprint,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'OTP challenge insert failed.'
                );
            }

            $this->audit->record(
                'otp.challenge_created',
                null,
                'public',
                'otp_challenge',
                $uuid,
                null,
                null,
                [
                    'purpose' => $purpose,
                    'requested_channel' => $requestedChannel->value,
                    'ttl_seconds' => self::TTL_SECONDS,
                    'max_attempts' => self::MAX_ATTEMPTS,
                ]
            );

            if (! $this->db->transStatus()) {
                throw new RuntimeException(
                    'OTP challenge transaction failed.'
                );
            }

            $this->db->transCommit();

            return [
                'uuid' => $uuid,
                'code' => $code,
                'normalized_phone' => $normalizedPhone,
                'purpose' => $purpose,
                'requested_channel' => $requestedChannel->value,
                'expires_at' => $expiresAt,
                'ttl_seconds' => self::TTL_SECONDS,
            ];
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * @return array{accepted:bool,reason:string,attempts_used:int}
     */
    public function verify(
        string $challengeUuid,
        string $code
    ): array {
        $challengeUuid = $this->normalizeUuid($challengeUuid);
        $code = trim($code);

        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            throw new InvalidArgumentException(
                'OTP code must contain exactly 6 digits.'
            );
        }

        $tenantId = $this->tenantContext->id();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->db->transBegin();

            $row = $this->db
                ->query(
                    'SELECT * FROM `otp_challenges` '
                    . 'WHERE `tenant_id` = ? AND `uuid` = ? '
                    . 'LIMIT 1 FOR UPDATE',
                    [$tenantId, $challengeUuid]
                )
                ->getFirstRow('array');

            if ($row === null) {
                $this->db->transCommit();
                return $this->result(false, 'not_found', 0);
            }

            $attempts = (int) $row['attempts_used'];
            $maxAttempts = (int) $row['max_attempts'];

            if ($row['consumed_at'] !== null) {
                $this->db->transCommit();
                return $this->result(
                    false,
                    'consumed',
                    $attempts
                );
            }

            if ($row['locked_at'] !== null) {
                $this->db->transCommit();
                return $this->result(false, 'locked', $attempts);
            }

            if ($row['invalidated_at'] !== null) {
                $this->db->transCommit();
                return $this->result(
                    false,
                    'invalidated',
                    $attempts
                );
            }

            if ((string) $row['expires_at'] <= $now) {
                $this->db->transCommit();
                return $this->result(false, 'expired', $attempts);
            }

            if ($attempts >= $maxAttempts) {
                $this->db
                    ->table('otp_challenges')
                    ->where('id', (int) $row['id'])
                    ->update([
                        'locked_at' => $now,
                        'updated_at' => $now,
                    ]);

                $this->auditLocked($row, $attempts);
                $this->db->transCommit();

                return $this->result(false, 'locked', $attempts);
            }

            $candidateDigest = $this->crypto->codeDigest(
                $challengeUuid,
                $code
            );

            if (! hash_equals(
                (string) $row['code_digest'],
                $candidateDigest
            )) {
                $attempts++;
                $locked = $attempts >= $maxAttempts;

                $update = [
                    'attempts_used' => $attempts,
                    'updated_at' => $now,
                ];

                if ($locked) {
                    $update['locked_at'] = $now;
                }

                $this->db
                    ->table('otp_challenges')
                    ->where('id', (int) $row['id'])
                    ->update($update);

                if ($locked) {
                    $this->auditLocked($row, $attempts);
                }

                if (! $this->db->transStatus()) {
                    throw new RuntimeException(
                        'OTP verification transaction failed.'
                    );
                }

                $this->db->transCommit();

                return $this->result(
                    false,
                    $locked ? 'locked' : 'invalid_code',
                    $attempts
                );
            }

            $this->db
                ->table('otp_challenges')
                ->where('id', (int) $row['id'])
                ->update([
                    'consumed_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                'otp.challenge_verified',
                null,
                'public',
                'otp_challenge',
                $challengeUuid,
                null,
                null,
                [
                    'purpose' => (string) $row['purpose'],
                    'requested_channel' =>
                        (string) $row['requested_channel'],
                    'attempts_used' => $attempts,
                ]
            );

            if (! $this->db->transStatus()) {
                throw new RuntimeException(
                    'OTP verification transaction failed.'
                );
            }

            $this->db->transCommit();

            return $this->result(true, 'accepted', $attempts);
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function auditLocked(array $row, int $attempts): void
    {
        $this->audit->record(
            'otp.challenge_locked',
            null,
            'public',
            'otp_challenge',
            (string) $row['uuid'],
            null,
            null,
            [
                'purpose' => (string) $row['purpose'],
                'requested_channel' =>
                    (string) $row['requested_channel'],
                'attempts_used' => $attempts,
            ]
        );
    }

    /** @return array{accepted:bool,reason:string,attempts_used:int} */
    private function result(
        bool $accepted,
        string $reason,
        int $attempts
    ): array {
        return [
            'accepted' => $accepted,
            'reason' => $reason,
            'attempts_used' => $attempts,
        ];
    }

    private function normalizePurpose(string $purpose): string
    {
        $purpose = strtolower(trim($purpose));

        if (
            preg_match('/^[a-z0-9._-]{1,50}$/D', $purpose)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'OTP purpose is invalid.'
            );
        }

        return $purpose;
    }

    private function normalizeFingerprint(
        ?string $fingerprint
    ): ?string {
        if ($fingerprint === null) {
            return null;
        }

        $fingerprint = strtolower(trim($fingerprint));

        if ($fingerprint === '') {
            return null;
        }

        if (
            preg_match('/^[0-9a-f]{64}$/D', $fingerprint)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'OTP request fingerprint must be SHA-256 hexadecimal.'
            );
        }

        return $fingerprint;
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[0-9a-f]{4}-[0-9a-f]{4}-'
                . '[0-9a-f]{12}$/D',
                $uuid
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'OTP challenge UUID is invalid.'
            );
        }

        return $uuid;
    }

    private function assertTenantActive(int $tenantId): void
    {
        $row = $this->db
            ->table('tenants')
            ->select('id')
            ->where('id', $tenantId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new RuntimeException(
                'OTP tenant is not active.'
            );
        }
    }

    private function issueLockName(
        int $tenantId,
        string $phoneFingerprint
    ): string {
        return 'civic_otp_issue_'
            . $tenantId
            . '_'
            . substr($phoneFingerprint, 0, 32);
    }

    private function acquireLock(string $lockName): void
    {
        $row = $this->db
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [$lockName, self::LOCK_TIMEOUT_SECONDS]
            )
            ->getFirstRow('array');

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire OTP issue lock.'
            );
        }
    }

    private function releaseLock(string $lockName): void
    {
        $this->db->query(
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
