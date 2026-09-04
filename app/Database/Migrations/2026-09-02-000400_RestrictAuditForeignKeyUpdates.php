<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use LogicException;

final class RestrictAuditForeignKeyUpdates extends Migration
{
    private const OLD_ACTOR_FK =
        'fk_audit_actor';

    private const ACTOR_FK =
        'fk_audit_actor_restrict';

    private const TENANT_FK =
        'fk_audit_tenant';

    public function up()
    {
        /*
         * Les deux colonnes ci-dessous participent au hash
         * de chaque entrée d'audit :
         *
         * - tenant_id
         * - actor_user_id
         *
         * Elles ne doivent donc jamais être modifiées
         * implicitement par une cascade de clé étrangère.
         *
         * Cette migration complète 000300 sans réécrire
         * l'historique des migrations.
         */

        $this->dropLegacyActorForeignKey();

        $this->ensureActorForeignKey(
            'RESTRICT'
        );

        $this->ensureTenantForeignKey(
            'RESTRICT'
        );
    }

    public function down()
    {
        /*
         * Retour à l'état laissé par la migration 000300 :
         *
         * actor :
         *   UPDATE CASCADE
         *   DELETE RESTRICT
         *
         * tenant :
         *   UPDATE CASCADE
         *   DELETE RESTRICT
         *
         * Le rollback de 000300 pourra ensuite, si demandé,
         * restaurer fk_audit_actor avec DELETE SET NULL.
         */

        $this->dropLegacyActorForeignKey();

        $this->ensureActorForeignKey(
            'CASCADE'
        );

        $this->ensureTenantForeignKey(
            'CASCADE'
        );
    }

    private function ensureActorForeignKey(
        string $updateRule
    ): void {
        $this->assertUpdateRule(
            $updateRule
        );

        $rules = $this->foreignKeyRules(
            self::ACTOR_FK
        );

        if (
            $rules !== null
            && $rules['UPDATE_RULE'] === $updateRule
            && $rules['DELETE_RULE'] === 'RESTRICT'
        ) {
            return;
        }

        if ($rules !== null) {
            $this->dropForeignKey(
                self::ACTOR_FK
            );
        }

        if ($updateRule === 'RESTRICT') {
            $this->db->query(<<<'SQL'
ALTER TABLE `audit_logs`
    ADD CONSTRAINT `fk_audit_actor_restrict`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
SQL);
            return;
        }

        $this->db->query(<<<'SQL'
ALTER TABLE `audit_logs`
    ADD CONSTRAINT `fk_audit_actor_restrict`
        FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
SQL);
    }

    private function ensureTenantForeignKey(
        string $updateRule
    ): void {
        $this->assertUpdateRule(
            $updateRule
        );

        $rules = $this->foreignKeyRules(
            self::TENANT_FK
        );

        if (
            $rules !== null
            && $rules['UPDATE_RULE'] === $updateRule
            && $rules['DELETE_RULE'] === 'RESTRICT'
        ) {
            return;
        }

        if ($rules !== null) {
            $this->dropForeignKey(
                self::TENANT_FK
            );
        }

        if ($updateRule === 'RESTRICT') {
            $this->db->query(<<<'SQL'
ALTER TABLE `audit_logs`
    ADD CONSTRAINT `fk_audit_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT
SQL);
            return;
        }

        $this->db->query(<<<'SQL'
ALTER TABLE `audit_logs`
    ADD CONSTRAINT `fk_audit_tenant`
        FOREIGN KEY (`tenant_id`)
        REFERENCES `tenants` (`id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
SQL);
    }

    private function dropLegacyActorForeignKey(): void
    {
        if (
            $this->foreignKeyRules(
                self::OLD_ACTOR_FK
            ) !== null
        ) {
            $this->dropForeignKey(
                self::OLD_ACTOR_FK
            );
        }
    }

    private function foreignKeyRules(
        string $constraintName
    ): ?array {
        $row = $this->db
            ->query(
                <<<'SQL'
SELECT
    `UPDATE_RULE`,
    `DELETE_RULE`
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

        return [
            'UPDATE_RULE' => strtoupper(
                (string) $row['UPDATE_RULE']
            ),
            'DELETE_RULE' => strtoupper(
                (string) $row['DELETE_RULE']
            ),
        ];
    }

    private function assertUpdateRule(
        string $updateRule
    ): void {
        if (! in_array(
            $updateRule,
            [
                'RESTRICT',
                'CASCADE',
            ],
            true
        )) {
            throw new LogicException(
                'Unexpected audit FK update rule.'
            );
        }
    }

    private function dropForeignKey(
        string $constraintName
    ): void {
        $allowed = [
            self::OLD_ACTOR_FK,
            self::ACTOR_FK,
            self::TENANT_FK,
        ];

        if (! in_array(
            $constraintName,
            $allowed,
            true
        )) {
            throw new LogicException(
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
