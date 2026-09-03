<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminUserService
{
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->authorization = new AuthorizationService($tenantContext, $this->db);
        $this->audit = new AuditService($tenantContext, $this->db);
    }

    public function create(
        int $actorUserId,
        string $displayName,
        string $email,
        string $locale,
        string $password,
        int $roleId,
        bool $isOwner = false
    ): int {
        $this->require($actorUserId, 'users.manage');
        $this->require($actorUserId, 'roles.manage');
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        $locale = strtolower(trim($locale));

        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new InvalidArgumentException('Administrator display name is invalid.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw new InvalidArgumentException('Administrator email is invalid.');
        }

        if (! in_array($locale, ['fr', 'ht'], true)) {
            throw new InvalidArgumentException('Administrator locale is invalid.');
        }

        if (mb_strlen($password) < 14 || mb_strlen($password) > 256) {
            throw new InvalidArgumentException('Administrator password must contain 14 to 256 characters.');
        }

        $tenantId = $this->tenantContext->id();

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start administrator creation transaction.');
            }

            $role = $this->db->query(
                'SELECT `id`, `code` FROM `roles` WHERE `tenant_id` = ? AND `id` = ? LIMIT 1 FOR UPDATE',
                [$tenantId, $roleId]
            )->getFirstRow('array');

            if ($role === null) {
                throw new InvalidArgumentException('Role does not belong to the current tenant.');
            }

            if ($this->db->table('users')->where('email', $email)->countAllResults() !== 0) {
                throw new InvalidArgumentException('Administrator email is already in use.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            if (! is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Administrator password hashing failed.');
            }

            if (! $this->db->table('users')->insert([
                'uuid' => $this->uuid(),
                'email' => $email,
                'password_hash' => $passwordHash,
                'session_version' => 1,
                'display_name' => $displayName,
                'status' => 'active',
                'locale' => $locale,
            ])) {
                throw new RuntimeException('Could not create administrator.');
            }

            $userId = (int) $this->db->insertID();
            $this->db->table('tenant_users')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'status' => 'active',
                'is_owner' => $isOwner ? 1 : 0,
            ]);
            $this->db->table('user_roles')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);

            $this->audit->record(
                event: 'admin.user_created',
                actorUserId: $actorUserId,
                entityType: 'user',
                entityId: $userId,
                context: [
                    'role_code' => (string) $role['code'],
                    'locale' => $locale,
                    'is_owner' => $isOwner,
                ]
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit administrator creation.');
            }

            return $userId;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    public function setStatus(int $actorUserId, int $userId, string $status): void
    {
        (new TenantUserWriteService($this->tenantContext, $this->db))
            ->setStatus($actorUserId, $userId, $status);
    }

    private function require(int $actorUserId, string $permission): void
    {
        if (! $this->authorization->userHasPermission($actorUserId, $permission)) {
            throw new RuntimeException('Actor is not authorized for this operation.');
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
}
