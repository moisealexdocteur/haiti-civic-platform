<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class AuthorizationService
{
    private TenantContext $tenantContext;
    private BaseConnection $db;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();
    }

    public function userHasPermission(
        int $userId,
        string $permissionCode
    ): bool {
        if ($userId <= 0 || $permissionCode === '') {
            return false;
        }

        $tenantId = $this->tenantContext->id();

        $row = $this->db
            ->table('user_roles ur')
            ->select('p.id')
            ->join(
                'tenant_users tu',
                'tu.tenant_id = ur.tenant_id'
                . ' AND tu.user_id = ur.user_id'
            )
            ->join(
                'users u',
                'u.id = ur.user_id'
            )
            ->join(
                'roles r',
                'r.id = ur.role_id'
                . ' AND r.tenant_id = ur.tenant_id'
            )
            ->join(
                'role_permissions rp',
                'rp.role_id = r.id'
            )
            ->join(
                'permissions p',
                'p.id = rp.permission_id'
            )
            ->where('ur.tenant_id', $tenantId)
            ->where('ur.user_id', $userId)
            ->where('tu.status', 'active')
            ->where('u.status', 'active')
            ->where('p.code', $permissionCode)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        return $row !== null;
    }

    public function permissionsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $tenantId = $this->tenantContext->id();

        $rows = $this->db
            ->table('user_roles ur')
            ->distinct()
            ->select('p.code')
            ->join(
                'tenant_users tu',
                'tu.tenant_id = ur.tenant_id'
                . ' AND tu.user_id = ur.user_id'
            )
            ->join(
                'users u',
                'u.id = ur.user_id'
            )
            ->join(
                'roles r',
                'r.id = ur.role_id'
                . ' AND r.tenant_id = ur.tenant_id'
            )
            ->join(
                'role_permissions rp',
                'rp.role_id = r.id'
            )
            ->join(
                'permissions p',
                'p.id = rp.permission_id'
            )
            ->where('ur.tenant_id', $tenantId)
            ->where('ur.user_id', $userId)
            ->where('tu.status', 'active')
            ->where('u.status', 'active')
            ->orderBy('p.code', 'ASC')
            ->get()
            ->getResultArray();

        return array_column($rows, 'code');
    }
}
