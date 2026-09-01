<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateModuleTables extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `modules` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(100) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `description` VARCHAR(500) NULL,
    `version` VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    `is_core` TINYINT(1) NOT NULL DEFAULT 0,
    `enabled_by_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_modules_code` (`code`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `tenant_modules` (
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `module_id` BIGINT UNSIGNED NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `config_json` LONGTEXT NULL,
    `license_start_at` DATETIME NULL,
    `license_end_at` DATETIME NULL,
    `activated_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`tenant_id`, `module_id`),

    CONSTRAINT `fk_tenant_modules_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    CONSTRAINT `fk_tenant_modules_module`
        FOREIGN KEY (`module_id`)
        REFERENCES `modules` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS `tenant_modules`');
        $this->db->query('DROP TABLE IF EXISTS `modules`');
    }
}
