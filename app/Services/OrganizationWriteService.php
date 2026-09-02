<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class OrganizationWriteService
{
    private const MANAGE_PERMISSION = 'organizations.manage';
    private const LOCK_TIMEOUT_SECONDS = 5;

    private TenantContext $tenantContext;
    private AuthorizationService $authorization;
    private AuditService $audit;
    private BaseConnection $db;

    public function __construct(
        TenantContext $tenantContext,
        ?AuthorizationService $authorization = null,
        ?AuditService $audit = null,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();

        $this->authorization = $authorization
            ?? new AuthorizationService(
                $tenantContext,
                $this->db
            );

        $this->audit = $audit
            ?? new AuditService(
                $tenantContext,
                $this->db
            );
    }

    public function create(
        int $actorUserId,
        string $name,
        ?string $code = null,
        string $type = 'organization',
        ?string $legalName = null,
        string $status = 'active',
        ?string $requestId = null,
        ?string $ipHash = null
    ): int {
        $tenantId = $this->tenantContext->id();

        $name = $this->requiredString(
            $name,
            190,
            'Organization name'
        );

        $code = $this->nullableString(
            $code,
            80,
            'Organization code'
        );

        $type = $this->requiredString(
            $type,
            50,
            'Organization type'
        );

        $legalName = $this->nullableString(
            $legalName,
            190,
            'Organization legal name'
        );

        $status = $this->requiredString(
            $status,
            20,
            'Organization status'
        );

        $row = [
            'uuid'       => $this->uuid(),
            'tenant_id'  => $tenantId,
            'type'       => $type,
            'code'       => $code,
            'name'       => $name,
            'legal_name' => $legalName,
            'status'     => $status,
        ];

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start organization transaction.'
                );
            }

            $this->assertAuthorized($actorUserId);

            $inserted = $this->db
                ->table('organizations')
                ->insert($row);

            if (! $inserted) {
                throw new RuntimeException(
                    'Organization insert failed.'
                );
            }

            $organizationId =
                (int) $this->db->insertID();

            $this->audit->record(
                'organization.created',
                $actorUserId,
                'user',
                'organization',
                $organizationId,
                $requestId,
                $ipHash,
                [
                    'fields' => [
                        'type',
                        'code',
                        'name',
                        'legal_name',
                        'status',
                    ],
                ]
            );

            $this->commitOrFail();

            return $organizationId;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            throw $exception;
        } finally {
            $this->releaseAuditLock($lockName);
        }
    }

    public function update(
        int $actorUserId,
        int $organizationId,
        array $changes,
        ?string $requestId = null,
        ?string $ipHash = null
    ): void {
        $tenantId = $this->tenantContext->id();

        if ($organizationId <= 0) {
            throw new InvalidArgumentException(
                'Organization ID must be positive.'
            );
        }

        $data = $this->normalizeChanges($changes);

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start organization transaction.'
                );
            }

            $this->assertAuthorized($actorUserId);

            $this->assertOrganizationInTenant(
                $tenantId,
                $organizationId
            );

            $updated = $this->db
                ->table('organizations')
                ->where('tenant_id', $tenantId)
                ->where('id', $organizationId)
                ->where('deleted_at', null)
                ->update($data);

            if (! $updated) {
                throw new RuntimeException(
                    'Organization update failed.'
                );
            }

            $this->audit->record(
                'organization.updated',
                $actorUserId,
                'user',
                'organization',
                $organizationId,
                $requestId,
                $ipHash,
                [
                    'changed_fields' =>
                        array_keys($data),
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            throw $exception;
        } finally {
            $this->releaseAuditLock($lockName);
        }
    }

    public function archive(
        int $actorUserId,
        int $organizationId,
        ?string $requestId = null,
        ?string $ipHash = null
    ): void {
        $tenantId = $this->tenantContext->id();

        if ($organizationId <= 0) {
            throw new InvalidArgumentException(
                'Organization ID must be positive.'
            );
        }

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException(
                    'Could not start organization transaction.'
                );
            }

            $this->assertAuthorized($actorUserId);

            $this->assertOrganizationInTenant(
                $tenantId,
                $organizationId
            );

            $archived = $this->db
                ->table('organizations')
                ->where('tenant_id', $tenantId)
                ->where('id', $organizationId)
                ->where('deleted_at', null)
                ->update([
                    'status'     => 'inactive',
                    'deleted_at' => gmdate(
                        'Y-m-d H:i:s'
                    ),
                ]);

            if (! $archived) {
                throw new RuntimeException(
                    'Organization archive failed.'
                );
            }

            $this->audit->record(
                'organization.archived',
                $actorUserId,
                'user',
                'organization',
                $organizationId,
                $requestId,
                $ipHash
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();

            throw $exception;
        } finally {
            $this->releaseAuditLock($lockName);
        }
    }

    private function normalizeChanges(array $changes): array
    {
        $allowed = [
            'type',
            'code',
            'name',
            'legal_name',
            'status',
        ];

        foreach (array_keys($changes) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Unsupported organization field: '
                    . $field
                );
            }
        }

        if ($changes === []) {
            throw new InvalidArgumentException(
                'At least one organization field '
                . 'must be changed.'
            );
        }

        $data = [];

        if (array_key_exists('type', $changes)) {
            $data['type'] = $this->requiredString(
                $changes['type'],
                50,
                'Organization type'
            );
        }

        if (array_key_exists('code', $changes)) {
            $data['code'] = $this->nullableString(
                $changes['code'],
                80,
                'Organization code'
            );
        }

        if (array_key_exists('name', $changes)) {
            $data['name'] = $this->requiredString(
                $changes['name'],
                190,
                'Organization name'
            );
        }

        if (array_key_exists('legal_name', $changes)) {
            $data['legal_name'] =
                $this->nullableString(
                    $changes['legal_name'],
                    190,
                    'Organization legal name'
                );
        }

        if (array_key_exists('status', $changes)) {
            $data['status'] = $this->requiredString(
                $changes['status'],
                20,
                'Organization status'
            );
        }

        return $data;
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

    private function assertOrganizationInTenant(
        int $tenantId,
        int $organizationId
    ): void {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT `id`
FROM `organizations`
WHERE `tenant_id` = ?
  AND `id` = ?
  AND `deleted_at` IS NULL
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $organizationId,
                ]
            )
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Organization does not exist '
                . 'in the current tenant.'
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
                $field . ' must be a string.'
            );
        }

        $value = trim($value);

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

    private function nullableString(
        mixed $value,
        int $maxLength,
        string $field
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                $field . ' must be a string or null.'
            );
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                $field . ' exceeds its maximum length.'
            );
        }

        return $value;
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Organization transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Organization transaction commit failed.'
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

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire organization audit lock.'
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
