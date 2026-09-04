<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateOtpChallengeTable extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `otp_challenges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,

    `purpose`
        VARCHAR(50)
        NOT NULL,

    `phone_fingerprint`
        CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    `code_digest`
        CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    `attempts_used`
        TINYINT UNSIGNED
        NOT NULL
        DEFAULT 0,

    `max_attempts`
        TINYINT UNSIGNED
        NOT NULL
        DEFAULT 5,

    `requested_channel`
        VARCHAR(20)
        NOT NULL
        DEFAULT 'whatsapp',

    `delivered_channel`
        VARCHAR(20)
        NULL,

    `provider_message_ref`
        VARCHAR(191)
        NULL,

    `request_fingerprint`
        CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NULL,

    `expires_at`
        DATETIME
        NOT NULL,

    `consumed_at`
        DATETIME
        NULL,

    `invalidated_at`
        DATETIME
        NULL,

    `locked_at`
        DATETIME
        NULL,

    `created_at`
        DATETIME
        NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    `updated_at`
        DATETIME
        NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    UNIQUE KEY
        `uq_otp_uuid`
        (`uuid`),

    KEY
        `idx_otp_tenant_phone_purpose_time`
        (
            `tenant_id`,
            `phone_fingerprint`,
            `purpose`,
            `created_at`,
            `id`
        ),

    KEY
        `idx_otp_tenant_request_time`
        (`tenant_id`, `request_fingerprint`, `created_at`),

    KEY
        `idx_otp_tenant_expiry`
        (`tenant_id`, `expires_at`),

    CONSTRAINT
        `chk_otp_phone_fingerprint`
        CHECK (
            `phone_fingerprint`
            REGEXP '^[0-9a-f]{64}$'
        ),

    CONSTRAINT
        `chk_otp_code_digest`
        CHECK (
            `code_digest`
            REGEXP '^[0-9a-f]{64}$'
        ),

    CONSTRAINT
        `chk_otp_request_fingerprint`
        CHECK (
            `request_fingerprint` IS NULL
            OR `request_fingerprint`
               REGEXP '^[0-9a-f]{64}$'
        ),

    CONSTRAINT
        `chk_otp_attempts`
        CHECK (
            `max_attempts` BETWEEN 1 AND 10
            AND `attempts_used` <= `max_attempts`
        ),

    CONSTRAINT
        `fk_otp_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        $this->db->query(
            'DROP TABLE IF EXISTS `otp_challenges`'
        );
    }
}
