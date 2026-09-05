<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateNotificationCenter extends Migration
{
    private const PERMISSIONS = [
        ['notifications.view', 'Consulter les notifications', 'Voir les messages, leur état et leurs tentatives.'],
        ['notifications.manage', 'Administrer les notifications', 'Relancer ou annuler les messages en attente.'],
    ];

    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
ADD COLUMN `preferred_locale` CHAR(2) NOT NULL DEFAULT 'ht'
    AFTER `last_name_ciphertext`,
ADD COLUMN `preferred_notification_channel` VARCHAR(20) NOT NULL DEFAULT 'auto'
    AFTER `preferred_locale`,
ADD CONSTRAINT `chk_citizen_preferred_locale`
    CHECK (`preferred_locale` IN ('fr', 'ht')),
ADD CONSTRAINT `chk_citizen_preferred_channel`
    CHECK (`preferred_notification_channel` IN ('auto', 'whatsapp', 'sms', 'email', 'manual'))
SQL);

        $this->db->query(<<<'SQL'
UPDATE `citizen_identities` ci
INNER JOIN `tenants` t ON t.`id` = ci.`tenant_id`
SET ci.`preferred_locale` = CASE
    WHEN t.`default_locale` = 'fr' THEN 'fr'
    ELSE 'ht'
END
SQL);

        $this->db->query(<<<'SQL'
ALTER TABLE `tenant_users`
ADD COLUMN `field_mode_enabled` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `is_owner`,
ADD COLUMN `field_department_code` CHAR(5) NULL
    AFTER `field_mode_enabled`,
ADD COLUMN `notification_phone_ciphertext` VARCHAR(1024)
    CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER `field_department_code`,
ADD COLUMN `preferred_notification_channel` VARCHAR(20)
    CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'email'
    AFTER `notification_phone_ciphertext`,
ADD CONSTRAINT `chk_tenant_user_field_mode`
    CHECK (`field_mode_enabled` IN (0, 1)),
ADD CONSTRAINT `chk_tenant_user_field_department`
    CHECK (`field_department_code` IS NULL OR `field_department_code` REGEXP '^HT-[A-Z]{2}$'),
ADD CONSTRAINT `chk_tenant_user_notification_channel`
    CHECK (`preferred_notification_channel` IN ('auto', 'whatsapp', 'sms', 'email'))
SQL);

        $this->db->query(<<<'SQL'
ALTER TABLE `tenant_communication_settings`
ADD COLUMN `whatsapp_notification_template_name` VARCHAR(512) NULL
    AFTER `whatsapp_template_language`,
ADD COLUMN `whatsapp_notification_template_language` VARCHAR(10) NULL
    AFTER `whatsapp_notification_template_name`
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `notification_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `event_key` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `template_key` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `audience` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `recipient_user_id` BIGINT UNSIGNED NULL,
    `citizen_identity_id` BIGINT UNSIGNED NULL,
    `entity_type` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `entity_id` BIGINT UNSIGNED NULL,
    `locale` CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `requested_channel` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'auto',
    `delivered_channel` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `recipient_ciphertext` LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `recipient_masked` VARCHAR(191) NOT NULL,
    `subject_ciphertext` LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
    `body_ciphertext` LONGTEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `content_sensitive` TINYINT(1) NOT NULL DEFAULT 0,
    `idempotency_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
    `priority` SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `available_at` DATETIME NOT NULL,
    `locked_at` DATETIME NULL,
    `lock_token` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `provider_message_id` VARCHAR(191) NULL,
    `last_error_code` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `last_error_detail` VARCHAR(500) NULL,
    `sent_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_uuid` (`uuid`),
    UNIQUE KEY `uq_notification_idempotency` (`tenant_id`, `idempotency_key`),
    KEY `idx_notification_dispatch` (`status`, `available_at`, `priority`, `id`),
    KEY `idx_notification_tenant_status` (`tenant_id`, `status`, `created_at`),
    KEY `idx_notification_citizen` (`tenant_id`, `citizen_identity_id`, `created_at`),
    KEY `idx_notification_user` (`tenant_id`, `recipient_user_id`, `created_at`),

    CONSTRAINT `chk_notification_audience`
        CHECK (`audience` IN ('citizen', 'administrator', 'field', 'system')),
    CONSTRAINT `chk_notification_locale`
        CHECK (`locale` IN ('fr', 'ht')),
    CONSTRAINT `chk_notification_requested_channel`
        CHECK (`requested_channel` IN ('auto', 'whatsapp', 'sms', 'email')),
    CONSTRAINT `chk_notification_delivered_channel`
        CHECK (`delivered_channel` IS NULL OR `delivered_channel` IN ('whatsapp', 'sms', 'email')),
    CONSTRAINT `chk_notification_status`
        CHECK (`status` IN ('queued', 'processing', 'retry', 'sent', 'failed', 'cancelled')),
    CONSTRAINT `chk_notification_sensitive`
        CHECK (`content_sensitive` IN (0, 1)),
    CONSTRAINT `chk_notification_attempts`
        CHECK (`attempt_count` <= `max_attempts` AND `max_attempts` BETWEEN 1 AND 20),

    CONSTRAINT `fk_notification_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_notification_recipient_user`
        FOREIGN KEY (`recipient_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT `fk_notification_citizen`
        FOREIGN KEY (`citizen_identity_id`)
        REFERENCES `citizen_identities` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `notification_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `notification_message_id` BIGINT UNSIGNED NOT NULL,
    `attempt_number` SMALLINT UNSIGNED NOT NULL,
    `channel` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `provider_message_id` VARCHAR(191) NULL,
    `error_code` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `error_detail` VARCHAR(500) NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_attempt` (`notification_message_id`, `attempt_number`, `channel`),
    KEY `idx_notification_attempt_tenant` (`tenant_id`, `attempted_at`),

    CONSTRAINT `chk_notification_attempt_channel`
        CHECK (`channel` IN ('whatsapp', 'sms', 'email')),
    CONSTRAINT `chk_notification_attempt_status`
        CHECK (`status` IN ('sent', 'failed', 'skipped')),
    CONSTRAINT `fk_notification_attempt_tenant`
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_notification_attempt_message`
        FOREIGN KEY (`notification_message_id`) REFERENCES `notification_messages` (`id`)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `notification_digest_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `recipient_user_id` BIGINT UNSIGNED NOT NULL,
    `digest_date` DATE NOT NULL,
    `audience` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `notification_message_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notification_digest` (`tenant_id`, `recipient_user_id`, `digest_date`, `audience`),
    CONSTRAINT `chk_notification_digest_audience`
        CHECK (`audience` IN ('administrator', 'field')),
    CONSTRAINT `fk_notification_digest_user`
        FOREIGN KEY (`tenant_id`, `recipient_user_id`)
        REFERENCES `tenant_users` (`tenant_id`, `user_id`)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT `fk_notification_digest_message`
        FOREIGN KEY (`notification_message_id`) REFERENCES `notification_messages` (`id`)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->installPermissions();
    }

    public function down()
    {
        $codes = array_column(self::PERMISSIONS, 0);
        $ids = array_column(
            $this->db->table('permissions')->select('id')->whereIn('code', $codes)->get()->getResultArray(),
            'id'
        );

        if ($ids !== []) {
            $this->db->table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }

        $this->db->table('permissions')->whereIn('code', $codes)->delete();
        $this->db->query('DROP TABLE IF EXISTS `notification_digest_runs`');
        $this->db->query('DROP TABLE IF EXISTS `notification_attempts`');
        $this->db->query('DROP TABLE IF EXISTS `notification_messages`');
        $this->db->query(<<<'SQL'
ALTER TABLE `tenant_communication_settings`
DROP COLUMN `whatsapp_notification_template_language`,
DROP COLUMN `whatsapp_notification_template_name`
SQL);
        $this->db->query(<<<'SQL'
ALTER TABLE `tenant_users`
DROP CONSTRAINT `chk_tenant_user_notification_channel`,
DROP CONSTRAINT `chk_tenant_user_field_department`,
DROP CONSTRAINT `chk_tenant_user_field_mode`,
DROP COLUMN `preferred_notification_channel`,
DROP COLUMN `notification_phone_ciphertext`,
DROP COLUMN `field_department_code`,
DROP COLUMN `field_mode_enabled`
SQL);
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
DROP CONSTRAINT `chk_citizen_preferred_channel`,
DROP CONSTRAINT `chk_citizen_preferred_locale`,
DROP COLUMN `preferred_notification_channel`,
DROP COLUMN `preferred_locale`
SQL);
    }

    private function installPermissions(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach (self::PERMISSIONS as [$code, $name, $description]) {
            if ($this->db->table('permissions')->where('code', $code)->countAllResults() === 0) {
                if (! $this->db->table('permissions')->insert([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'domain' => 'core',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])) {
                    throw new RuntimeException('Could not add a notification permission.');
                }
            }
        }

        $roles = $this->db->table('roles')->select('id')->where('code', 'identity_admin')->get()->getResultArray();
        $permissions = $this->db->table('permissions')->select('id')
            ->whereIn('code', array_column(self::PERMISSIONS, 0))->get()->getResultArray();

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                $exists = $this->db->table('role_permissions')
                    ->where('role_id', (int) $role['id'])
                    ->where('permission_id', (int) $permission['id'])
                    ->countAllResults();

                if ($exists === 0) {
                    $this->db->table('role_permissions')->insert([
                        'role_id' => (int) $role['id'],
                        'permission_id' => (int) $permission['id'],
                    ]);
                }
            }
        }
    }
}
