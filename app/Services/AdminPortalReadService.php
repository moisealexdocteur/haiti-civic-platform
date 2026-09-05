<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class AdminPortalReadService
{
    private BaseConnection $db;
    private AuthorizationService $authorization;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->authorization = new AuthorizationService($tenantContext, $this->db);
    }

    public function permissions(int $actorUserId): array
    {
        return $this->authorization->permissionsForUser($actorUserId);
    }

    public function dashboard(int $actorUserId): array
    {
        $permissions = $this->permissions($actorUserId);
        $tenantId = $this->tenantContext->id();
        $identityCounts = ['pending' => 0, 'verified' => 0, 'rejected' => 0];

        if (in_array('identity.view', $permissions, true)) {
            $rows = $this->db->table('citizen_identities')
                ->select('verification_status, COUNT(*) AS total')
                ->where('tenant_id', $tenantId)
                ->groupBy('verification_status')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $status = (string) $row['verification_status'];

                if (array_key_exists($status, $identityCounts)) {
                    $identityCounts[$status] = (int) $row['total'];
                }
            }
        }

        $members = null;

        if (in_array('users.view', $permissions, true)) {
            $members = $this->db->table('tenant_users tu')
                ->join('users u', 'u.id = tu.user_id')
                ->where('tu.tenant_id', $tenantId)
                ->where('tu.status', 'active')
                ->where('u.status', 'active')
                ->where('u.deleted_at', null)
                ->countAllResults();
        }

        $communications = null;

        if (in_array('settings.view', $permissions, true)) {
            $row = $this->db->table('tenant_communication_settings')
                ->select(
                    'whatsapp_enabled, whatsapp_validation_status, '
                    . 'sms_enabled, sms_validation_status, '
                    . 'email_enabled, email_validation_status'
                )
                ->where('tenant_id', $tenantId)
                ->limit(1)
                ->get()
                ->getFirstRow('array');
            $communications = [
                'whatsapp' => (int) ($row['whatsapp_enabled'] ?? 0) === 1
                    && (string) ($row['whatsapp_validation_status'] ?? '') === 'valid',
                'sms' => (int) ($row['sms_enabled'] ?? 0) === 1
                    && (string) ($row['sms_validation_status'] ?? '') === 'valid',
                'email' => (int) ($row['email_enabled'] ?? 0) === 1
                    && (string) ($row['email_validation_status'] ?? '') === 'valid',
            ];
        }

        return [
            'permissions' => $permissions,
            'identities' => $identityCounts,
            'members' => $members,
            'communications' => $communications,
        ];
    }

    public function users(int $actorUserId): array
    {
        $this->require($actorUserId, 'users.view');

        return $this->db->table('tenant_users tu')
            ->select(
                'u.id, u.uuid, u.email, u.display_name, u.locale, u.last_login_at, '
                . 'tu.status, tu.is_owner, tu.field_mode_enabled, tu.field_department_code, '
                . 'tu.preferred_notification_channel, '
                . '(tu.notification_phone_ciphertext IS NOT NULL) AS notification_phone_set, '
                . 'GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles'
            )
            ->join('users u', 'u.id = tu.user_id')
            ->join('user_roles ur', 'ur.tenant_id = tu.tenant_id AND ur.user_id = tu.user_id', 'left')
            ->join('roles r', 'r.id = ur.role_id AND r.tenant_id = tu.tenant_id', 'left')
            ->where('tu.tenant_id', $this->tenantContext->id())
            ->where('u.deleted_at', null)
            ->groupBy('u.id, u.uuid, u.email, u.display_name, u.locale, u.last_login_at, '
                . 'tu.status, tu.is_owner, tu.field_mode_enabled, tu.field_department_code, '
                . 'tu.preferred_notification_channel, tu.notification_phone_ciphertext')
            ->orderBy('tu.is_owner', 'DESC')
            ->orderBy('u.display_name', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function roles(int $actorUserId): array
    {
        $this->requireAny($actorUserId, ['users.view', 'roles.view']);

        return $this->db->table('roles')
            ->select('id, uuid, code, name, description, is_system')
            ->where('tenant_id', $this->tenantContext->id())
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function roleMatrix(int $actorUserId): array
    {
        $this->require($actorUserId, 'roles.view');
        $roles = $this->roles($actorUserId);
        $permissions = $this->db->table('permissions')
            ->select('id, code, name, description, domain')
            ->whereIn('code', [
                'audit.view',
                'identity.manage',
                'identity.view',
                'notifications.manage',
                'notifications.view',
                'roles.manage',
                'roles.view',
                'settings.manage',
                'settings.view',
                'users.manage',
                'users.view',
            ])
            ->orderBy('domain', 'ASC')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResultArray();

        $assignedRows = $this->db->table('role_permissions rp')
            ->select('rp.role_id, p.code')
            ->join('roles r', 'r.id = rp.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('r.tenant_id', $this->tenantContext->id())
            ->orderBy('p.code', 'ASC')
            ->get()
            ->getResultArray();
        $assigned = [];

        foreach ($assignedRows as $row) {
            $assigned[(int) $row['role_id']][] = (string) $row['code'];
        }

        foreach ($roles as &$role) {
            $role['permission_codes'] = $assigned[(int) $role['id']] ?? [];
            $role['mutable'] = (int) $role['is_system'] !== 1
                && (string) $role['code'] !== 'identity_admin';
        }
        unset($role);

        return ['roles' => $roles, 'permissions' => $permissions];
    }

    public function audit(int $actorUserId, int $limit = 100): array
    {
        $this->require($actorUserId, 'audit.view');
        $limit = max(1, min(200, $limit));

        return $this->db->table('audit_logs a')
            ->select('a.id, a.event, a.entity_type, a.entity_id, a.actor_type, a.occurred_at, u.display_name')
            ->join('users u', 'u.id = a.actor_user_id', 'left')
            ->where('a.tenant_id', $this->tenantContext->id())
            ->orderBy('a.id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    private function require(int $actorUserId, string $permission): void
    {
        if (! $this->authorization->userHasPermission($actorUserId, $permission)) {
            throw new RuntimeException('Actor is not authorized for this operation.');
        }
    }

    private function requireAny(int $actorUserId, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->authorization->userHasPermission($actorUserId, $permission)) {
                return;
            }
        }

        throw new RuntimeException('Actor is not authorized for this operation.');
    }
}
