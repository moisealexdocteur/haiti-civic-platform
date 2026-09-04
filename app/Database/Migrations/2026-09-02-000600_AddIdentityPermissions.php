<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

final class AddIdentityPermissions extends Migration
{
    private const CODES = [
        'identity.view',
        'identity.manage',
    ];

    public function up()
    {
        $existing = $this->db
            ->table('permissions')
            ->select('code')
            ->whereIn(
                'code',
                self::CODES
            )
            ->get()
            ->getResultArray();

        /*
         * Cette migration doit être propriétaire
         * de ces deux codes.
         *
         * Refus explicite d'écraser une permission
         * préexistante dont la provenance serait ambiguë.
         */
        if ($existing !== []) {
            throw new RuntimeException(
                'Identity permissions already exist; '
                . 'manual reconciliation is required.'
            );
        }

        if (! $this->db->transBegin()) {
            throw new RuntimeException(
                'Could not start identity permission transaction.'
            );
        }

        try {
            $now = gmdate(
                'Y-m-d H:i:s'
            );

            $inserted = $this->db
                ->table('permissions')
                ->insertBatch([
                    [
                        'code' =>
                            'identity.view',
                        'name' =>
                            'Consulter les identités citoyennes',
                        'description' =>
                            'Consulter les données '
                            . 'd’identité autorisées du tenant.',
                        'domain' =>
                            'identity_verification',
                        'created_at' =>
                            $now,
                        'updated_at' =>
                            $now,
                    ],
                    [
                        'code' =>
                            'identity.manage',
                        'name' =>
                            'Administrer les identités citoyennes',
                        'description' =>
                            'Créer et modifier les identités '
                            . 'citoyennes du tenant.',
                        'domain' =>
                            'identity_verification',
                        'created_at' =>
                            $now,
                        'updated_at' =>
                            $now,
                    ],
                ]);

            if ($inserted === false) {
                throw new RuntimeException(
                    'Identity permission insert failed.'
                );
            }

            $count = $this->db
                ->table('permissions')
                ->whereIn(
                    'code',
                    self::CODES
                )
                ->countAllResults();

            if ($count !== 2) {
                throw new RuntimeException(
                    'Identity permission catalog '
                    . 'is incomplete after insert.'
                );
            }

            if (! $this->db->transStatus()) {
                throw new RuntimeException(
                    'Identity permission transaction failed.'
                );
            }

            if (! $this->db->transCommit()) {
                throw new RuntimeException(
                    'Identity permission commit failed.'
                );
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }

    public function down()
    {
        /*
         * Le FK role_permissions -> permissions est CASCADE.
         *
         * Nous refusons donc explicitement le rollback
         * si une permission identité a déjà été affectée,
         * afin d'éviter une suppression silencieuse
         * des affectations RBAC.
         */
        $assignments = $this->db
            ->table('role_permissions rp')
            ->join(
                'permissions p',
                'p.id = rp.permission_id'
            )
            ->whereIn(
                'p.code',
                self::CODES
            )
            ->countAllResults();

        if ($assignments !== 0) {
            throw new RuntimeException(
                'Cannot remove identity permissions '
                . 'while role assignments exist.'
            );
        }

        if (! $this->db->transBegin()) {
            throw new RuntimeException(
                'Could not start identity permission rollback.'
            );
        }

        try {
            $deleted = $this->db
                ->table('permissions')
                ->whereIn(
                    'code',
                    self::CODES
                )
                ->delete();

            if (! $deleted) {
                throw new RuntimeException(
                    'Identity permission delete failed.'
                );
            }

            $remaining = $this->db
                ->table('permissions')
                ->whereIn(
                    'code',
                    self::CODES
                )
                ->countAllResults();

            if ($remaining !== 0) {
                throw new RuntimeException(
                    'Identity permissions remain '
                    . 'after rollback.'
                );
            }

            if (! $this->db->transStatus()) {
                throw new RuntimeException(
                    'Identity permission rollback failed.'
                );
            }

            if (! $this->db->transCommit()) {
                throw new RuntimeException(
                    'Identity permission rollback commit failed.'
                );
            }
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        }
    }
}
