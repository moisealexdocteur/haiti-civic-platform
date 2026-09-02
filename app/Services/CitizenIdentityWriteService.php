<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CitizenIdentityWriteService
{
    private const MANAGE_PERMISSION =
        'identity.manage';

    private const LOCK_TIMEOUT_SECONDS = 5;

    private const INITIAL_STATUS =
        IdentityVerificationStateMachine::PENDING;

    private TenantContext $tenantContext;

    private BaseConnection $db;

    private IdentityInputNormalizer $normalizer;

    private IdentityCryptoService $crypto;

    private AuthorizationService $authorization;

    private AuditService $audit;

    private IdentityVerificationStateMachine $stateMachine;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?IdentityInputNormalizer $normalizer = null,
        ?IdentityCryptoService $crypto = null,
        ?AuthorizationService $authorization = null,
        ?AuditService $audit = null,
        ?IdentityVerificationStateMachine $stateMachine = null
    ) {
        $this->tenantContext =
            $tenantContext;

        $this->db =
            $db ?? Database::connect();

        $this->normalizer =
            $normalizer
            ?? new IdentityInputNormalizer();

        $this->crypto =
            $crypto
            ?? new IdentityCryptoService(
                $tenantContext
            );

        $this->authorization =
            $authorization
            ?? new AuthorizationService(
                $tenantContext,
                $this->db
            );

        $this->audit =
            $audit
            ?? new AuditService(
                $tenantContext,
                $this->db
            );

        $this->stateMachine =
            $stateMachine
            ?? new IdentityVerificationStateMachine();
    }

    public function create(
        int $actorUserId,
        string $ninu,
        ?string $phone,
        string $consentVersion,
        ?string $requestId = null,
        ?string $ipHash = null
    ): int {
        $tenantId =
            $this->tenantContext->id();

        $consentVersion =
            $this->requiredString(
                $consentVersion,
                80,
                'Consent version'
            );

        $lockName =
            $this->auditLockName(
                $tenantId
            );

        $this->acquireAuditLock(
            $lockName
        );

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start citizen identity transaction.'
                );
            }

            $this->assertAuthorized(
                $actorUserId
            );

            /*
             * La normalisation est effectuée avant
             * toute empreinte ou tout chiffrement.
             */
            $normalizedNinu =
                $this->normalizer
                    ->normalizeNinu(
                        $ninu
                    );

            $normalizedPhone =
                $this->normalizer
                    ->normalizeHaitiPhone(
                        $phone
                    );

            $uuid =
                $this->uuid();

            $fingerprint =
                $this->crypto
                    ->ninuFingerprint(
                        $normalizedNinu
                    );

            /*
             * Le verrou tenant sérialise les écritures
             * auditées de ce tenant.
             *
             * La contrainte UNIQUE de la base reste la
             * seconde barrière contre les doublons.
             */
            $this->assertFingerprintAvailable(
                $tenantId,
                $fingerprint
            );

            $ninuCiphertext =
                $this->crypto
                    ->encryptNinu(
                        $normalizedNinu,
                        $uuid
                    );

            $phoneCiphertext =
                $normalizedPhone === null
                    ? null
                    : $this->crypto
                        ->encryptPhone(
                            $normalizedPhone,
                            $uuid
                        );

            $consentedAt =
                gmdate(
                    'Y-m-d H:i:s'
                );

            $inserted = $this->db
                ->table(
                    'citizen_identities'
                )
                ->insert([
                    'uuid' =>
                        $uuid,

                    'tenant_id' =>
                        $tenantId,

                    'ninu_ciphertext' =>
                        $ninuCiphertext,

                    'ninu_fingerprint' =>
                        $fingerprint,

                    'phone_ciphertext' =>
                        $phoneCiphertext,

                    'verification_status' =>
                        self::INITIAL_STATUS,

                    'consent_version' =>
                        $consentVersion,

                    'consented_at' =>
                        $consentedAt,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Citizen identity insert failed.'
                );
            }

            $identityId =
                (int) $this->db
                    ->insertID();

            $eventContext = [
                'phone_present' =>
                    $normalizedPhone !== null,

                'consent_version' =>
                    $consentVersion,
            ];

            $this->insertIdentityEvent(
                $tenantId,
                $identityId,
                $actorUserId,
                'identity.created',
                null,
                self::INITIAL_STATUS,
                $eventContext
            );

            /*
             * Aucun NINU, téléphone, ciphertext ou
             * fingerprint dans le journal d'audit.
             */
            $this->audit->record(
                'citizen_identity.created',
                $actorUserId,
                'user',
                'citizen_identity',
                $identityId,
                $requestId,
                $ipHash,
                [
                    'verification_status' =>
                        self::INITIAL_STATUS,

                    'phone_present' =>
                        $normalizedPhone !== null,

                    'consent_version' =>
                        $consentVersion,
                ]
            );

            $this->commitOrFail();

            return $identityId;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    public function updatePhone(
        int $actorUserId,
        int $identityId,
        ?string $phone,
        ?string $requestId = null,
        ?string $ipHash = null
    ): void {
        $tenantId =
            $this->tenantContext->id();

        if ($identityId <= 0) {
            throw new InvalidArgumentException(
                'Citizen identity ID must be positive.'
            );
        }

        $lockName =
            $this->auditLockName(
                $tenantId
            );

        $this->acquireAuditLock(
            $lockName
        );

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start citizen identity transaction.'
                );
            }

            $this->assertAuthorized(
                $actorUserId
            );

            $identity =
                $this->identityForUpdate(
                    $tenantId,
                    $identityId
                );

            $normalizedPhone =
                $this->normalizer
                    ->normalizeHaitiPhone(
                        $phone
                    );

            $phoneCiphertext =
                $normalizedPhone === null
                    ? null
                    : $this->crypto
                        ->encryptPhone(
                            $normalizedPhone,
                            $identity['uuid']
                        );

            $updated = $this->db
                ->table(
                    'citizen_identities'
                )
                ->where(
                    'tenant_id',
                    $tenantId
                )
                ->where(
                    'id',
                    $identityId
                )
                ->update([
                    'phone_ciphertext' =>
                        $phoneCiphertext,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Citizen identity phone update failed.'
                );
            }

            $oldPhonePresent =
                $identity[
                    'phone_ciphertext'
                ] !== null;

            $newPhonePresent =
                $normalizedPhone !== null;

            $eventContext = [
                'old_phone_present' =>
                    $oldPhonePresent,

                'new_phone_present' =>
                    $newPhonePresent,
            ];

            $this->insertIdentityEvent(
                $tenantId,
                $identityId,
                $actorUserId,
                'identity.phone_changed',
                null,
                null,
                $eventContext
            );

            $this->audit->record(
                'citizen_identity.phone_changed',
                $actorUserId,
                'user',
                'citizen_identity',
                $identityId,
                $requestId,
                $ipHash,
                [
                    'changed_fields' =>
                        ['phone'],

                    'phone_present' =>
                        $newPhonePresent,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }


    public function transitionVerificationStatus(
        int $actorUserId,
        int $identityId,
        string $toStatus,
        ?string $reasonCode = null,
        ?string $requestId = null,
        ?string $ipHash = null
    ): void {
        $tenantId =
            $this->tenantContext->id();

        if ($identityId <= 0) {
            throw new InvalidArgumentException(
                'Citizen identity ID must be positive.'
            );
        }

        $lockName =
            $this->auditLockName(
                $tenantId
            );

        $this->acquireAuditLock(
            $lockName
        );

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start citizen identity transaction.'
                );
            }

            $this->assertAuthorized(
                $actorUserId
            );

            $identity =
                $this->identityForUpdate(
                    $tenantId,
                    $identityId
                );

            $fromStatus =
                (string) $identity[
                    'verification_status'
                ];

            $this->stateMachine
                ->assertStatus(
                    $fromStatus
                );

            $this->stateMachine
                ->assertTransition(
                    $fromStatus,
                    $toStatus
                );

            if ($reasonCode !== null) {
                $reasonCode =
                    $this->requiredString(
                        $reasonCode,
                        80,
                        'Reason code'
                    );
            }

            if (
                $this->stateMachine
                    ->requiresReason(
                        $fromStatus,
                        $toStatus
                    )
                && $reasonCode === null
            ) {
                throw new InvalidArgumentException(
                    'Reason code is required for this '
                    . 'identity verification transition.'
                );
            }

            $verifiedAt =
                $toStatus
                === IdentityVerificationStateMachine::VERIFIED
                    ? gmdate(
                        'Y-m-d H:i:s'
                    )
                    : null;

            $updated = $this->db
                ->table(
                    'citizen_identities'
                )
                ->where(
                    'tenant_id',
                    $tenantId
                )
                ->where(
                    'id',
                    $identityId
                )
                ->update([
                    'verification_status' =>
                        $toStatus,

                    'verified_at' =>
                        $verifiedAt,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Citizen identity verification '
                    . 'status update failed.'
                );
            }

            $eventContext = [
                'reason_present' =>
                    $reasonCode !== null,
            ];

            $this->insertIdentityEvent(
                $tenantId,
                $identityId,
                $actorUserId,
                'identity.verification_status_changed',
                $fromStatus,
                $toStatus,
                $eventContext,
                $reasonCode
            );

            $this->audit->record(
                'citizen_identity.verification_status_changed',
                $actorUserId,
                'user',
                'citizen_identity',
                $identityId,
                $requestId,
                $ipHash,
                [
                    'from_status' =>
                        $fromStatus,

                    'to_status' =>
                        $toStatus,

                    'reason_present' =>
                        $reasonCode !== null,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    private function assertAuthorized(
        int $actorUserId
    ): void {
        if (
            ! $this->authorization
                ->userHasPermission(
                    $actorUserId,
                    self::MANAGE_PERMISSION
                )
        ) {
            throw new RuntimeException(
                'Permission denied: '
                . self::MANAGE_PERMISSION
            );
        }
    }

    private function assertFingerprintAvailable(
        int $tenantId,
        string $fingerprint
    ): void {
        $row = $this->db
            ->table(
                'citizen_identities'
            )
            ->select('id')
            ->where(
                'tenant_id',
                $tenantId
            )
            ->where(
                'ninu_fingerprint',
                $fingerprint
            )
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row !== null) {
            throw new InvalidArgumentException(
                'Citizen identity already exists '
                . 'in the current tenant.'
            );
        }
    }

    private function identityForUpdate(
        int $tenantId,
        int $identityId
    ): array {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT
    `id`,
    `uuid`,
    `phone_ciphertext`,
    `verification_status`,
    `verified_at`
FROM `citizen_identities`
WHERE `tenant_id` = ?
  AND `id` = ?
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $identityId,
                ]
            )
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Citizen identity does not exist '
                . 'in the current tenant.'
            );
        }

        return $row;
    }

    private function insertIdentityEvent(
        int $tenantId,
        int $identityId,
        int $actorUserId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        array $context,
        ?string $reasonCode = null
    ): void {
        $contextJson = json_encode(
            $context,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        $inserted = $this->db
            ->table(
                'identity_verification_events'
            )
            ->insert([
                'uuid' =>
                    $this->uuid(),

                'tenant_id' =>
                    $tenantId,

                'citizen_identity_id' =>
                    $identityId,

                'event_type' =>
                    $eventType,

                'from_status' =>
                    $fromStatus,

                'to_status' =>
                    $toStatus,

                'actor_user_id' =>
                    $actorUserId,

                'reason_code' =>
                    $reasonCode,

                'context_json' =>
                    $contextJson,

                'occurred_at' =>
                    gmdate(
                        'Y-m-d H:i:s'
                    ),
            ]);

        if (! $inserted) {
            throw new RuntimeException(
                'Identity verification event insert failed.'
            );
        }
    }

    private function requiredString(
        mixed $value,
        int $maxLength,
        string $field
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                $field
                . ' must be a string.'
            );
        }

        $value = trim(
            $value
        );

        if (
            $value === ''
            || mb_strlen($value)
                > $maxLength
        ) {
            throw new InvalidArgumentException(
                $field
                . ' has an invalid length.'
            );
        }

        return $value;
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Citizen identity transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Citizen identity transaction '
                . 'commit failed.'
            );
        }
    }

    private function rollbackIfNeeded(): void
    {
        $this->db->transRollback();
    }

    private function auditLockName(
        int $tenantId
    ): string {
        return 'civic_audit_tenant_'
            . $tenantId;
    }

    private function acquireAuditLock(
        string $lockName
    ): void {
        $row = $this->db
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [
                    $lockName,
                    self::LOCK_TIMEOUT_SECONDS,
                ]
            )
            ->getFirstRow('array');

        if (
            (int) (
                $row['acquired']
                ?? 0
            ) !== 1
        ) {
            throw new RuntimeException(
                'Could not acquire citizen identity audit lock.'
            );
        }
    }

    private function releaseAuditLock(
        string $lockName
    ): void {
        $this->db->query(
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
    }

    private function uuid(): string
    {
        $bytes =
            random_bytes(16);

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f)
            | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f)
            | 0x80
        );

        $hex =
            bin2hex(
                $bytes
            );

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
