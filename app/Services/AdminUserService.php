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
        bool $isOwner = false,
        ?string $notificationPhone = null,
        string $preferredNotificationChannel = 'email'
    ): int {
        $this->require($actorUserId, 'users.manage');
        $this->require($actorUserId, 'roles.manage');
        $displayName = trim($displayName);
        $email = strtolower(trim($email));
        $locale = strtolower(trim($locale));
        $notificationPhone = (new IdentityInputNormalizer())->normalizeHaitiPhone($notificationPhone);
        $preferredNotificationChannel = $this->notificationChannel($preferredNotificationChannel);

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
                'notification_phone_ciphertext' => $this->encryptPhone($userId, $notificationPhone),
                'preferred_notification_channel' => $preferredNotificationChannel,
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
                    'notification_phone_present' => $notificationPhone !== null,
                    'preferred_notification_channel' => $preferredNotificationChannel,
                ]
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit administrator creation.');
            }

            try {
                (new NotificationOrchestrator($this->tenantContext, $this->db))
                    ->userCreated($userId);
            } catch (Throwable $notificationException) {
                log_message('error', 'User creation notification could not be queued: {type}', [
                    'type' => $notificationException::class,
                ]);
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

    public function setFieldMode(
        int $actorUserId,
        int $userId,
        bool $enabled,
        ?string $departmentCode,
        ?string $notificationPhone = null,
        bool $clearNotificationPhone = false,
        string $preferredNotificationChannel = 'email'
    ): void {
        $this->require($actorUserId, 'users.manage');
        $departmentCode = $enabled
            ? (new HaitiDepartmentCatalog())->normalize($departmentCode)
            : null;
        $notificationPhone = (new IdentityInputNormalizer())->normalizeHaitiPhone($notificationPhone);
        $preferredNotificationChannel = $this->notificationChannel($preferredNotificationChannel);

        try {
            if (! $this->db->transBegin()) {
                throw new RuntimeException('Could not start field mode transaction.');
            }

            $membership = $this->db->query(
                'SELECT `id`, `field_mode_enabled`, `field_department_code`, '
                . '`notification_phone_ciphertext`, `preferred_notification_channel` '
                . 'FROM `tenant_users` WHERE `tenant_id` = ? AND `user_id` = ? LIMIT 1 FOR UPDATE',
                [$this->tenantContext->id(), $userId]
            )->getFirstRow('array');

            if ($membership === null) {
                throw new InvalidArgumentException('User does not belong to the current tenant.');
            }

            $phoneCiphertext = $clearNotificationPhone
                ? null
                : ($notificationPhone === null
                    ? ($membership['notification_phone_ciphertext'] ?? null)
                    : $this->encryptPhone($userId, $notificationPhone));

            if ((int) $membership['field_mode_enabled'] === ($enabled ? 1 : 0)
                && ($membership['field_department_code'] ?? null) === $departmentCode
                && ($membership['notification_phone_ciphertext'] ?? null) === $phoneCiphertext
                && (string) $membership['preferred_notification_channel'] === $preferredNotificationChannel) {
                $this->db->transCommit();
                return;
            }

            $this->db->table('tenant_users')->where('id', (int) $membership['id'])->update([
                'field_mode_enabled' => $enabled ? 1 : 0,
                'field_department_code' => $departmentCode,
                'notification_phone_ciphertext' => $phoneCiphertext,
                'preferred_notification_channel' => $preferredNotificationChannel,
            ]);
            $this->audit->record(
                event: 'tenant_user.field_mode_changed',
                actorUserId: $actorUserId,
                entityType: 'tenant_user',
                entityId: (int) $membership['id'],
                context: [
                    'target_user_id' => $userId,
                    'enabled' => $enabled,
                    'department_code' => $departmentCode,
                    'notification_phone_present' => $phoneCiphertext !== null,
                    'preferred_notification_channel' => $preferredNotificationChannel,
                ]
            );

            if (! $this->db->transStatus() || ! $this->db->transCommit()) {
                throw new RuntimeException('Could not commit field mode change.');
            }

            try {
                (new NotificationOrchestrator($this->tenantContext, $this->db))->fieldModeChanged(
                    $userId,
                    $enabled,
                    $departmentCode,
                    gmdate('YmdHis') . ':' . bin2hex(random_bytes(4))
                );
            } catch (Throwable $notificationException) {
                log_message('error', 'Field mode notification could not be queued: {type}', [
                    'type' => $notificationException::class,
                ]);
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function require(int $actorUserId, string $permission): void
    {
        if (! $this->authorization->userHasPermission($actorUserId, $permission)) {
            throw new RuntimeException('Actor is not authorized for this operation.');
        }
    }

    private function notificationChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, ['auto', 'whatsapp', 'sms', 'email'], true)) {
            throw new InvalidArgumentException('Administrator notification channel is invalid.');
        }

        return $channel;
    }

    private function encryptPhone(int $userId, ?string $phone): ?string
    {
        return $phone === null ? null : (new TenantSecretCipher($this->tenantContext))
            ->encrypt('tenant_user.phone.' . $userId, $phone);
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
