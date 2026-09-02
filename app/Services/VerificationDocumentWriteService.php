<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VerificationDocumentWriteService
{
    public const CIN_FRONT = 'cin_front';

    public const CIN_BACK = 'cin_back';

    public const PORTRAIT = 'portrait';

    private const MANAGE_PERMISSION =
        'identity.manage';

    private const LOCK_TIMEOUT_SECONDS = 5;

    private const DOCUMENT_TYPES = [
        self::CIN_FRONT,
        self::CIN_BACK,
        self::PORTRAIT,
    ];

    private TenantContext $tenantContext;

    private BaseConnection $db;

    private AuthorizationService $authorization;

    private AuditService $audit;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?AuthorizationService $authorization = null,
        ?AuditService $audit = null
    ) {
        $this->tenantContext =
            $tenantContext;

        $this->db =
            $db ?? Database::connect();

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
    }

    public function register(
        int $actorUserId,
        int $identityId,
        string $documentType,
        string $storageRef,
        ?string $contentType = null,
        ?int $sizeBytes = null,
        ?string $sha256 = null,
        ?string $capturedAt = null,
        ?string $requestId = null,
        ?string $ipHash = null
    ): int {
        $tenantId =
            $this->tenantContext->id();

        if ($identityId <= 0) {
            throw new InvalidArgumentException(
                'Citizen identity ID must be positive.'
            );
        }

        $documentType =
            $this->documentType(
                $documentType
            );

        $storageRef =
            $this->requiredString(
                $storageRef,
                512,
                'Storage reference'
            );

        /*
         * Une data URI introduirait directement le binaire
         * du document dans MariaDB, ce qui est interdit.
         */
        if (
            str_starts_with(
                strtolower($storageRef),
                'data:'
            )
        ) {
            throw new InvalidArgumentException(
                'Storage reference must not contain document data.'
            );
        }

        $contentType =
            $this->optionalString(
                $contentType,
                127,
                'Content type'
            );

        if (
            $sizeBytes !== null
            && $sizeBytes <= 0
        ) {
            throw new InvalidArgumentException(
                'Document size must be positive.'
            );
        }

        $sha256 =
            $this->normalizeSha256(
                $sha256
            );

        $capturedAt =
            $this->capturedAt(
                $capturedAt
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
                    'Could not start verification '
                    . 'document transaction.'
                );
            }

            $this->assertAuthorized(
                $actorUserId
            );

            $this->lockIdentity(
                $tenantId,
                $identityId
            );

            $revisionNo =
                $this->nextRevision(
                    $tenantId,
                    $identityId,
                    $documentType
                );

            $uuid =
                $this->uuid();

            $inserted = $this->db
                ->table(
                    'verification_documents'
                )
                ->insert([
                    'uuid' =>
                        $uuid,

                    'tenant_id' =>
                        $tenantId,

                    'citizen_identity_id' =>
                        $identityId,

                    'document_type' =>
                        $documentType,

                    'revision_no' =>
                        $revisionNo,

                    'storage_ref' =>
                        $storageRef,

                    'content_type' =>
                        $contentType,

                    'size_bytes' =>
                        $sizeBytes,

                    'sha256' =>
                        $sha256,

                    'status' =>
                        'active',

                    'captured_at' =>
                        $capturedAt,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Verification document insert failed.'
                );
            }

            $documentId =
                (int) $this->db
                    ->insertID();

            /*
             * Aucun storage_ref, hash de fichier ou
             * donnée d'identité sensible dans l'audit.
             */
            $context = [
                'document_type' =>
                    $documentType,

                'revision_no' =>
                    $revisionNo,

                'content_type' =>
                    $contentType,

                'size_bytes' =>
                    $sizeBytes,

                'sha256_present' =>
                    $sha256 !== null,

                'captured_at_present' =>
                    $capturedAt !== null,
            ];

            $this->insertIdentityEvent(
                $tenantId,
                $identityId,
                $actorUserId,
                'identity.document_registered',
                $context
            );

            $this->audit->record(
                'citizen_identity.document_registered',
                $actorUserId,
                'user',
                'verification_document',
                $documentId,
                $requestId,
                $ipHash,
                $context
            );

            $this->commitOrFail();

            return $documentId;
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

    private function lockIdentity(
        int $tenantId,
        int $identityId
    ): void {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT `id`
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
    }

    private function nextRevision(
        int $tenantId,
        int $identityId,
        string $documentType
    ): int {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT `revision_no`
FROM `verification_documents`
WHERE `tenant_id` = ?
  AND `citizen_identity_id` = ?
  AND `document_type` = ?
ORDER BY `revision_no` DESC
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $identityId,
                    $documentType,
                ]
            )
            ->getFirstRow('array');

        $revisionNo =
            $row === null
                ? 1
                : ((int) $row['revision_no']) + 1;

        if ($revisionNo > 65535) {
            throw new RuntimeException(
                'Verification document revision limit reached.'
            );
        }

        return $revisionNo;
    }

    private function insertIdentityEvent(
        int $tenantId,
        int $identityId,
        int $actorUserId,
        string $eventType,
        array $context
    ): void {
        $contextJson =
            json_encode(
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
                    null,

                'to_status' =>
                    null,

                'actor_user_id' =>
                    $actorUserId,

                'reason_code' =>
                    null,

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

    private function documentType(
        string $documentType
    ): string {
        $documentType =
            trim(
                $documentType
            );

        if (
            ! in_array(
                $documentType,
                self::DOCUMENT_TYPES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Unsupported verification document type.'
            );
        }

        return $documentType;
    }

    private function requiredString(
        mixed $value,
        int $maxLength,
        string $field
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException(
                $field . ' must be a string.'
            );
        }

        $value =
            trim(
                $value
            );

        if (
            $value === ''
            || mb_strlen($value) > $maxLength
        ) {
            throw new InvalidArgumentException(
                $field . ' has an invalid length.'
            );
        }

        return $value;
    }

    private function optionalString(
        ?string $value,
        int $maxLength,
        string $field
    ): ?string {
        if ($value === null) {
            return null;
        }

        return $this->requiredString(
            $value,
            $maxLength,
            $field
        );
    }

    private function normalizeSha256(
        ?string $sha256
    ): ?string {
        if ($sha256 === null) {
            return null;
        }

        $sha256 =
            strtolower(
                trim(
                    $sha256
                )
            );

        if (
            ! preg_match(
                '/^[0-9a-f]{64}$/',
                $sha256
            )
        ) {
            throw new InvalidArgumentException(
                'Document SHA-256 is invalid.'
            );
        }

        return $sha256;
    }

    private function capturedAt(
        ?string $capturedAt
    ): ?string {
        if ($capturedAt === null) {
            return null;
        }

        $capturedAt =
            trim(
                $capturedAt
            );

        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $capturedAt
            );

        if (
            $date === false
            || $date->format(
                'Y-m-d H:i:s'
            ) !== $capturedAt
        ) {
            throw new InvalidArgumentException(
                'Captured at timestamp is invalid.'
            );
        }

        return $capturedAt;
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Verification document transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Verification document transaction '
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
                'Could not acquire verification '
                . 'document audit lock.'
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
