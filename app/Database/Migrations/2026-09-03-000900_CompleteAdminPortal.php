<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CompleteAdminPortal extends Migration
{
    private const PERMISSIONS = [
        ['settings.view', 'Consulter les réglages', 'Voir la configuration technique du tenant.'],
        ['settings.manage', 'Administrer les réglages', 'Modifier les fournisseurs de communication du tenant.'],
        ['users.view', 'Consulter les utilisateurs', 'Voir les membres administratifs du tenant.'],
        ['users.manage', 'Administrer les utilisateurs', 'Créer, activer et désactiver les membres du tenant.'],
        ['roles.view', 'Consulter les rôles', 'Voir les rôles et permissions du tenant.'],
        ['roles.manage', 'Administrer les rôles', 'Attribuer les rôles du tenant.'],
        ['audit.view', 'Consulter le journal d’audit', 'Voir la chaîne d’audit du tenant.'],
    ];

    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `users`
ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1
AFTER `password_hash`
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `tenant_communication_settings` (
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `whatsapp_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `whatsapp_graph_version` VARCHAR(20) NULL,
    `whatsapp_phone_number_id` VARCHAR(30) NULL,
    `whatsapp_access_token_encrypted` LONGTEXT NULL,
    `whatsapp_template_name` VARCHAR(512) NULL,
    `whatsapp_template_language` VARCHAR(10) NULL,
    `sms_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `twilio_account_sid` VARCHAR(40) NULL,
    `twilio_auth_token_encrypted` LONGTEXT NULL,
    `twilio_from_number` VARCHAR(20) NULL,
    `twilio_messaging_service_sid` VARCHAR(40) NULL,
    `email_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `smtp_host` VARCHAR(253) NULL,
    `smtp_port` SMALLINT UNSIGNED NULL,
    `smtp_crypto` VARCHAR(10) NULL,
    `smtp_user` VARCHAR(254) NULL,
    `smtp_password_encrypted` LONGTEXT NULL,
    `email_from_address` VARCHAR(254) NULL,
    `email_from_name` VARCHAR(160) NULL,
    `updated_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`tenant_id`),

    CONSTRAINT `fk_communication_settings_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,

    CONSTRAINT `fk_communication_settings_actor`
        FOREIGN KEY (`updated_by_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE RESTRICT
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `admin_password_reset_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_reset_uuid` (`uuid`),
    KEY `idx_admin_reset_user_time` (`tenant_id`, `user_id`, `created_at`),
    KEY `idx_admin_reset_expiry` (`expires_at`),

    CONSTRAINT `chk_admin_reset_token_hash`
        CHECK (`token_hash` REGEXP '^[0-9a-f]{64}$'),

    CONSTRAINT `fk_admin_reset_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,

    CONSTRAINT `fk_admin_reset_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $now = gmdate('Y-m-d H:i:s');
        $permissionTable = $this->db->table('permissions');

        foreach (self::PERMISSIONS as [$code, $name, $description]) {
            if ($permissionTable->where('code', $code)->countAllResults() !== 0) {
                continue;
            }

            if (! $permissionTable->insert([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'domain' => 'core',
                'created_at' => $now,
                'updated_at' => $now,
            ])) {
                throw new RuntimeException('Could not add an administration permission.');
            }
        }

        $codes = array_column(self::PERMISSIONS, 0);
        $roleRows = $this->db->table('roles')
            ->select('id')
            ->where('code', 'identity_admin')
            ->get()
            ->getResultArray();
        $permissionRows = $this->db->table('permissions')
            ->select('id')
            ->whereIn('code', $codes)
            ->get()
            ->getResultArray();

        foreach ($roleRows as $role) {
            foreach ($permissionRows as $permission) {
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

    public function down()
    {
        $codes = array_column(self::PERMISSIONS, 0);

        $permissionRows = $this->db->table('permissions')
            ->select('id')
            ->whereIn('code', $codes)
            ->get()
            ->getResultArray();
        $permissionIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $permissionRows
        );

        if ($permissionIds !== []) {
            $this->db->table('role_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        $this->db->table('permissions')->whereIn('code', $codes)->delete();
        $this->db->query('DROP TABLE IF EXISTS `admin_password_reset_tokens`');
        $this->db->query('DROP TABLE IF EXISTS `tenant_communication_settings`');
        $this->db->query('ALTER TABLE `users` DROP COLUMN `session_version`');
    }
}
