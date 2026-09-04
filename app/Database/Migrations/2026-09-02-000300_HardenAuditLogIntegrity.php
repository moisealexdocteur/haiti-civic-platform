<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class HardenAuditLogIntegrity extends Migration
{
    private const OLD_ACTOR_FK =
        'fk_audit_actor';

    private const RESTRICT_ACTOR_FK =
        'fk_audit_actor_restrict';

    public function up()
    {
        /*
         * MariaDB < 12.1 requires foreign-key symbols to be
         * unique at database level.
         *
         * Do not DROP and ADD the same symbol inside one
         * ALTER TABLE operation.
         *
         * This implementation also survives a previous partial
         * migration:
         * - old FK only
         * - new FK only
         * - neither FK
         * - both FKs
         */

        $restrictRule = $this->foreignKeyDeleteRule(
            self::RESTRICT_ACTOR_FK
        );

        if (
            $restrictRule !== null
            && $restrictRule !== 'RESTRICT'
        ) {
            $this->dropForeignKey(
                self::RESTRICT_ACTOR_FK
            );
        }

        if (
            $this->foreignKeyDeleteRule(
                self::OLD_ACTOR_FK
            ) !== null
        ) {
            $this->dropForeignKey(
                self::OLD_ACTOR_FK
            );
        }

        if (
            $this->foreignKeyDeleteRule(
                self::RESTRICT_ACTOR_FK
            ) === null
        ) {
            $this->db->query(<<<'SQL'
ALTER TABLE `audit_logs`
    ADD CONSTRAINT `fk_audit_actor_restrict`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
SQL);
        }

        /*
         * The journal is append-only at database level.
         * DROP IF EXISTS makes retries after partial DDL safe.
         */
        $this->db->query(
            'DROP TRIGGER IF EXISTS '
            . '`trg_audit_logs_no_update`'
        );

        $this->db->query(
            'DROP TRIGGER IF EXISTS '
            . '`trg_audit_logs_no_delete`'
        );

        $this->db->query(<<<'SQL'
CREATE TRIGGER `trg_audit_logs_no_update`
BEFORE UPDATE ON `audit_logs`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT =
    'audit_logs is append-only: UPDATE forbidden'
SQL);

        $this->db->query(<<<'SQL'
CREATE TRIGGER `trg_audit_logs_no_delete`
BEFORE DELETE ON `audit_logs`
FOR EACH ROW
SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT =
    'audit_logs is append-only: DELETE forbidden'
SQL);
    }

    public function down()
    {
        $this->db->query(
            'DROP TRIGGER IF EXISTS '
            . '`trg_audit_logs_no_update`'
        );

        $this->db->query(
            'DROP TRIGGER IF EXISTS '
            . '`trg_audit_logs_no_delete`'
        );

        /*
         * Restore the schema created by
         * CreateAuditLogTable.
         */

        if (
            $this->foreignKeyDeleteRule(
                self::RESTRICT_ACTOR_FK
            ) !== null
        ) {
            $this->dropForeignKey(
                self::RESTRICT_ACTOR_FK
            );
        }

        $oldRule = $this->foreignKeyDeleteRule(
            self::OLD_ACTOR_FK
        );

        if (
            $oldRule !== null
            && $oldRule !== 'SET NULL'
        ) {
            $this->dropForeignKey(
                self::OLD_ACTOR_FK
            );

            $oldRule = null;
        }

        if ($oldRule === null) {
            $this->db->query(<<<'SQL'
ALTER TABLE `audit_logs`
    ADD CONSTRAINT `fk_audit_actor`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
SQL);
        }
    }

    private function foreignKeyDeleteRule(
        string $constraintName
    ): ?string {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT `DELETE_RULE`
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE `CONSTRAINT_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'audit_logs'
  AND `CONSTRAINT_NAME` = ?
LIMIT 1
SQL,
                [$constraintName]
            )
            ->getFirstRow('array');

        if ($row === null) {
            return null;
        }

        return strtoupper(
            (string) $row['DELETE_RULE']
        );
    }

    private function dropForeignKey(
        string $constraintName
    ): void {
        /*
         * Only internal constants call this method.
         * No request/user value can become SQL here.
         */
        $allowed = [
            self::OLD_ACTOR_FK,
            self::RESTRICT_ACTOR_FK,
        ];

        if (! in_array(
            $constraintName,
            $allowed,
            true
        )) {
            throw new \LogicException(
                'Unexpected audit foreign key name.'
            );
        }

        $this->db->query(
            sprintf(
                'ALTER TABLE `audit_logs` '
                . 'DROP FOREIGN KEY `%s`',
                $constraintName
            )
        );
    }
}
