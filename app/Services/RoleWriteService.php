<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RoleWriteService
{
    private const AUDIT_LOCK_TIMEOUT = 5;

    private TenantContext $tenantContext;
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();

        $this->authorization = new AuthorizationService(
            $tenantContext,
            $this->db
        );

        $this->audit = new AuditService(
            $tenantContext,
            $this->db
        );
    }

    public function createRole(
        int $actorUserId,
        string $code,
        string $name,
        ?string $description = null
    ): int {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $code = $this->normalizeCode($code);

        $name = $this->normalizeRequiredString(
            $name,
            160,
            'Role name'
        );

        $description = $this->normalizeNullableString(
            $description,
            500,
            'Role description'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'roles.manage'
            );

            if (
                $this->roleByCodeForUpdate(
                    $tenantId,
                    $code
                ) !== null
            ) {
                throw new InvalidArgumentException(
                    'Role code already exists in the current tenant.'
                );
            }

            $inserted = $this->db
                ->table('roles')
                ->insert([
                    'uuid'        => $this->uuid(),
                    'tenant_id'   => $tenantId,
                    'code'        => $code,
                    'name'        => $name,
                    'description' => $description,
                    'is_system'   => 0,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Could not create role.'
                );
            }

            $roleId = (int) $this->db->insertID();

            $this->audit->record(
                event: 'role.created',
                actorUserId: $actorUserId,
                entityType: 'role',
                entityId: $roleId,
                context: [
                    'code' => $code,
                    'name' => $name,
                ]
            );

            $this->commitOrFail();

            return $roleId;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock($lockName);
        }
    }

    public function updateRole(
        int $actorUserId,
        int $roleId,
        string $name,
        ?string $description = null
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $roleId,
            'Role ID'
        );

        $name = $this->normalizeRequiredString(
            $name,
            160,
            'Role name'
        );

        $description = $this->normalizeNullableString(
            $description,
            500,
            'Role description'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'roles.manage'
            );

            $role = $this->roleForTenantForUpdate(
                $tenantId,
                $roleId
            );

            $this->assertMutableRole($role);

            if (
                $name === (string) $role['name']
                && $description === $role['description']
            ) {
                $this->commitOrFail();
                return;
            }

            $updated = $this->db
                ->table('roles')
                ->where('id', $roleId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'name'        => $name,
                    'description' => $description,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Could not update role.'
                );
            }

            $this->audit->record(
                event: 'role.updated',
                actorUserId: $actorUserId,
                entityType: 'role',
                entityId: $roleId,
                context: [
                    'code' =>
                        (string) $role['code'],
                    'old_name' =>
                        (string) $role['name'],
                    'new_name' =>
                        $name,
                    'old_description' =>
                        $role['description'],
                    'new_description' =>
                        $description,
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

    public function setPermissions(
        int $actorUserId,
        int $roleId,
        array $permissionCodes
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $roleId,
            'Role ID'
        );

        $permissionCodes =
            $this->normalizePermissionCodes(
                $permissionCodes
            );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'roles.manage'
            );

            $role = $this->roleForTenantForUpdate(
                $tenantId,
                $roleId
            );

            $this->assertMutableRole($role);

            $permissionRows = [];

            if ($permissionCodes !== []) {
                $permissionRows = $this->db
                    ->table('permissions')
                    ->select('id, code')
                    ->whereIn(
                        'code',
                        $permissionCodes
                    )
                    ->orderBy('code', 'ASC')
                    ->get()
                    ->getResultArray();

                $foundCodes = array_map(
                    static fn (array $row): string =>
                        (string) $row['code'],
                    $permissionRows
                );

                sort(
                    $foundCodes,
                    SORT_STRING
                );

                if (
                    $foundCodes !== $permissionCodes
                ) {
                    throw new InvalidArgumentException(
                        'One or more permission codes do not exist.'
                    );
                }
            }

            $oldCodes =
                $this->permissionCodesForRoleForUpdate(
                    $roleId
                );

            if ($oldCodes === $permissionCodes) {
                $this->commitOrFail();
                return;
            }

            $deleted = $this->db
                ->table('role_permissions')
                ->where('role_id', $roleId)
                ->delete();

            if (! $deleted) {
                throw new RuntimeException(
                    'Could not clear role permissions.'
                );
            }

            foreach (
                $permissionRows as $permission
            ) {
                $inserted = $this->db
                    ->table('role_permissions')
                    ->insert([
                        'role_id' => $roleId,
                        'permission_id' =>
                            (int) $permission['id'],
                    ]);

                if (! $inserted) {
                    throw new RuntimeException(
                        'Could not assign role permission.'
                    );
                }
            }

            $this->audit->record(
                event: 'role.permissions_changed',
                actorUserId: $actorUserId,
                entityType: 'role',
                entityId: $roleId,
                context: [
                    'code' =>
                        (string) $role['code'],
                    'old_permissions' =>
                        $oldCodes,
                    'new_permissions' =>
                        $permissionCodes,
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

    public function assignToUser(
        int $actorUserId,
        int $roleId,
        int $userId
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $roleId,
            'Role ID'
        );

        $this->assertPositiveId(
            $userId,
            'User ID'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'roles.manage'
            );

            $role = $this->roleForTenantForUpdate(
                $tenantId,
                $roleId
            );

            $this->assertActiveTenantMemberForUpdate(
                $tenantId,
                $userId
            );

            if (
                $this->userRoleAssignmentForUpdate(
                    $tenantId,
                    $userId,
                    $roleId
                ) !== null
            ) {
                throw new InvalidArgumentException(
                    'Role is already assigned to this user.'
                );
            }

            $inserted = $this->db
                ->table('user_roles')
                ->insert([
                    'tenant_id' => $tenantId,
                    'user_id'   => $userId,
                    'role_id'   => $roleId,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Could not assign role to user.'
                );
            }

            $this->audit->record(
                event: 'role.user_assigned',
                actorUserId: $actorUserId,
                entityType: 'role',
                entityId: $roleId,
                context: [
                    'role_code' =>
                        (string) $role['code'],
                    'target_user_id' =>
                        $userId,
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

    public function removeFromUser(
        int $actorUserId,
        int $roleId,
        int $userId
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $roleId,
            'Role ID'
        );

        $this->assertPositiveId(
            $userId,
            'User ID'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'roles.manage'
            );

            $role = $this->roleForTenantForUpdate(
                $tenantId,
                $roleId
            );

            if (
                $this->userRoleAssignmentForUpdate(
                    $tenantId,
                    $userId,
                    $roleId
                ) === null
            ) {
                throw new InvalidArgumentException(
                    'Role is not assigned to this user.'
                );
            }

            $deleted = $this->db
                ->table('user_roles')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->delete();

            if (! $deleted) {
                throw new RuntimeException(
                    'Could not remove role from user.'
                );
            }

            $this->audit->record(
                event: 'role.user_removed',
                actorUserId: $actorUserId,
                entityType: 'role',
                entityId: $roleId,
                context: [
                    'role_code' =>
                        (string) $role['code'],
                    'target_user_id' =>
                        $userId,
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

    private function roleByCodeForUpdate(
        int $tenantId,
        string $code
    ): ?array {
        return $this->db
            ->query(
                <<<'SQL'
SELECT *
FROM `roles`
WHERE `tenant_id` = ?
  AND `code` = ?
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $code,
                ]
            )
            ->getFirstRow('array');
    }

    private function roleForTenantForUpdate(
        int $tenantId,
        int $roleId
    ): array {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT *
FROM `roles`
WHERE `tenant_id` = ?
  AND `id` = ?
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $roleId,
                ]
            )
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Role does not belong to the current tenant.'
            );
        }

        return $row;
    }

    private function assertMutableRole(
        array $role
    ): void {
        if ((int) $role['is_system'] === 1) {
            throw new RuntimeException(
                'System roles cannot be modified by this service.'
            );
        }
    }

    private function assertActiveTenantMemberForUpdate(
        int $tenantId,
        int $userId
    ): void {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT
    tu.`id`,
    tu.`user_id`
FROM `tenant_users` tu
INNER JOIN `users` u
    ON u.`id` = tu.`user_id`
WHERE tu.`tenant_id` = ?
  AND tu.`user_id` = ?
  AND tu.`status` = 'active'
  AND u.`status` = 'active'
  AND u.`deleted_at` IS NULL
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $userId,
                ]
            )
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Target user is not an active member '
                . 'of the current tenant.'
            );
        }
    }

    private function userRoleAssignmentForUpdate(
        int $tenantId,
        int $userId,
        int $roleId
    ): ?array {
        return $this->db
            ->query(
                <<<'SQL'
SELECT
    `tenant_id`,
    `user_id`,
    `role_id`
FROM `user_roles`
WHERE `tenant_id` = ?
  AND `user_id` = ?
  AND `role_id` = ?
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $userId,
                    $roleId,
                ]
            )
            ->getFirstRow('array');
    }

    private function permissionCodesForRoleForUpdate(
        int $roleId
    ): array {
        $rows = $this->db
            ->query(
                <<<'SQL'
SELECT p.`code`
FROM `role_permissions` rp
INNER JOIN `permissions` p
    ON p.`id` = rp.`permission_id`
WHERE rp.`role_id` = ?
ORDER BY p.`code` ASC
FOR UPDATE
SQL,
                [$roleId]
            )
            ->getResultArray();

        return array_map(
            static fn (array $row): string =>
                (string) $row['code'],
            $rows
        );
    }

    private function normalizePermissionCodes(
        array $codes
    ): array {
        $normalized = [];

        foreach ($codes as $code) {
            if (! is_string($code)) {
                throw new InvalidArgumentException(
                    'Permission codes must be strings.'
                );
            }

            $code = strtolower(trim($code));

            if (
                $code === ''
                || mb_strlen($code) > 120
                || ! preg_match(
                    '/^[a-z0-9][a-z0-9._-]*$/',
                    $code
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid permission code.'
                );
            }

            $normalized[$code] = true;
        }

        $codes = array_keys($normalized);
        sort($codes, SORT_STRING);

        return $codes;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));

        if (
            $code === ''
            || mb_strlen($code) > 80
            || ! preg_match(
                '/^[a-z0-9][a-z0-9._-]*$/',
                $code
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid role code.'
            );
        }

        return $code;
    }

    private function normalizeRequiredString(
        string $value,
        int $maxLength,
        string $field
    ): string {
        $value = trim($value);

        if (
            $value === ''
            || mb_strlen($value) > $maxLength
        ) {
            throw new InvalidArgumentException(
                $field . ' is invalid.'
            );
        }

        return $value;
    }

    private function normalizeNullableString(
        ?string $value,
        int $maxLength,
        string $field
    ): ?string {
        if ($value === null) {
            return null;
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

    private function requirePermission(
        int $actorUserId,
        string $permission
    ): void {
        if (! $this->authorization->userHasPermission(
            $actorUserId,
            $permission
        )) {
            throw new RuntimeException(
                'Actor is not authorized for this operation.'
            );
        }
    }

    private function assertPositiveId(
        int $id,
        string $field
    ): void {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                $field . ' must be positive.'
            );
        }
    }

    private function beginTransaction(): void
    {
        if (! $this->db->transBegin()) {
            throw new RuntimeException(
                'Could not start role transaction.'
            );
        }
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Role transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Could not commit role transaction.'
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
                [
                    $lockName,
                    self::AUDIT_LOCK_TIMEOUT,
                ]
            )
            ->getFirstRow('array');

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire the audit transaction lock.'
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
