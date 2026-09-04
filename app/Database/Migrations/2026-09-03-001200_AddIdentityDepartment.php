<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddIdentityDepartment extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
ADD COLUMN `department_code`
    CHAR(5)
    CHARACTER SET ascii
    COLLATE ascii_bin
    NULL
    AFTER `contact_verification_status`,
ADD KEY `idx_ci_tenant_department_status`
    (`tenant_id`, `department_code`, `verification_status`),
ADD CONSTRAINT `chk_ci_department_code`
    CHECK (
        `department_code` IS NULL
        OR `department_code` IN (
            'HT-AR', 'HT-CE', 'HT-GA', 'HT-NI', 'HT-ND',
            'HT-NE', 'HT-NO', 'HT-OU', 'HT-SD', 'HT-SE'
        )
    )
SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
DROP CONSTRAINT `chk_ci_department_code`,
DROP INDEX `idx_ci_tenant_department_status`,
DROP COLUMN `department_code`
SQL);
    }
}
