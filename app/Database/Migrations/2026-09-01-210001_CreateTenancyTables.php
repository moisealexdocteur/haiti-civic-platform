<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTenancyTables extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `tenants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `slug` VARCHAR(80) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `default_locale` VARCHAR(10) NOT NULL DEFAULT 'fr',
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'America/Port-au-Prince',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenants_uuid` (`uuid`),
    UNIQUE KEY `uq_tenants_slug` (`slug`),
    KEY `idx_tenants_status` (`status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `organizations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'organization',
    `code` VARCHAR(80) NULL,
    `name` VARCHAR(190) NOT NULL,
    `legal_name` VARCHAR(190) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_organizations_uuid` (`uuid`),
    UNIQUE KEY `uq_organizations_tenant_code` (`tenant_id`, `code`),
    KEY `idx_organizations_tenant_status` (`tenant_id`, `status`),

    CONSTRAINT `fk_organizations_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `display_name` VARCHAR(160) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `locale` VARCHAR(10) NOT NULL DEFAULT 'fr',
    `last_login_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_uuid` (`uuid`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `tenant_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `is_owner` TINYINT(1) NOT NULL DEFAULT 0,
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_users_membership` (`tenant_id`, `user_id`),
    KEY `idx_tenant_users_user` (`user_id`),
    KEY `idx_tenant_users_status` (`tenant_id`, `status`),

    CONSTRAINT `fk_tenant_users_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT `fk_tenant_users_user`
        FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS `tenant_users`');
        $this->db->query('DROP TABLE IF EXISTS `users`');
        $this->db->query('DROP TABLE IF EXISTS `organizations`');
        $this->db->query('DROP TABLE IF EXISTS `tenants`');
    }
}
