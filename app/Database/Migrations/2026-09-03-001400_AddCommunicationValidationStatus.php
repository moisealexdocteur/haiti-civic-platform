<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddCommunicationValidationStatus extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `tenant_communication_settings`
ADD COLUMN `whatsapp_validation_status` VARCHAR(20) NOT NULL DEFAULT 'untested' AFTER `whatsapp_template_language`,
ADD COLUMN `whatsapp_validated_at` DATETIME NULL AFTER `whatsapp_validation_status`,
ADD COLUMN `sms_validation_status` VARCHAR(20) NOT NULL DEFAULT 'untested' AFTER `twilio_messaging_service_sid`,
ADD COLUMN `sms_validated_at` DATETIME NULL AFTER `sms_validation_status`,
ADD COLUMN `email_validation_status` VARCHAR(20) NOT NULL DEFAULT 'untested' AFTER `email_from_name`,
ADD COLUMN `email_validated_at` DATETIME NULL AFTER `email_validation_status`,
ADD CONSTRAINT `chk_whatsapp_validation_status` CHECK (`whatsapp_validation_status` IN ('untested', 'valid')),
ADD CONSTRAINT `chk_sms_validation_status` CHECK (`sms_validation_status` IN ('untested', 'valid')),
ADD CONSTRAINT `chk_email_validation_status` CHECK (`email_validation_status` IN ('untested', 'valid'))
SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `tenant_communication_settings`
DROP CONSTRAINT `chk_whatsapp_validation_status`,
DROP CONSTRAINT `chk_sms_validation_status`,
DROP CONSTRAINT `chk_email_validation_status`,
DROP COLUMN `whatsapp_validated_at`,
DROP COLUMN `whatsapp_validation_status`,
DROP COLUMN `sms_validated_at`,
DROP COLUMN `sms_validation_status`,
DROP COLUMN `email_validated_at`,
DROP COLUMN `email_validation_status`
SQL);
    }
}
