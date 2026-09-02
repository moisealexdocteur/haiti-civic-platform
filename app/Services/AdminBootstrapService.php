<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminBootstrapService
{
    private const LOCK_TIMEOUT_SECONDS = 5;
    private const ROLE_CODE = 'identity_admin';
    private const ROLE_NAME = 'Administrateur des identités';
    private const PERMISSIONS = [
        'identity.view',
        'identity.manage',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function bootstrapFirstAdministrator(
        string $tenantSlug,
        string $email,
        string $displayName,
        string $password
    ): array {
        $tenantSlug = strtolower(trim($tenantSlug));
        $email = strtolower(trim($email));
        $displayName = trim($displayName);

        if ($tenantSlug === '' || mb_strlen($tenantSlug) > 80) {
            throw new InvalidArgumentException('Tenant slug is invalid.');
        }

        if (
            ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($email) > 191
        ) {
            throw new InvalidArgumentException('Administrator email is invalid.');
        }

        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new InvalidArgumentException('Administrator display name is invalid.');
        }

        if (mb_strlen($password) < 14 || mb_strlen($password) > 256) {
            throw new InvalidArgumentException(
                'Administrator password must contain 14 to 256 characters.'
            );
        }

        $tenant = $this->db
            ->table('tenants')
            ->select('id, uuid, slug, name')
            ->where('slug', $tenantSlug)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant does not exist or is inactive.');
        }

        $tenantId = (int) $tenant['id'];
        $lockName = 'civic_audit_tenant_' . $tenantId;

        $this->acquireAuditLock($lockName);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start administrator bootstrap transaction.');
            }

            $lockedTenant = $this->db
                ->query(
                    <<<'SQL'
SELECT `id`, `uuid`, `slug`, `name`
FROM `tenants`
WHERE `id` = ?
  AND `slug` = ?
  AND `status` = 'active'
  AND `deleted_at` IS NULL
LIMIT 1
FOR UPDATE
SQL,
                    [$tenantId, $tenantSlug]
                )
                ->getFirstRow('array');

            if ($lockedTenant === null) {
                throw new RuntimeException('Tenant became unavailable during bootstrap.');
            }

            $membershipCount = $this->db
                ->table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->countAllResults();

            if ($membershipCount !== 0) {
                throw new RuntimeException(
                    'First-administrator bootstrap is allowed only when the tenant has no users.'
                );
            }

            $existingUser = $this->db
                ->table('users')
                ->select('id')
                ->where('email', $email)
                ->limit(1)
                ->get()
                ->getFirstRow('array');

            if ($existingUser !== null) {
                throw new RuntimeException(
                    'A global user already exists with this email; manual assignment is required.'
                );
            }

            $existingRole = $this->db
                ->table('roles')
                ->select('id')
                ->where('tenant_id', $tenantId)
                ->where('code', self::ROLE_CODE)
                ->limit(1)
                ->get()
                ->getFirstRow('array');

            if ($existingRole !== null) {
                throw new RuntimeException(
                    'Bootstrap role already exists; manual reconciliation is required.'
                );
            }

            $permissionRows = $this->db
                ->table('permissions')
                ->select('id, code')
                ->whereIn('code', self::PERMISSIONS)
                ->orderBy('code', 'ASC')
                ->get()
                ->getResultArray();

            $permissionCodes = array_column($permissionRows, 'code');
            sort($permissionCodes, SORT_STRING);
            $expectedCodes = self::PERMISSIONS;
            sort($expectedCodes, SORT_STRING);

            if ($permissionCodes !== $expectedCodes) {
                throw new RuntimeException('Identity permissions are incomplete.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            if (! is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Administrator password hashing failed.');
            }

            $userUuid = $this->uuid();

            if (! $this->db->table('users')->insert([
                'uuid' => $userUuid,
                'email' => $email,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'status' => 'active',
                'locale' => 'fr',
            ])) {
                throw new RuntimeException('Administrator user insert failed.');
            }

            $userId = (int) $this->db->insertID();

            if (! $this->db->table('tenant_users')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 'active',
                'is_owner' => 1,
            ])) {
                throw new RuntimeException('Administrator tenant membership insert failed.');
            }

            if (! $this->db->table('roles')->insert([
                'uuid' => $this->uuid(),
                'tenant_id' => $tenantId,
                'code' => self::ROLE_CODE,
                'name' => self::ROLE_NAME,
                'description' => 'Accès de vérification des identités pour le premier administrateur du tenant.',
                'is_system' => 0,
            ])) {
                throw new RuntimeException('Administrator role insert failed.');
            }

            $roleId = (int) $this->db->insertID();

            foreach ($permissionRows as $permissionRow) {
                if (! $this->db->table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => (int) $permissionRow['id'],
                ])) {
                    throw new RuntimeException('Administrator role permission insert failed.');
                }
            }

            if (! $this->db->table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
            ])) {
                throw new RuntimeException('Administrator role assignment insert failed.');
            }

            $audit = new AuditService(
                (new TenantContext())->set($tenantId),
                $this->db
            );

            $audit->record(
                event: 'admin.bootstrap_created',
                actorUserId: null,
                actorType: 'system',
                entityType: 'user',
                entityId: $userId,
                context: [
                    'role_code' => self::ROLE_CODE,
                    'permissions' => self::PERMISSIONS,
                    'is_owner' => true,
                ]
            );

            if (! $this->db->transStatus()) {
                throw new RuntimeException('Administrator bootstrap transaction failed.');
            }

            if (! $this->db->transCommit()) {
                throw new RuntimeException('Administrator bootstrap commit failed.');
            }

            return [
                'tenant_id' => $tenantId,
                'tenant_slug' => (string) $lockedTenant['slug'],
                'user_id' => $userId,
                'user_uuid' => $userUuid,
                'role_id' => $roleId,
                'role_code' => self::ROLE_CODE,
            ];
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        } finally {
            $this->releaseAuditLock($lockName);
        }
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
            throw new RuntimeException('Could not acquire administrator bootstrap lock.');
        }
    }

    private function releaseAuditLock(string $lockName): void
    {
        $this->db->query('SELECT RELEASE_LOCK(?)', [$lockName]);
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
