<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddCitizenContactIdentity extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
ADD COLUMN `email_ciphertext` VARCHAR(1024)
    CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER `phone_ciphertext`,
ADD COLUMN `first_name_ciphertext` VARCHAR(1024)
    CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER `email_ciphertext`,
ADD COLUMN `last_name_ciphertext` VARCHAR(1024)
    CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER `first_name_ciphertext`
SQL);
    }

    public function down()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
DROP COLUMN `last_name_ciphertext`,
DROP COLUMN `first_name_ciphertext`,
DROP COLUMN `email_ciphertext`
SQL);
    }
}
