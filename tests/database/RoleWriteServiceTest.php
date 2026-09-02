<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\RoleWriteService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RoleWriteServiceTest extends CIUnitTestCase
{
    private const PERMISSION_MARKER =
        'PHPUnit RoleWriteServiceTest';

    private int $tenantA;
    private int $tenantB;

    private int $actorUser;
    private int $unauthorizedUser;
    private int $targetUser;
    private int $foreignUser;

    private int $roleA;
    private int $foreignRole;
    private int $systemRole;

    private int $permissionAlpha;
    private int $permissionBeta;

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

    public function testCreateRoleIsTenantScopedAndAudited(): void
    {
        $roleId = $this->service()->createRole(
            $this->actorUser,
            'Field-Supervisor',
            'Superviseur terrain',
            'Rôle de test'
        );

        $role = $this->role($roleId);

        $this->assertNotNull($role);
        $this->assertSame(
            $this->tenantA,
            (int) $role['tenant_id']
        );
        $this->assertSame(
            'field-supervisor',
            $role['code']
        );
        $this->assertSame(
            'Superviseur terrain',
            $role['name']
        );
        $this->assertSame(
            0,
            (int) $role['is_system']
        );

        $audit = $this->auditRow('role.created');

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
            (string) $roleId,
            $audit['entity_id']
        );

        $verification = $this->auditService()
            ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(1, $verification['count']);
    }

    public function testUnauthorizedActorCannotCreateRole(): void
    {
        $exception = $this->captureException(
            fn () => $this->service()->createRole(
                $this->unauthorizedUser,
                'forbidden-role',
                'Forbidden Role'
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
            $this->roleByCode(
                $this->tenantA,
                'forbidden-role'
            )
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testForeignTenantRoleCannotBeUpdated(): void
    {
        $exception = $this->captureException(
            fn () => $this->service()->updateRole(
                $this->actorUser,
                $this->foreignRole,
                'Tentative interdite'
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'Role does not belong to the current tenant.',
            $exception->getMessage()
        );

        $foreign = $this->role($this->foreignRole);

        $this->assertNotNull($foreign);
        $this->assertSame(
            $this->tenantB,
            (int) $foreign['tenant_id']
        );
        $this->assertSame(
            'Foreign Role',
            $foreign['name']
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testSystemRoleCannotBeModified(): void
    {
        $updateException = $this->captureException(
            fn () => $this->service()->updateRole(
                $this->actorUser,
                $this->systemRole,
                'Modified System Role'
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $updateException
        );

        $this->assertSame(
            'System roles cannot be modified by this service.',
            $updateException->getMessage()
        );

        $permissionException = $this->captureException(
            fn () => $this->service()->setPermissions(
                $this->actorUser,
                $this->systemRole,
                ['test.role.alpha']
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $permissionException
        );

        $this->assertSame(
            'System roles cannot be modified by this service.',
            $permissionException->getMessage()
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testSetPermissionsReplacesAndAudits(): void
    {
        $this->db->table('role_permissions')->insert([
            'role_id'       => $this->roleA,
            'permission_id' => $this->permissionAlpha,
        ]);

        /*
         * Le doublon teste aussi la canonicalisation
         * de la liste d'entrée.
         */
        $this->service()->setPermissions(
            $this->actorUser,
            $this->roleA,
            [
                'test.role.beta',
                'test.role.beta',
            ]
        );

        $this->assertSame(
            ['test.role.beta'],
            $this->permissionCodesForRole(
                $this->roleA
            )
        );

        $audit = $this->auditRow(
            'role.permissions_changed'
        );

        $this->assertNotNull($audit);

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            ['test.role.alpha'],
            $context['old_permissions']
        );

        $this->assertSame(
            ['test.role.beta'],
            $context['new_permissions']
        );

        $verification = $this->auditService()
            ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
    }

    public function testAssignAndRemoveRoleAreAudited(): void
    {
        $this->service()->assignToUser(
            $this->actorUser,
            $this->roleA,
            $this->targetUser
        );

        $this->assertTrue(
            $this->userHasRole(
                $this->tenantA,
                $this->targetUser,
                $this->roleA
            )
        );

        $assigned = $this->auditRow(
            'role.user_assigned'
        );

        $this->assertNotNull($assigned);

        $assignedContext = json_decode(
            $assigned['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            $this->targetUser,
            $assignedContext['target_user_id']
        );

        $this->service()->removeFromUser(
            $this->actorUser,
            $this->roleA,
            $this->targetUser
        );

        $this->assertFalse(
            $this->userHasRole(
                $this->tenantA,
                $this->targetUser,
                $this->roleA
            )
        );

        $removed = $this->auditRow(
            'role.user_removed'
        );

        $this->assertNotNull($removed);

        $verification = $this->auditService()
            ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(
            2,
            $verification['count']
        );
    }

    public function testForeignTenantUserCannotReceiveRole(): void
    {
        $exception = $this->captureException(
            fn () => $this->service()->assignToUser(
                $this->actorUser,
                $this->roleA,
                $this->foreignUser
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'Target user is not an active member '
            . 'of the current tenant.',
            $exception->getMessage()
        );

        $this->assertFalse(
            $this->userHasRole(
                $this->tenantA,
                $this->foreignUser,
                $this->roleA
            )
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testBusinessWriteRollsBackWhenAuditFails(): void
    {
        $this->db->table('audit_logs')->insert([
            'tenant_id'    => $this->tenantA,
            'actor_type'   => 'system',
            'event'        => 'broken.role.audit',
            'context_json' => '{}',
            'entry_hash'   => 'broken',
            'occurred_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $exception = $this->captureException(
            fn () => $this->service()->createRole(
                $this->actorUser,
                'rollback-role',
                'Rollback Role'
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
         * Le rôle a été INSERT avant l'audit,
         * mais doit disparaître avec le rollback.
         */
        $this->assertNull(
            $this->roleByCode(
                $this->tenantA,
                'rollback-role'
            )
        );

        /*
         * Seule l'entrée cassée préexistante subsiste.
         */
        $this->assertSame(1, $this->auditCount());
    }

    private function service(): RoleWriteService
    {
        return new RoleWriteService(
            (new TenantContext())->set($this->tenantA),
            $this->db
        );
    }

    private function auditService(): AuditService
    {
        return new AuditService(
            (new TenantContext())->set($this->tenantA),
            $this->db
        );
    }

    private function role(int $roleId): ?array
    {
        return $this->db
            ->table('roles')
            ->where('id', $roleId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function roleByCode(
        int $tenantId,
        string $code
    ): ?array {
        return $this->db
            ->table('roles')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function permissionCodesForRole(
        int $roleId
    ): array {
        $rows = $this->db
            ->table('role_permissions rp')
            ->select('p.code')
            ->join(
                'permissions p',
                'p.id = rp.permission_id'
            )
            ->where('rp.role_id', $roleId)
            ->orderBy('p.code', 'ASC')
            ->get()
            ->getResultArray();

        return array_column($rows, 'code');
    }

    private function userHasRole(
        int $tenantId,
        int $userId,
        int $roleId
    ): bool {
        $row = $this->db
            ->table('user_roles')
            ->select('role_id')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        return $row !== null;
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
        $this->tenantA = $this->insertTenant(
            '__role_write_tenant_a__',
            'Role Write Tenant A'
        );

        $this->tenantB = $this->insertTenant(
            '__role_write_tenant_b__',
            'Role Write Tenant B'
        );

        $this->actorUser = $this->insertUser(
            'role-write-actor@invalid.example',
            'Role Write Actor'
        );

        $this->unauthorizedUser = $this->insertUser(
            'role-write-unauthorized@invalid.example',
            'Role Write Unauthorized'
        );

        $this->targetUser = $this->insertUser(
            'role-write-target@invalid.example',
            'Role Write Target'
        );

        $this->foreignUser = $this->insertUser(
            'role-write-foreign@invalid.example',
            'Role Write Foreign'
        );

        $this->insertMembership(
            $this->tenantA,
            $this->actorUser
        );

        $this->insertMembership(
            $this->tenantA,
            $this->unauthorizedUser
        );

        $this->insertMembership(
            $this->tenantA,
            $this->targetUser
        );

        $this->insertMembership(
            $this->tenantB,
            $this->foreignUser
        );

        $rolesManage = $this->ensurePermission(
            'roles.manage',
            'Administrer les rôles'
        );

        $this->permissionAlpha = $this->ensurePermission(
            'test.role.alpha',
            'Permission test rôle alpha'
        );

        $this->permissionBeta = $this->ensurePermission(
            'test.role.beta',
            'Permission test rôle beta'
        );

        /*
         * Rôle donnant roles.manage à l'acteur.
         */
        $managerRole = $this->insertRole(
            $this->tenantA,
            'role-write-manager',
            'Role Write Manager'
        );

        $this->db->table('role_permissions')->insert([
            'role_id'       => $managerRole,
            'permission_id' => $rolesManage,
        ]);

        $this->db->table('user_roles')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->actorUser,
            'role_id'   => $managerRole,
        ]);

        $this->roleA = $this->insertRole(
            $this->tenantA,
            'role-write-target',
            'Role Write Target'
        );

        $this->foreignRole = $this->insertRole(
            $this->tenantB,
            'role-write-foreign',
            'Foreign Role'
        );

        $this->systemRole = $this->insertRole(
            $this->tenantA,
            'role-write-system',
            'System Role',
            true
        );
    }

    private function insertTenant(
        string $slug,
        string $name
    ): int {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => $slug,
            'name' => $name,
        ]);

        return (int) $this->db->insertID();
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

    private function insertMembership(
        int $tenantId,
        int $userId
    ): void {
        $this->db->table('tenant_users')->insert([
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
            'status'    => 'active',
        ]);
    }

    private function insertRole(
        int $tenantId,
        string $code,
        string $name,
        bool $isSystem = false
    ): int {
        $this->db->table('roles')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $tenantId,
            'code'      => $code,
            'name'      => $name,
            'is_system' => $isSystem ? 1 : 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function ensurePermission(
        string $code,
        string $name
    ): int {
        $existing = $this->db
            ->table('permissions')
            ->select('id')
            ->where('code', $code)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $this->db->table('permissions')->insert([
            'code'        => $code,
            'name'        => $name,
            'description' => self::PERMISSION_MARKER,
            'domain'      => 'test',
        ]);

        return (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        /*
         * audit_logs est append-only pour le runtime.
         */
        $cleanupDb = $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        /*
         * Les cascades suppriment tenant_users,
         * roles, role_permissions et user_roles.
         */
        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__role_write_tenant_a__',
                '__role_write_tenant_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'role-write-actor@invalid.example',
                'role-write-unauthorized@invalid.example',
                'role-write-target@invalid.example',
                'role-write-foreign@invalid.example'
             )"
        );

        /*
         * Ne jamais supprimer une permission de catalogue
         * préexistante. Seulement celles créées par ce test.
         */
        $this->db
            ->table('permissions')
            ->where(
                'description',
                self::PERMISSION_MARKER
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
