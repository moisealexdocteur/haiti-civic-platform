<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class AdminAuthService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function authenticate(
        string $tenantSlug,
        string $email,
        string $password
    ): ?array {
        $tenantSlug = strtolower(trim($tenantSlug));
        $email = strtolower(trim($email));

        if (
            $tenantSlug === ''
            || $email === ''
            || $password === ''
            || mb_strlen($tenantSlug) > 80
            || mb_strlen($email) > 191
        ) {
            return null;
        }

        $row = $this->activeMembershipQuery()
            ->where('t.slug', $tenantSlug)
            ->where('u.email', $email)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            return null;
        }

        $hash = $row['password_hash'] ?? null;

        if (
            ! is_string($hash)
            || $hash === ''
            || ! password_verify($password, $hash)
        ) {
            return null;
        }

        $this->db
            ->table('users')
            ->where('id', (int) $row['user_id'])
            ->update(['last_login_at' => gmdate('Y-m-d H:i:s')]);

        unset($row['password_hash']);

        return $row;
    }

    public function sessionIsActive(
        int $userId,
        int $tenantId,
        string $tenantSlug
    ): bool {
        if (
            $userId <= 0
            || $tenantId <= 0
            || trim($tenantSlug) === ''
        ) {
            return false;
        }

        $row = $this->activeMembershipQuery()
            ->where('t.id', $tenantId)
            ->where('t.slug', strtolower(trim($tenantSlug)))
            ->where('u.id', $userId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        return $row !== null;
    }

    private function activeMembershipQuery()
    {
        return $this->db
            ->table('tenant_users tu')
            ->select([
                't.id AS tenant_id',
                't.uuid AS tenant_uuid',
                't.slug AS tenant_slug',
                't.name AS tenant_name',
                'u.id AS user_id',
                'u.uuid AS user_uuid',
                'u.email',
                'u.display_name',
                'u.password_hash',
            ])
            ->join('tenants t', 't.id = tu.tenant_id')
            ->join('users u', 'u.id = tu.user_id')
            ->where('t.status', 'active')
            ->where('t.deleted_at', null)
            ->where('tu.status', 'active')
            ->where('u.status', 'active')
            ->where('u.deleted_at', null);
    }
}
