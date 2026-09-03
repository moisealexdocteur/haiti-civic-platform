<?php

namespace App\Database\Migrations;

use App\Services\PublicReferenceGenerator;
use CodeIgniter\Database\Migration;
use RuntimeException;

final class AddPublicIdentityReference extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
ADD COLUMN `public_reference`
    CHAR(19)
    CHARACTER SET ascii
    COLLATE ascii_bin
    NULL
AFTER `uuid`
SQL);

        $generator = new PublicReferenceGenerator();
        $rows = $this->db->table('citizen_identities')
            ->select('id')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        $used = [];

        foreach ($rows as $row) {
            do {
                $reference = $generator->generate();
            } while (isset($used[$reference]));

            $used[$reference] = true;
            $updated = $this->db->table('citizen_identities')
                ->where('id', (int) $row['id'])
                ->update(['public_reference' => $reference]);

            if (! $updated) {
                throw new RuntimeException('Could not backfill a public identity reference.');
            }
        }

        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
MODIFY COLUMN `public_reference`
    CHAR(19)
    CHARACTER SET ascii
    COLLATE ascii_bin
    NOT NULL,
ADD UNIQUE KEY `uq_ci_public_reference` (`public_reference`),
ADD CONSTRAINT `chk_ci_public_reference`
    CHECK (
        `public_reference`
        REGEXP '^DOS-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$'
    )
SQL);

        // Filet de sécurité pour les imports internes et les anciens outils
        // qui n'envoient pas encore la référence publique.
        $this->db->query(<<<'SQL'
CREATE TRIGGER `trg_ci_public_reference_before_insert`
BEFORE INSERT ON `citizen_identities`
FOR EACH ROW
SET NEW.`public_reference` = COALESCE(
    NULLIF(NEW.`public_reference`, ''),
    CONCAT(
        'DOS-',
        UPPER(SUBSTRING(REPLACE(REPLACE(REPLACE(UUID(), '-', ''), '0', '2'), '1', '3'), 1, 4)), '-',
        UPPER(SUBSTRING(REPLACE(REPLACE(REPLACE(UUID(), '-', ''), '0', '2'), '1', '3'), 5, 4)), '-',
        UPPER(SUBSTRING(REPLACE(REPLACE(REPLACE(UUID(), '-', ''), '0', '2'), '1', '3'), 9, 4))
    )
)
SQL);
    }

    public function down()
    {
        $this->db->query('DROP TRIGGER IF EXISTS `trg_ci_public_reference_before_insert`');
        $this->db->query(<<<'SQL'
ALTER TABLE `citizen_identities`
DROP CONSTRAINT `chk_ci_public_reference`,
DROP INDEX `uq_ci_public_reference`,
DROP COLUMN `public_reference`
SQL);
    }
}
