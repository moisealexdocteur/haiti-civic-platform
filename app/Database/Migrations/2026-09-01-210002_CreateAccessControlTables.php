<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAccessControlTables extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `permissions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(120) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `description` VARCHAR(500) NULL,
    `domain` VARCHAR(80) NOT NULL DEFAULT 'core',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissions_code` (`code`),
    KEY `idx_permissions_domain` (`domain`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `roles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `description` VARCHAR(500) NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_uuid` (`uuid`),
    UNIQUE KEY `uq_roles_tenant_code` (`tenant_id`, `code`),
    UNIQUE KEY `uq_roles_id_tenant` (`id`, `tenant_id`),

    CONSTRAINT `fk_roles_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `role_permissions` (
    `role_id` BIGINT UNSIGNED NOT NULL,
    `permission_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`role_id`, `permission_id`),

    CONSTRAINT `fk_role_permissions_role`
        FOREIGN KEY (`role_id`)
        REFERENCES `roles` (`id`)
        ON DELETE CASCADE,

    CONSTRAINT `fk_role_permissions_permission`
        FOREIGN KEY (`permission_id`)
        REFERENCES `permissions` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `user_roles` (
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`tenant_id`, `user_id`, `role_id`),

    KEY `idx_user_roles_role_tenant` (`role_id`, `tenant_id`),

    CONSTRAINT `fk_user_roles_tenant_user`
        FOREIGN KEY (`tenant_id`, `user_id`)
        REFERENCES `tenant_users` (`tenant_id`, `user_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT `fk_user_roles_role_tenant`
        FOREIGN KEY (`role_id`, `tenant_id`)
        REFERENCES `roles` (`id`, `tenant_id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS `user_roles`');
        $this->db->query('DROP TABLE IF EXISTS `role_permissions`');
        $this->db->query('DROP TABLE IF EXISTS `roles`');
        $this->db->query('DROP TABLE IF EXISTS `permissions`');
    }
}
