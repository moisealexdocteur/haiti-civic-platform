<?php

namespace App\Services\Otp;

use App\Services\AuditService;
use App\Services\TenantContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OtpChallengeDeliveryService
{
    private BaseConnection $db;
    private AuditService $audit;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?AuditService $audit = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->audit = $audit
            ?? new AuditService($tenantContext, $this->db);
    }

    public function markDelivered(
        string $challengeUuid,
        OtpDeliveryResult $result
    ): void {
        if (! $result->accepted) {
            throw new InvalidArgumentException(
                'Only an accepted OTP delivery may be recorded.'
            );
        }

        $challengeUuid = $this->normalizeUuid($challengeUuid);
        $providerRef = $this->normalizeProviderRef(
            $result->providerMessageId
        );
        $tenantId = $this->tenantContext->id();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->db->transBegin();

            $row = $this->lockedChallenge($tenantId, $challengeUuid);

            if ($row === null) {
                throw new RuntimeException(
                    'OTP challenge is unavailable for delivery recording.'
                );
            }

            if (
                $row['consumed_at'] !== null
                || $row['invalidated_at'] !== null
                || $row['locked_at'] !== null
                || (string) $row['expires_at'] <= $now
            ) {
                throw new RuntimeException(
                    'OTP challenge is no longer deliverable.'
                );
            }

            $this->db
                ->table('otp_challenges')
                ->where('id', (int) $row['id'])
                ->update([
                    'delivered_channel' => $result->channel->value,
                    'provider_message_ref' => $providerRef,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                'otp.challenge_delivered',
                null,
                'public',
                'otp_challenge',
                $challengeUuid,
                null,
                null,
                [
                    'delivered_channel' => $result->channel->value,
                    'provider_ref_present' => $providerRef !== null,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function invalidateUndelivered(
        string $challengeUuid,
        string $failureCode
    ): bool {
        $challengeUuid = $this->normalizeUuid($challengeUuid);
        $failureCode = $this->normalizeFailureCode($failureCode);
        $tenantId = $this->tenantContext->id();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->db->transBegin();

            $row = $this->lockedChallenge($tenantId, $challengeUuid);

            if ($row === null) {
                $this->db->transCommit();
                return false;
            }

            if (
                $row['consumed_at'] !== null
                || $row['invalidated_at'] !== null
            ) {
                $this->db->transCommit();
                return false;
            }

            $this->db
                ->table('otp_challenges')
                ->where('id', (int) $row['id'])
                ->update([
                    'invalidated_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->audit->record(
                'otp.challenge_delivery_failed',
                null,
                'public',
                'otp_challenge',
                $challengeUuid,
                null,
                null,
                [
                    'requested_channel' =>
                        (string) $row['requested_channel'],
                    'failure_code' => $failureCode,
                ]
            );

            $this->commitOrFail();
            return true;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function lockedChallenge(
        int $tenantId,
        string $challengeUuid
    ): ?array {
        return $this->db
            ->query(
                'SELECT * FROM `otp_challenges` '
                . 'WHERE `tenant_id` = ? AND `uuid` = ? '
                . 'LIMIT 1 FOR UPDATE',
                [$tenantId, $challengeUuid]
            )
            ->getFirstRow('array');
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'OTP delivery metadata transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'OTP delivery metadata commit failed.'
            );
        }
    }

    private function normalizeProviderRef(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 191) {
            throw new InvalidArgumentException(
                'OTP provider message reference is too long.'
            );
        }

        return $value;
    }

    private function normalizeFailureCode(string $value): string
    {
        $value = strtolower(trim($value));

        if (
            preg_match('/^[a-z0-9._:-]{1,80}$/D', $value)
            !== 1
        ) {
            return 'delivery_failed';
        }

        return $value;
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
}
