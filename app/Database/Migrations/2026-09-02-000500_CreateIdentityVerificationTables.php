<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateIdentityVerificationTables
    extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `citizen_identities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,
    `tenant_id` BIGINT UNSIGNED NOT NULL,

    `ninu_ciphertext`
        VARCHAR(1024)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    `ninu_fingerprint`
        CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NOT NULL,

    `phone_ciphertext`
        VARCHAR(1024)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NULL,

    `verification_status`
        VARCHAR(30)
        NOT NULL
        DEFAULT 'pending',

    `consent_version`
        VARCHAR(80)
        NOT NULL,

    `consented_at`
        DATETIME
        NOT NULL,

    `verified_at`
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
        `uq_ci_uuid`
        (`uuid`),

    UNIQUE KEY
        `uq_ci_tenant_id`
        (`tenant_id`, `id`),

    UNIQUE KEY
        `uq_ci_tenant_ninu`
        (`tenant_id`, `ninu_fingerprint`),

    KEY
        `idx_ci_tenant_status`
        (`tenant_id`, `verification_status`),

    CONSTRAINT
        `chk_ci_ninu_fingerprint`
        CHECK (
            `ninu_fingerprint`
            REGEXP '^[0-9a-f]{64}$'
        ),

    CONSTRAINT
        `fk_ci_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `verification_documents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,

    `tenant_id`
        BIGINT UNSIGNED
        NOT NULL,

    `citizen_identity_id`
        BIGINT UNSIGNED
        NOT NULL,

    `document_type`
        VARCHAR(30)
        NOT NULL,

    `revision_no`
        SMALLINT UNSIGNED
        NOT NULL
        DEFAULT 1,

    `storage_ref`
        VARCHAR(512)
        NOT NULL,

    `content_type`
        VARCHAR(127)
        NULL,

    `size_bytes`
        BIGINT UNSIGNED
        NULL,

    `sha256`
        CHAR(64)
        CHARACTER SET ascii
        COLLATE ascii_bin
        NULL,

    `status`
        VARCHAR(20)
        NOT NULL
        DEFAULT 'active',

    `captured_at`
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
        `uq_vd_uuid`
        (`uuid`),

    UNIQUE KEY
        `uq_vd_tenant_identity_type_revision`
        (
            `tenant_id`,
            `citizen_identity_id`,
            `document_type`,
            `revision_no`
        ),

    KEY
        `idx_vd_tenant_identity`
        (`tenant_id`, `citizen_identity_id`),

    KEY
        `idx_vd_tenant_type`
        (`tenant_id`, `document_type`),

    CONSTRAINT
        `chk_vd_revision`
        CHECK (`revision_no` >= 1),

    CONSTRAINT
        `chk_vd_sha256`
        CHECK (
            `sha256` IS NULL
            OR `sha256`
               REGEXP '^[0-9a-f]{64}$'
        ),

    CONSTRAINT
        `fk_vd_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    CONSTRAINT
        `fk_vd_identity`
        FOREIGN KEY (
            `tenant_id`,
            `citizen_identity_id`
        )
        REFERENCES `citizen_identities` (
            `tenant_id`,
            `id`
        )
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `identity_verification_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid` CHAR(36) NOT NULL,

    `tenant_id`
        BIGINT UNSIGNED
        NOT NULL,

    `citizen_identity_id`
        BIGINT UNSIGNED
        NOT NULL,

    `event_type`
        VARCHAR(50)
        NOT NULL,

    `from_status`
        VARCHAR(30)
        NULL,

    `to_status`
        VARCHAR(30)
        NULL,

    `actor_user_id`
        BIGINT UNSIGNED
        NULL,

    `reason_code`
        VARCHAR(80)
        NULL,

    `context_json`
        LONGTEXT
        NULL,

    `occurred_at`
        DATETIME
        NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    `created_at`
        DATETIME
        NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    UNIQUE KEY
        `uq_ive_uuid`
        (`uuid`),

    KEY
        `idx_ive_tenant_identity_time`
        (
            `tenant_id`,
            `citizen_identity_id`,
            `occurred_at`,
            `id`
        ),

    KEY
        `idx_ive_tenant_event_time`
        (
            `tenant_id`,
            `event_type`,
            `occurred_at`
        ),

    CONSTRAINT
        `chk_ive_context_json`
        CHECK (
            `context_json` IS NULL
            OR JSON_VALID(`context_json`)
        ),

    CONSTRAINT
        `fk_ive_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    CONSTRAINT
        `fk_ive_identity`
        FOREIGN KEY (
            `tenant_id`,
            `citizen_identity_id`
        )
        REFERENCES `citizen_identities` (
            `tenant_id`,
            `id`
        )
        ON UPDATE RESTRICT
        ON DELETE RESTRICT,

    CONSTRAINT
        `fk_ive_actor_membership`
        FOREIGN KEY (
            `tenant_id`,
            `actor_user_id`
        )
        REFERENCES `tenant_users` (
            `tenant_id`,
            `user_id`
        )
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
            'DROP TABLE IF EXISTS `identity_verification_events`'
        );

        $this->db->query(
            'DROP TABLE IF EXISTS `verification_documents`'
        );

        $this->db->query(
            'DROP TABLE IF EXISTS `citizen_identities`'
        );
    }
}
