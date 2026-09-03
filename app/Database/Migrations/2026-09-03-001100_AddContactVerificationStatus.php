<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddContactVerificationStatus extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
ADD COLUMN `contact_verification_status`
    VARCHAR(30)
    NOT NULL
    DEFAULT 'otp_verified'
    AFTER `phone_ciphertext`,
ADD KEY `idx_ci_tenant_contact_status`
    (`tenant_id`, `contact_verification_status`),
ADD CONSTRAINT `chk_ci_contact_status`
    CHECK (
        `contact_verification_status`
        IN ('otp_verified', 'manual_review')
    )
SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
DROP CONSTRAINT `chk_ci_contact_status`,
DROP INDEX `idx_ci_tenant_contact_status`,
DROP COLUMN `contact_verification_status`
SQL);
    }
}
