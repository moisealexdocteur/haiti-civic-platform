<?php

namespace App\Services;

use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminPasswordResetService
{
    private const TTL_SECONDS = 1800;
    private const COOLDOWN_SECONDS = 60;

    private BaseConnection $db;
    private ?Closure $deliverReset;

    public function __construct(?BaseConnection $db = null, ?Closure $deliverReset = null)
    {
        $this->db = $db ?? Database::connect();
        $this->deliverReset = $deliverReset;
    }

    public function request(string $tenantSlug, string $email, string $resetUrlBase): bool
    {
        $tenantSlug = strtolower(trim($tenantSlug));
        $email = strtolower(trim($email));

        if ($tenantSlug === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $membership = $this->activeMembership($tenantSlug, $email);

        if ($membership === null) {
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            return false;
        }

        $tenantId = (int) $membership['tenant_id'];
        $userId = (int) $membership['user_id'];
        $now = gmdate('Y-m-d H:i:s');
        $cooldown = gmdate('Y-m-d H:i:s', time() - self::COOLDOWN_SECONDS);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start password reset transaction.');
            }

            $this->db->query('SELECT `id` FROM `users` WHERE `id` = ? LIMIT 1 FOR UPDATE', [$userId]);

            $recent = $this->db->table('admin_password_reset_tokens')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('created_at >=', $cooldown)
                ->countAllResults();

            if ($recent !== 0) {
                $this->db->transCommit();
                return false;
            }

            $this->db->table('admin_password_reset_tokens')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('used_at', null)
                ->update(['used_at' => $now]);

            $uuid = $this->uuid();
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            if (! $this->db->table('admin_password_reset_tokens')->insert([
                'uuid' => $uuid,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
                'created_at' => $now,
            ])) {
                throw new RuntimeException('Could not create password reset token.');
            }

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit password reset token.');
            }

            $query = http_build_query([
                'organisation' => $tenantSlug,
                'demande' => $uuid,
                'jeton' => $token,
            ], '', '&', PHP_QUERY_RFC3986);
            $resetUrl = rtrim($resetUrlBase, '?') . '?' . $query;
            $sent = $this->deliverReset !== null
                ? (bool) ($this->deliverReset)($membership, $resetUrl)
                : (new AdminPasswordResetMailer((new TenantContext())->set($tenantId)))->send(
                    (string) $membership['email'],
                    (string) $membership['display_name'],
                    (string) $membership['locale'],
                    $resetUrl
                );

            $audit = new AuditService((new TenantContext())->set($tenantId), $this->db);

            if ($sent) {
                $audit->record(
                    event: 'admin.password_reset_requested',
                    actorUserId: null,
                    actorType: 'system',
                    entityType: 'user',
                    entityId: $userId,
                    context: ['delivery' => 'email']
                );
            } else {
                $this->db->table('admin_password_reset_tokens')
                    ->where('uuid', $uuid)
                    ->where('tenant_id', $tenantId)
                    ->update(['used_at' => gmdate('Y-m-d H:i:s')]);
                $audit->record(
                    event: 'admin.password_reset_delivery_failed',
                    actorUserId: null,
                    actorType: 'system',
                    entityType: 'user',
                    entityId: $userId,
                    context: ['delivery' => 'email']
                );
            }

            $this->forget($token);
            return $sent;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function isUsable(string $tenantSlug, string $requestUuid, string $token): bool
    {
        $row = $this->tokenRow($tenantSlug, $requestUuid);

        return $row !== null
            && $row['used_at'] === null
            && (string) $row['expires_at'] > gmdate('Y-m-d H:i:s')
            && hash_equals((string) $row['token_hash'], hash('sha256', $token));
    }

    public function reset(
        string $tenantSlug,
        string $requestUuid,
        string $token,
        string $newPassword
    ): bool {
        $this->validatePassword($newPassword);
        $tenantSlug = strtolower(trim($tenantSlug));
        $requestUuid = strtolower(trim($requestUuid));
        $now = gmdate('Y-m-d H:i:s');

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start password change transaction.');
            }

            $row = $this->db->query(
                'SELECT prt.*, t.`slug`, tu.`status` AS membership_status, '
                . 'u.`status` AS user_status, u.`session_version` '
                . 'FROM `admin_password_reset_tokens` prt '
                . 'INNER JOIN `tenants` t ON t.`id` = prt.`tenant_id` '
                . 'INNER JOIN `tenant_users` tu ON tu.`tenant_id` = prt.`tenant_id` AND tu.`user_id` = prt.`user_id` '
                . 'INNER JOIN `users` u ON u.`id` = prt.`user_id` '
                . 'WHERE prt.`uuid` = ? AND t.`slug` = ? LIMIT 1 FOR UPDATE',
                [$requestUuid, $tenantSlug]
            )->getFirstRow('array');

            $valid = $row !== null
                && $row['used_at'] === null
                && (string) $row['expires_at'] > $now
                && (string) $row['membership_status'] === 'active'
                && (string) $row['user_status'] === 'active'
                && hash_equals((string) $row['token_hash'], hash('sha256', $token));

            if (! $valid) {
                $this->db->transRollback();
                return false;
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            if (! is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Password hashing failed.');
            }

            $userId = (int) $row['user_id'];
            $tenantId = (int) $row['tenant_id'];
            $this->db->table('users')->where('id', $userId)->update([
                'password_hash' => $passwordHash,
                'session_version' => ((int) $row['session_version']) + 1,
            ]);
            $this->db->table('admin_password_reset_tokens')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('used_at', null)
                ->update(['used_at' => $now]);

            (new AuditService((new TenantContext())->set($tenantId), $this->db))->record(
                event: 'admin.password_reset_completed',
                actorUserId: null,
                actorType: 'system',
                entityType: 'user',
                entityId: $userId
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit password change.');
            }

            return true;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function changeAuthenticatedPassword(
        int $tenantId,
        int $userId,
        string $currentPassword,
        string $newPassword
    ): int {
        $this->validatePassword($newPassword);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start password change transaction.');
            }

            $row = $this->db->query(
                'SELECT u.`password_hash`, u.`session_version` FROM `users` u '
                . 'INNER JOIN `tenant_users` tu ON tu.`user_id` = u.`id` '
                . 'WHERE u.`id` = ? AND tu.`tenant_id` = ? AND u.`status` = ? '
                . 'AND tu.`status` = ? LIMIT 1 FOR UPDATE',
                [$userId, $tenantId, 'active', 'active']
            )->getFirstRow('array');

            if ($row === null || ! password_verify($currentPassword, (string) $row['password_hash'])) {
                $this->db->transRollback();
                return 0;
            }

            $newVersion = ((int) $row['session_version']) + 1;
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

            if (! is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Password hashing failed.');
            }

            $this->db->table('users')->where('id', $userId)->update([
                'password_hash' => $passwordHash,
                'session_version' => $newVersion,
            ]);
            $this->db->table('admin_password_reset_tokens')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('used_at', null)
                ->update(['used_at' => gmdate('Y-m-d H:i:s')]);
            (new AuditService((new TenantContext())->set($tenantId), $this->db))->record(
                event: 'admin.password_changed',
                actorUserId: $userId,
                entityType: 'user',
                entityId: $userId
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit password change.');
            }

            return $newVersion;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function activeMembership(string $tenantSlug, string $email): ?array
    {
        return $this->db->table('tenant_users tu')
            ->select('t.id AS tenant_id, t.slug, u.id AS user_id, u.email, u.display_name, u.locale')
            ->join('tenants t', 't.id = tu.tenant_id')
            ->join('users u', 'u.id = tu.user_id')
            ->where('t.slug', $tenantSlug)
            ->where('t.status', 'active')
            ->where('t.deleted_at', null)
            ->where('tu.status', 'active')
            ->where('u.email', $email)
            ->where('u.status', 'active')
            ->where('u.deleted_at', null)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function tokenRow(string $tenantSlug, string $requestUuid): ?array
    {
        return $this->db->table('admin_password_reset_tokens prt')
            ->select('prt.*')
            ->join('tenants t', 't.id = prt.tenant_id')
            ->join('tenant_users tu', 'tu.tenant_id = prt.tenant_id AND tu.user_id = prt.user_id')
            ->join('users u', 'u.id = prt.user_id')
            ->where('t.slug', strtolower(trim($tenantSlug)))
            ->where('prt.uuid', strtolower(trim($requestUuid)))
            ->where('t.status', 'active')
            ->where('tu.status', 'active')
            ->where('u.status', 'active')
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 14 || mb_strlen($password) > 256) {
            throw new InvalidArgumentException('Administrator password must contain 14 to 256 characters.');
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function forget(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        } else {
            $value = '';
        }
    }
}
