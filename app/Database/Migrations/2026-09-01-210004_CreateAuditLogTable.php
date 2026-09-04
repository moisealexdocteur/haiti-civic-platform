<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAuditLogTable extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NULL,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `actor_type` VARCHAR(30) NOT NULL DEFAULT 'user',
    `event` VARCHAR(120) NOT NULL,
    `entity_type` VARCHAR(100) NULL,
    `entity_id` VARCHAR(100) NULL,
    `request_id` CHAR(36) NULL,
    `ip_hash` CHAR(64) NULL,
    `context_json` LONGTEXT NULL,
    `prev_hash` CHAR(64) NULL,
    `entry_hash` CHAR(64) NULL,
    `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_audit_tenant_time` (`tenant_id`, `occurred_at`),
    KEY `idx_audit_actor_time` (`actor_user_id`, `occurred_at`),
    KEY `idx_audit_event_time` (`event`, `occurred_at`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_request` (`request_id`),

    CONSTRAINT `fk_audit_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    CONSTRAINT `fk_audit_actor`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TABLE IF EXISTS `audit_logs`');
    }
}
