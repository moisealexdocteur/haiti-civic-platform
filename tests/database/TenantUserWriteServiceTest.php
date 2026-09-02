<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\TenantContext;
use App\Services\TenantUserWriteService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TenantUserWriteServiceTest extends CIUnitTestCase
{
    private int $tenantA;
    private int $tenantB;
    private int $actorUser;
    private int $unauthorizedUser;
    private int $targetUser;
    private int $foreignUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();

        $this->cleanupFixtures();
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function testAddMemberCreatesScopedMembershipAndAudit(): void
    {
        $membershipId = $this->service()->addMember(
            $this->actorUser,
            $this->targetUser
        );

        $membership = $this->membership(
            $this->tenantA,
            $this->targetUser
        );

        $this->assertNotNull($membership);
        $this->assertSame(
            $membershipId,
            (int) $membership['id']
        );
        $this->assertSame('active', $membership['status']);
        $this->assertSame(0, (int) $membership['is_owner']);

        $audit = $this->auditRow('tenant_user.created');

        $this->assertNotNull($audit);
        $this->assertSame(
            $this->tenantA,
            (int) $audit['tenant_id']
        );
        $this->assertSame(
            $this->actorUser,
            (int) $audit['actor_user_id']
        );
        $this->assertSame(
            (string) $membershipId,
            $audit['entity_id']
        );

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $this->targetUser,
            $context['target_user_id']
        );
        $this->assertSame('active', $context['status']);
        $this->assertFalse($context['is_owner']);

        $verification = (
            new AuditService(
                (new TenantContext())->set($this->tenantA),
                $this->db
            )
        )->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(1, $verification['count']);
    }

    public function testUnauthorizedActorCannotAddMember(): void
    {
        $exception = $this->captureException(
            fn () => $this->service()->addMember(
                $this->unauthorizedUser,
                $this->targetUser
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            'Actor is not authorized for this operation.',
            $exception->getMessage()
        );

        $this->assertNull(
            $this->membership(
                $this->tenantA,
                $this->targetUser
            )
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testForeignTenantMembershipCannotBeChanged(): void
    {
        $exception = $this->captureException(
            fn () => $this->service()->setStatus(
                $this->actorUser,
                $this->foreignUser,
                'inactive'
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'User does not belong to the current tenant.',
            $exception->getMessage()
        );

        $foreignMembership = $this->membership(
            $this->tenantB,
            $this->foreignUser
        );

        $this->assertNotNull($foreignMembership);
        $this->assertSame(
            'active',
            $foreignMembership['status']
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testLastActiveOwnerCannotBeDisabledOrDemoted(): void
    {
        $statusException = $this->captureException(
            fn () => $this->service()->setStatus(
                $this->actorUser,
                $this->actorUser,
                'inactive'
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $statusException
        );

        $ownerException = $this->captureException(
            fn () => $this->service()->setOwner(
                $this->actorUser,
                $this->actorUser,
                false
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $ownerException
        );

        $membership = $this->membership(
            $this->tenantA,
            $this->actorUser
        );

        $this->assertNotNull($membership);
        $this->assertSame('active', $membership['status']);
        $this->assertSame(1, (int) $membership['is_owner']);
        $this->assertSame(0, $this->auditCount());
    }

    public function testStatusChangeIsScopedAndAudited(): void
    {
        $this->insertMembership(
            $this->tenantA,
            $this->targetUser
        );

        $this->service()->setStatus(
            $this->actorUser,
            $this->targetUser,
            'inactive'
        );

        $membership = $this->membership(
            $this->tenantA,
            $this->targetUser
        );

        $this->assertNotNull($membership);
        $this->assertSame(
            'inactive',
            $membership['status']
        );

        $audit = $this->auditRow(
            'tenant_user.status_changed'
        );

        $this->assertNotNull($audit);

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $this->targetUser,
            $context['target_user_id']
        );
        $this->assertSame(
            'active',
            $context['old_status']
        );
        $this->assertSame(
            'inactive',
            $context['new_status']
        );
    }

    public function testOwnerChangeIsAudited(): void
    {
        $this->insertMembership(
            $this->tenantA,
            $this->targetUser
        );

        $this->service()->setOwner(
            $this->actorUser,
            $this->targetUser,
            true
        );

        $membership = $this->membership(
            $this->tenantA,
            $this->targetUser
        );

        $this->assertNotNull($membership);
        $this->assertSame(
            1,
            (int) $membership['is_owner']
        );

        $audit = $this->auditRow(
            'tenant_user.owner_changed'
        );

        $this->assertNotNull($audit);

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $this->targetUser,
            $context['target_user_id']
        );
        $this->assertFalse(
            $context['old_is_owner']
        );
        $this->assertTrue(
            $context['new_is_owner']
        );
    }

    public function testBusinessWriteRollsBackWhenAuditAppendFails(): void
    {
        /*
         * Injection volontaire d'une extrémité de chaîne invalide.
         * AuditService refusera d'ajouter une nouvelle entrée.
         */
        $this->db->table('audit_logs')->insert([
            'tenant_id'    => $this->tenantA,
            'actor_type'   => 'system',
            'event'        => 'broken.audit.chain',
            'context_json' => '{}',
            'entry_hash'   => 'broken',
            'occurred_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $exception = $this->captureException(
            fn () => $this->service()->addMember(
                $this->actorUser,
                $this->targetUser
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            'Cannot append to an invalid audit chain.',
            $exception->getMessage()
        );

        /*
         * L'écriture métier doit avoir été rollbackée.
         */
        $this->assertNull(
            $this->membership(
                $this->tenantA,
                $this->targetUser
            )
        );

        /*
         * Seule l'entrée invalide préexistante subsiste.
         */
        $this->assertSame(1, $this->auditCount());
    }

    private function service(): TenantUserWriteService
    {
        return new TenantUserWriteService(
            (new TenantContext())->set($this->tenantA),
            $this->db
        );
    }

    private function membership(
        int $tenantId,
        int $userId
    ): ?array {
        return $this->db
            ->table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function insertMembership(
        int $tenantId,
        int $userId,
        bool $isOwner = false
    ): int {
        $this->db->table('tenant_users')->insert([
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
            'status'    => 'active',
            'is_owner'  => $isOwner ? 1 : 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function auditRow(string $event): ?array
    {
        return $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('event', $event)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function auditCount(): int
    {
        return $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->countAllResults();
    }

    private function captureException(
        callable $callback
    ): Throwable {
        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail(
            'Expected operation to throw an exception.'
        );
    }

    private function createFixtures(): void
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__tenant_user_write_a__',
            'name' => 'Tenant User Write A',
        ]);

        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__tenant_user_write_b__',
            'name' => 'Tenant User Write B',
        ]);

        $this->tenantB = (int) $this->db->insertID();

        $this->actorUser = $this->insertUser(
            'tenant-user-actor@invalid.example',
            'Tenant User Actor'
        );

        $this->unauthorizedUser = $this->insertUser(
            'tenant-user-unauthorized@invalid.example',
            'Tenant User Unauthorized'
        );

        $this->targetUser = $this->insertUser(
            'tenant-user-target@invalid.example',
            'Tenant User Target'
        );

        $this->foreignUser = $this->insertUser(
            'tenant-user-foreign@invalid.example',
            'Tenant User Foreign'
        );

        $this->insertMembership(
            $this->tenantA,
            $this->actorUser,
            true
        );

        $this->insertMembership(
            $this->tenantA,
            $this->unauthorizedUser
        );

        $this->insertMembership(
            $this->tenantB,
            $this->foreignUser,
            true
        );

        $permission = $this->db
            ->table('permissions')
            ->select('id')
            ->where('code', 'users.manage')
            ->get()
            ->getFirstRow('array');

        if ($permission === null) {
            $inserted = $this->db
                ->table('permissions')
                ->insert([
                    'code'   => 'users.manage',
                    'name'   => '__tenant_user_test_permission__',
                    'domain' => 'core',
                ]);

            if (! $inserted) {
                throw new RuntimeException(
                    'Could not create users.manage test permission.'
                );
            }

            $permissionId = (int) $this->db->insertID();
        } else {
            $permissionId = (int) $permission['id'];
        }

        $this->db->table('roles')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantA,
            'code'      => '__tenant_user_manager__',
            'name'      => 'Tenant User Manager',
        ]);

        $roleId = (int) $this->db->insertID();

        $this->db->table('role_permissions')->insert([
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
        ]);

        $this->db->table('user_roles')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->actorUser,
            'role_id'   => $roleId,
        ]);
    }

    private function insertUser(
        string $email,
        string $displayName
    ): int {
        $this->db->table('users')->insert([
            'uuid'         => $this->uuid(),
            'email'        => $email,
            'display_name' => $displayName,
            'status'       => 'active',
        ]);

        return (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        /*
         * audit_logs est append-only pour le runtime.
         * Le nettoyage PHPUnit utilise donc uniquement
         * le compte migrateur de la base de test.
         */
        $cleanupDb = $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        /*
         * Suppression des tenants :
         * cascade sur tenant_users, roles, user_roles,
         * role_permissions et tenant_modules.
         */
        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__tenant_user_write_a__',
                '__tenant_user_write_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'tenant-user-actor@invalid.example',
                'tenant-user-unauthorized@invalid.example',
                'tenant-user-target@invalid.example',
                'tenant-user-foreign@invalid.example'
             )"
        );

        /*
         * Ne supprimer que la permission éventuellement créée
         * par ce fixture. Une permission canonique users.manage
         * déjà présente doit survivre au test.
         */
        $this->db
            ->table('permissions')
            ->where('code', 'users.manage')
            ->where(
                'name',
                '__tenant_user_test_permission__'
            )
            ->delete();
    }

    private function privilegedCleanupConnection(): \mysqli
    {
        $username = getenv('MIGRATION_DB_USERNAME');
        $password = getenv('MIGRATION_DB_PASSWORD');
        $database = getenv('TEST_DATABASE');

        if (
            ! is_string($username)
            || $username === ''
            || ! is_string($password)
            || $password === ''
            || ! is_string($database)
            || $database === ''
        ) {
            throw new RuntimeException(
                'Privileged test DB credentials are missing.'
            );
        }

        mysqli_report(
            MYSQLI_REPORT_ERROR
            | MYSQLI_REPORT_STRICT
        );

        return new \mysqli(
            'db',
            $username,
            $password,
            $database,
            3306
        );
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
