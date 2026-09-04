<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicIdentitySubmissionService
{
    private const LOCK_TIMEOUT_SECONDS = 5;

    private const INITIAL_STATUS =
        IdentityVerificationStateMachine::PENDING;

    private const REQUIRED_DOCUMENTS = [
        VerificationDocumentWriteService::CIN_FRONT,
        VerificationDocumentWriteService::CIN_BACK,
        VerificationDocumentWriteService::PORTRAIT,
    ];

    private TenantContext $tenantContext;

    private BaseConnection $db;

    private IdentityInputNormalizer $normalizer;

    private IdentityCryptoService $crypto;

    private AuditService $audit;

    private PublicDocumentStorageService $storage;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?IdentityInputNormalizer $normalizer = null,
        ?IdentityCryptoService $crypto = null,
        ?AuditService $audit = null,
        ?PublicDocumentStorageService $storage = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();
        $this->normalizer =
            $normalizer ?? new IdentityInputNormalizer();
        $this->crypto =
            $crypto ?? new IdentityCryptoService($tenantContext);
        $this->audit =
            $audit ?? new AuditService($tenantContext, $this->db);
        $this->storage =
            $storage ?? new PublicDocumentStorageService();
    }

    public function submit(
        string $ninu,
        ?string $phone,
        string $consentVersion,
        array $documents,
        ?string $requestId = null,
        string $contactVerificationStatus =
            ContactVerificationStatus::OTP_VERIFIED,
        ?string $departmentCode = null
    ): array {
        $tenantId = $this->tenantContext->id();
        $consentVersion = $this->requiredString(
            $consentVersion,
            80,
            'Consent version'
        );
        $documents = $this->requiredDocuments($documents);
        $contactVerificationStatus = ContactVerificationStatus::normalize(
            $contactVerificationStatus
        );
        $departmentCode = (new HaitiDepartmentCatalog())
            ->normalize($departmentCode);

        $lockName = $this->auditLockName($tenantId);
        $storedPaths = [];

        $this->acquireAuditLock($lockName);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start public identity submission transaction.'
                );
            }

            $tenant = $this->activeTenantForUpdate($tenantId);

            $normalizedNinu =
                $this->normalizer->normalizeNinu($ninu);

            $normalizedPhone =
                $this->normalizer->normalizeHaitiPhone($phone);

            $uuid = $this->uuid();
            $publicReference = $this->availablePublicReference();

            $fingerprint =
                $this->crypto->ninuFingerprint($normalizedNinu);

            $this->assertFingerprintAvailable(
                $tenantId,
                $fingerprint
            );

            $ninuCiphertext = $this->crypto->encryptNinu(
                $normalizedNinu,
                $uuid
            );

            $phoneCiphertext = $normalizedPhone === null
                ? null
                : $this->crypto->encryptPhone(
                    $normalizedPhone,
                    $uuid
                );

            $consentedAt = gmdate('Y-m-d H:i:s');

            $inserted = $this->db
                ->table('citizen_identities')
                ->insert([
                    'uuid' => $uuid,
                    'public_reference' => $publicReference,
                    'tenant_id' => $tenantId,
                    'ninu_ciphertext' => $ninuCiphertext,
                    'ninu_fingerprint' => $fingerprint,
                    'phone_ciphertext' => $phoneCiphertext,
                    'contact_verification_status' =>
                        $contactVerificationStatus,
                    'department_code' => $departmentCode,
                    'verification_status' => self::INITIAL_STATUS,
                    'consent_version' => $consentVersion,
                    'consented_at' => $consentedAt,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Public citizen identity insert failed.'
                );
            }

            $identityId = (int) $this->db->insertID();

            foreach (self::REQUIRED_DOCUMENTS as $documentType) {
                $stored = $this->storage->store(
                    $documents[$documentType],
                    (string) $tenant['uuid'],
                    $uuid,
                    $documentType
                );

                $storedPaths[] =
                    (string) $stored['absolute_path'];

                $this->insertDocument(
                    $tenantId,
                    $identityId,
                    $documentType,
                    $stored
                );
            }

            $context = [
                'phone_present' => $normalizedPhone !== null,
                'contact_verification_status' =>
                    $contactVerificationStatus,
                'department_code' => $departmentCode,
                'consent_version' => $consentVersion,
                'document_types' => self::REQUIRED_DOCUMENTS,
                'document_count' => count(self::REQUIRED_DOCUMENTS),
            ];

            $this->insertIdentityEvent(
                $tenantId,
                $identityId,
                'identity.public_submitted',
                self::INITIAL_STATUS,
                $context
            );

            $this->audit->record(
                'citizen_identity.public_submitted',
                null,
                'public',
                'citizen_identity',
                $identityId,
                $requestId,
                null,
                [
                    'verification_status' => self::INITIAL_STATUS,
                    'phone_present' => $normalizedPhone !== null,
                    'contact_verification_status' =>
                        $contactVerificationStatus,
                    'department_code' => $departmentCode,
                    'consent_version' => $consentVersion,
                    'document_types' => self::REQUIRED_DOCUMENTS,
                ]
            );

            $this->commitOrFail();

            return [
                'id' => $identityId,
                'uuid' => $uuid,
                'public_reference' => $publicReference,
                'verification_status' => self::INITIAL_STATUS,
                'contact_verification_status' =>
                    $contactVerificationStatus,
                'department_code' => $departmentCode,
                'consented_at' => $consentedAt,
            ];
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            foreach ($storedPaths as $storedPath) {
                $this->storage->deleteStoredPath($storedPath);
            }

            throw $exception;
        } finally {
            $this->releaseAuditLock($lockName);
        }
    }

    private function availablePublicReference(): string
    {
        $generator = new PublicReferenceGenerator();

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $reference = $generator->generate();
            $exists = $this->db->table('citizen_identities')
                ->where('public_reference', $reference)
                ->countAllResults();

            if ($exists === 0) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not allocate a public identity reference.');
    }

    private function activeTenantForUpdate(int $tenantId): array
    {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT `id`, `uuid`
FROM `tenants`
WHERE `id` = ?
  AND `status` = 'active'
  AND `deleted_at` IS NULL
LIMIT 1
FOR UPDATE
SQL,
                [$tenantId]
            )
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Public tenant is not active.'
            );
        }

        return $row;
    }

    private function assertFingerprintAvailable(
        int $tenantId,
        string $fingerprint
    ): void {
        $row = $this->db
            ->table('citizen_identities')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('ninu_fingerprint', $fingerprint)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row !== null) {
            throw new InvalidArgumentException(
                'Citizen identity already exists in the current tenant.'
            );
        }
    }

    private function insertDocument(
        int $tenantId,
        int $identityId,
        string $documentType,
        array $stored
    ): void {
        $inserted = $this->db
            ->table('verification_documents')
            ->insert([
                'uuid' => $this->uuid(),
                'tenant_id' => $tenantId,
                'citizen_identity_id' => $identityId,
                'document_type' => $documentType,
                'revision_no' => 1,
                'storage_ref' => (string) $stored['storage_ref'],
                'content_type' => (string) $stored['content_type'],
                'size_bytes' => (int) $stored['size_bytes'],
                'sha256' => (string) $stored['sha256'],
                'status' => 'active',
                'captured_at' => (string) $stored['captured_at'],
            ]);

        if (! $inserted) {
            throw new RuntimeException(
                'Public verification document insert failed.'
            );
        }
    }

    private function insertIdentityEvent(
        int $tenantId,
        int $identityId,
        string $eventType,
        string $toStatus,
        array $context
    ): void {
        $contextJson = json_encode(
            $context,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        $inserted = $this->db
            ->table('identity_verification_events')
            ->insert([
                'uuid' => $this->uuid(),
                'tenant_id' => $tenantId,
                'citizen_identity_id' => $identityId,
                'event_type' => $eventType,
                'from_status' => null,
                'to_status' => $toStatus,
                'actor_user_id' => null,
                'reason_code' => null,
                'context_json' => $contextJson,
                'occurred_at' => gmdate('Y-m-d H:i:s'),
            ]);

        if (! $inserted) {
            throw new RuntimeException(
                'Public identity verification event insert failed.'
            );
        }
    }

    private function requiredDocuments(array $documents): array
    {
        $normalized = [];

        foreach (self::REQUIRED_DOCUMENTS as $documentType) {
            $path = $documents[$documentType] ?? null;

            if (! is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException(
                    'All verification documents are required.'
                );
            }

            $normalized[$documentType] = $path;
        }

        return $normalized;
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

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                $field . ' has an invalid length.'
            );
        }

        return $value;
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Public identity submission transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Public identity submission commit failed.'
            );
        }
    }

    private function rollbackIfNeeded(): void
    {
        $this->db->transRollback();
    }

    private function auditLockName(int $tenantId): string
    {
        return 'civic_audit_tenant_' . $tenantId;
    }

    private function acquireAuditLock(string $lockName): void
    {
        $row = $this->db
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [$lockName, self::LOCK_TIMEOUT_SECONDS]
            )
            ->getFirstRow('array');

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire public identity audit lock.'
            );
        }
    }

    private function releaseAuditLock(string $lockName): void
    {
        $this->db->query(
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f) | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f) | 0x80
        );

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
