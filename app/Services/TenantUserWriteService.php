<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TenantUserWriteService
{
    private const AUDIT_LOCK_TIMEOUT = 5;

    private TenantContext $tenantContext;
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();

        $this->authorization = new AuthorizationService(
            $tenantContext,
            $this->db
        );

        $this->audit = new AuditService(
            $tenantContext,
            $this->db
        );
    }

    public function addMember(
        int $actorUserId,
        int $userId,
        bool $isOwner = false
    ): int {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $userId,
            'User ID'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            /*
             * L'autorisation est contrôlée après acquisition
             * du verrou tenant afin qu'une modification de rôle
             * passant par les services d'écriture ne puisse pas
             * rendre cette décision obsolète.
             */
            $this->requirePermission(
                $actorUserId,
                'users.manage'
            );

            $this->assertActiveUserExists(
                $userId
            );

            /*
             * SELECT ... FOR UPDATE sur la clé unique logique
             * (tenant_id, user_id).
             *
             * Si la ligne existe, elle est verrouillée.
             * La contrainte UNIQUE reste la protection finale
             * contre une insertion concurrente.
             */
            if ($this->membershipForUpdate(
                $tenantId,
                $userId
            ) !== null) {
                throw new InvalidArgumentException(
                    'User already belongs to the current tenant.'
                );
            }

            $inserted = $this->db
                ->table('tenant_users')
                ->insert([
                    'tenant_id' => $tenantId,
                    'user_id'   => $userId,
                    'status'    => 'active',
                    'is_owner'  => $isOwner ? 1 : 0,
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Could not create tenant membership.'
                );
            }

            $membershipId =
                (int) $this->db->insertID();

            $this->audit->record(
                event: 'tenant_user.created',
                actorUserId: $actorUserId,
                entityType: 'tenant_user',
                entityId: $membershipId,
                context: [
                    'target_user_id' => $userId,
                    'status'         => 'active',
                    'is_owner'       => $isOwner,
                ]
            );

            $this->commitOrFail();

            return $membershipId;
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    public function setStatus(
        int $actorUserId,
        int $userId,
        string $status
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $userId,
            'User ID'
        );

        $status = strtolower(trim($status));

        if (! in_array(
            $status,
            ['active', 'inactive'],
            true
        )) {
            throw new InvalidArgumentException(
                'Membership status must be active or inactive.'
            );
        }

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'users.manage'
            );

            $membership =
                $this->membershipForUpdate(
                    $tenantId,
                    $userId
                );

            if ($membership === null) {
                throw new InvalidArgumentException(
                    'User does not belong to the current tenant.'
                );
            }

            $oldStatus =
                (string) $membership['status'];

            if ($oldStatus === $status) {
                $this->commitOrFail();
                return;
            }

            if (
                $status === 'inactive'
                && (int) $membership['is_owner'] === 1
            ) {
                $this->lockActiveOwnersAndAssertAnother(
                    $tenantId,
                    $userId
                );
            }

            $updated = $this->db
                ->table('tenant_users')
                ->where(
                    'id',
                    (int) $membership['id']
                )
                ->where('tenant_id', $tenantId)
                ->update([
                    'status' => $status,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Could not update tenant membership status.'
                );
            }

            $this->audit->record(
                event: 'tenant_user.status_changed',
                actorUserId: $actorUserId,
                entityType: 'tenant_user',
                entityId: (int) $membership['id'],
                context: [
                    'target_user_id' => $userId,
                    'old_status'     => $oldStatus,
                    'new_status'     => $status,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    public function setOwner(
        int $actorUserId,
        int $userId,
        bool $isOwner
    ): void {
        $tenantId = $this->tenantContext->id();

        $this->assertPositiveId(
            $actorUserId,
            'Actor user ID'
        );

        $this->assertPositiveId(
            $userId,
            'User ID'
        );

        $lockName = $this->auditLockName($tenantId);
        $this->acquireAuditLock($lockName);

        try {
            $this->beginTransaction();

            $this->requirePermission(
                $actorUserId,
                'users.manage'
            );

            $membership =
                $this->membershipForUpdate(
                    $tenantId,
                    $userId
                );

            if ($membership === null) {
                throw new InvalidArgumentException(
                    'User does not belong to the current tenant.'
                );
            }

            $oldOwner =
                (int) $membership['is_owner'] === 1;

            if ($oldOwner === $isOwner) {
                $this->commitOrFail();
                return;
            }

            if ($oldOwner && ! $isOwner) {
                $this->lockActiveOwnersAndAssertAnother(
                    $tenantId,
                    $userId
                );
            }

            $updated = $this->db
                ->table('tenant_users')
                ->where(
                    'id',
                    (int) $membership['id']
                )
                ->where('tenant_id', $tenantId)
                ->update([
                    'is_owner' =>
                        $isOwner ? 1 : 0,
                ]);

            if (! $updated) {
                throw new RuntimeException(
                    'Could not update tenant ownership.'
                );
            }

            $this->audit->record(
                event: 'tenant_user.owner_changed',
                actorUserId: $actorUserId,
                entityType: 'tenant_user',
                entityId: (int) $membership['id'],
                context: [
                    'target_user_id' => $userId,
                    'old_is_owner'   => $oldOwner,
                    'new_is_owner'   => $isOwner,
                ]
            );

            $this->commitOrFail();
        } catch (Throwable $exception) {
            $this->rollbackIfNeeded();
            throw $exception;
        } finally {
            $this->releaseAuditLock(
                $lockName
            );
        }
    }

    private function requirePermission(
        int $actorUserId,
        string $permission
    ): void {
        if (! $this->authorization->userHasPermission(
            $actorUserId,
            $permission
        )) {
            throw new RuntimeException(
                'Actor is not authorized for this operation.'
            );
        }
    }

    private function assertActiveUserExists(int $userId): void
    {
        $row = $this->db
            ->table('users')
            ->select('id')
            ->where('id', $userId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Target user does not exist or is not active.'
            );
        }
    }

    private function membershipForUpdate(
        int $tenantId,
        int $userId
    ): ?array {
        return $this->db
            ->query(
                <<<'SQL'
SELECT
    `id`,
    `tenant_id`,
    `user_id`,
    `status`,
    `is_owner`
FROM `tenant_users`
WHERE `tenant_id` = ?
  AND `user_id` = ?
LIMIT 1
FOR UPDATE
SQL,
                [
                    $tenantId,
                    $userId,
                ]
            )
            ->getFirstRow('array');
    }

    private function lockActiveOwnersAndAssertAnother(
        int $tenantId,
        int $excludedUserId
    ): void {
        /*
         * Verrouille l'ensemble des propriétaires actifs
         * actuellement visibles pour le tenant.
         *
         * Deux opérations métier conformes à ce service ne
         * peuvent donc pas toutes deux valider l'invariant
         * "un autre propriétaire existe" sur le même état.
         */
        $rows = $this->db
            ->query(
                <<<'SQL'
SELECT
    `id`,
    `user_id`
FROM `tenant_users`
WHERE `tenant_id` = ?
  AND `status` = 'active'
  AND `is_owner` = 1
ORDER BY `id`
FOR UPDATE
SQL,
                [$tenantId]
            )
            ->getResultArray();

        foreach ($rows as $row) {
            if (
                (int) $row['user_id']
                !== $excludedUserId
            ) {
                return;
            }
        }

        throw new RuntimeException(
            'The last active tenant owner cannot be removed or disabled.'
        );
    }

    private function assertPositiveId(
        int $id,
        string $field
    ): void {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                $field . ' must be positive.'
            );
        }
    }

    private function auditLockName(int $tenantId): string
    {
        return 'civic_audit_tenant_' . $tenantId;
    }

    private function acquireAuditLock(string $lockName): void
    {
        $row = $this->db
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [
                    $lockName,
                    self::AUDIT_LOCK_TIMEOUT,
                ]
            )
            ->getFirstRow('array');

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire the audit transaction lock.'
            );
        }
    }

    private function releaseAuditLock(string $lockName): void
    {
        $this->db->query(
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
    }

    private function beginTransaction(): void
    {
        if (! $this->db->transBegin()) {
            throw new RuntimeException(
                'Could not start tenant-user transaction.'
            );
        }
    }

    private function commitOrFail(): void
    {
        if (! $this->db->transStatus()) {
            throw new RuntimeException(
                'Tenant-user transaction failed.'
            );
        }

        if (! $this->db->transCommit()) {
            throw new RuntimeException(
                'Could not commit tenant-user transaction.'
            );
        }
    }

    private function rollbackIfNeeded(): void
    {
        $this->db->transRollback();
    }
}
