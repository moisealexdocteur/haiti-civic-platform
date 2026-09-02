<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\TenantContext;
use App\Services\TenantModuleWriteService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TenantModuleWriteServiceTest extends CIUnitTestCase
{
    private const PERMISSION_MARKER =
        'PHPUnit TenantModuleWriteServiceTest permission';

    private const MODULE_MARKER =
        'PHPUnit TenantModuleWriteServiceTest module';

    private int $tenantA;
    private int $tenantB;
    private int $actorUser;
    private int $unauthorizedUser;

    private int $moduleAlpha;
    private int $moduleBeta;
    private int $moduleCore;

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

    public function testEnableCreatesScopedRegistrationAndAudit(): void
    {
        /*
         * Tenant B possède déjà le même module global.
         * L'écriture du tenant A ne doit pas le modifier.
         */
        $this->insertTenantModule(
            $this->tenantB,
            $this->moduleAlpha,
            false,
            '{"foreign":true}'
        );

        $this->service()->setEnabled(
            $this->actorUser,
            'test.module.alpha',
            true
        );

        $tenantA = $this->tenantModule(
            $this->tenantA,
            $this->moduleAlpha
        );

        $tenantB = $this->tenantModule(
            $this->tenantB,
            $this->moduleAlpha
        );

        $this->assertNotNull($tenantA);
        $this->assertSame(
            1,
            (int) $tenantA['enabled']
        );
        $this->assertNotNull(
            $tenantA['activated_at']
        );

        $this->assertNotNull($tenantB);
        $this->assertSame(
            0,
            (int) $tenantB['enabled']
        );
        $this->assertSame(
            '{"foreign":true}',
            $tenantB['config_json']
        );

        $audit = $this->auditRow(
            'tenant_module.enabled_changed'
        );

        $this->assertNotNull($audit);
        $this->assertSame(
            $this->tenantA,
            (int) $audit['tenant_id']
        );

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            'test.module.alpha',
            $context['module_code']
        );
        $this->assertNull(
            $context['old_enabled']
        );
        $this->assertTrue(
            $context['new_enabled']
        );

        $verification = $this->auditService()
            ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(
            1,
            $verification['count']
        );
    }

    public function testUnauthorizedActorCannotEnableModule(): void
    {
        $exception = $this->captureException(
            fn () => $this->service()->setEnabled(
                $this->unauthorizedUser,
                'test.module.alpha',
                true
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
            $this->tenantModule(
                $this->tenantA,
                $this->moduleAlpha
            )
        );

        $this->assertSame(
            0,
            $this->auditCount()
        );
    }

    public function testCoreModuleCannotBeDisabled(): void
    {
        $this->insertTenantModule(
            $this->tenantA,
            $this->moduleCore,
            true
        );

        $exception = $this->captureException(
            fn () => $this->service()->setEnabled(
                $this->actorUser,
                'test.module.core',
                false
            )
        );

        $this->assertInstanceOf(
            RuntimeException::class,
            $exception
        );

        $this->assertSame(
            'Core modules cannot be disabled.',
            $exception->getMessage()
        );

        $row = $this->tenantModule(
            $this->tenantA,
            $this->moduleCore
        );

        $this->assertNotNull($row);
        $this->assertSame(
            1,
            (int) $row['enabled']
        );

        $this->assertSame(
            0,
            $this->auditCount()
        );
    }

    public function testConfigIsCanonicalAndAuditContainsOnlyHashes(): void
    {
        $this->insertTenantModule(
            $this->tenantA,
            $this->moduleAlpha,
            true,
            '{"a":1}'
        );

        $config = [
            'z' => 2,
            'secret' => 'do-not-store-in-audit',
            'nested' => [
                'b' => 2,
                'a' => 1,
            ],
        ];

        $this->service()->setConfig(
            $this->actorUser,
            'test.module.alpha',
            $config
        );

        $row = $this->tenantModule(
            $this->tenantA,
            $this->moduleAlpha
        );

        $this->assertNotNull($row);

        $expectedJson =
            '{"nested":{"a":1,"b":2},'
            . '"secret":"do-not-store-in-audit",'
            . '"z":2}';

        $this->assertSame(
            $expectedJson,
            $row['config_json']
        );

        $audit = $this->auditRow(
            'tenant_module.config_changed'
        );

        $this->assertNotNull($audit);

        /*
         * Le journal ne doit pas contenir le secret
         * ni la configuration brute.
         */
        $this->assertStringNotContainsString(
            'do-not-store-in-audit',
            (string) $audit['context_json']
        );

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            hash('sha256', '{"a":1}'),
            $context['old_config_hash']
        );

        $this->assertSame(
            hash('sha256', $expectedJson),
            $context['new_config_hash']
        );

        $this->assertArrayNotHasKey(
            'config',
            $context
        );
    }

    public function testForeignTenantRegistrationDoesNotAuthorizeConfig(): void
    {
        /*
         * Le module est enregistré uniquement chez tenant B.
         */
        $this->insertTenantModule(
            $this->tenantB,
            $this->moduleAlpha,
            true,
            '{"tenant":"b"}'
        );

        $exception = $this->captureException(
            fn () => $this->service()->setConfig(
                $this->actorUser,
                'test.module.alpha',
                ['tenant' => 'a']
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'Module has not been registered '
            . 'for the current tenant.',
            $exception->getMessage()
        );

        $foreign = $this->tenantModule(
            $this->tenantB,
            $this->moduleAlpha
        );

        $this->assertNotNull($foreign);
        $this->assertSame(
            '{"tenant":"b"}',
            $foreign['config_json']
        );

        $this->assertSame(
            0,
            $this->auditCount()
        );
    }

    public function testLicenseWindowIsStoredAndAudited(): void
    {
        $this->insertTenantModule(
            $this->tenantA,
            $this->moduleBeta,
            true
        );

        $this->service()->setLicenseWindow(
            $this->actorUser,
            'test.module.beta',
            '2026-09-01 00:00:00',
            '2027-08-31 23:59:59'
        );

        $row = $this->tenantModule(
            $this->tenantA,
            $this->moduleBeta
        );

        $this->assertNotNull($row);

        $this->assertSame(
            '2026-09-01 00:00:00',
            $row['license_start_at']
        );

        $this->assertSame(
            '2027-08-31 23:59:59',
            $row['license_end_at']
        );

        $audit = $this->auditRow(
            'tenant_module.license_changed'
        );

        $this->assertNotNull($audit);

        $context = json_decode(
            $audit['context_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(
            '2026-09-01 00:00:00',
            $context['new_license_start_at']
        );

        $this->assertSame(
            '2027-08-31 23:59:59',
            $context['new_license_end_at']
        );

        $verification = $this->auditService()
            ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
    }

    public function testInvalidLicenseWindowDoesNotWriteOrAudit(): void
    {
        $this->insertTenantModule(
            $this->tenantA,
            $this->moduleBeta,
            true
        );

        $exception = $this->captureException(
            fn () => $this->service()->setLicenseWindow(
                $this->actorUser,
                'test.module.beta',
                '2027-01-01 00:00:00',
                '2026-12-31 23:59:59'
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );

        $this->assertSame(
            'License end must not precede license start.',
            $exception->getMessage()
        );

        $row = $this->tenantModule(
            $this->tenantA,
            $this->moduleBeta
        );

        $this->assertNotNull($row);
        $this->assertNull(
            $row['license_start_at']
        );
        $this->assertNull(
            $row['license_end_at']
        );

        $this->assertSame(
            0,
            $this->auditCount()
        );
    }

    public function testBusinessWriteRollsBackWhenAuditFails(): void
    {
        $this->db->table('audit_logs')->insert([
            'tenant_id'    => $this->tenantA,
            'actor_type'   => 'system',
            'event'        => 'broken.module.audit',
            'context_json' => '{}',
            'entry_hash'   => 'broken',
            'occurred_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $exception = $this->captureException(
            fn () => $this->service()->setEnabled(
                $this->actorUser,
                'test.module.alpha',
                true
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
         * L'INSERT tenant_modules précède l'audit,
         * mais doit avoir été annulé.
         */
        $this->assertNull(
            $this->tenantModule(
                $this->tenantA,
                $this->moduleAlpha
            )
        );

        /*
         * Seule l'entrée invalide préexistante reste.
         */
        $this->assertSame(
            1,
            $this->auditCount()
        );
    }

    private function service(): TenantModuleWriteService
    {
        return new TenantModuleWriteService(
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

    private function tenantModule(
        int $tenantId,
        int $moduleId
    ): ?array {
        return $this->db
            ->table('tenant_modules')
            ->where('tenant_id', $tenantId)
            ->where('module_id', $moduleId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function insertTenantModule(
        int $tenantId,
        int $moduleId,
        bool $enabled,
        ?string $configJson = null
    ): void {
        $this->db->table('tenant_modules')->insert([
            'tenant_id'   => $tenantId,
            'module_id'   => $moduleId,
            'enabled'     => $enabled ? 1 : 0,
            'config_json' => $configJson,
            'activated_at' => $enabled
                ? gmdate('Y-m-d H:i:s')
                : null,
        ]);
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

    private function createFixtures(): void
    {
        $this->tenantA = $this->insertTenant(
            '__module_write_tenant_a__',
            'Module Write Tenant A'
        );

        $this->tenantB = $this->insertTenant(
            '__module_write_tenant_b__',
            'Module Write Tenant B'
        );

        $this->actorUser = $this->insertUser(
            'module-write-actor@invalid.example',
            'Module Write Actor'
        );

        $this->unauthorizedUser = $this->insertUser(
            'module-write-unauthorized@invalid.example',
            'Module Write Unauthorized'
        );

        $this->insertMembership(
            $this->tenantA,
            $this->actorUser
        );

        $this->insertMembership(
            $this->tenantA,
            $this->unauthorizedUser
        );

        $permissionId = $this->ensurePermission(
            'modules.manage',
            'Administrer les modules'
        );

        $managerRole = $this->insertRole(
            $this->tenantA,
            '__module_write_manager__',
            'Module Write Manager'
        );

        $this->db->table('role_permissions')->insert([
            'role_id'       => $managerRole,
            'permission_id' => $permissionId,
        ]);

        $this->db->table('user_roles')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->actorUser,
            'role_id'   => $managerRole,
        ]);

        $this->moduleAlpha = $this->insertModule(
            'test.module.alpha',
            'Test Module Alpha'
        );

        $this->moduleBeta = $this->insertModule(
            'test.module.beta',
            'Test Module Beta'
        );

        $this->moduleCore = $this->insertModule(
            'test.module.core',
            'Test Core Module',
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
        string $name
    ): int {
        $this->db->table('roles')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $tenantId,
            'code'      => $code,
            'name'      => $name,
            'is_system' => 0,
        ]);

        return (int) $this->db->insertID();
    }

    private function insertModule(
        string $code,
        string $name,
        bool $isCore = false
    ): int {
        $this->db->table('modules')->insert([
            'code'               => $code,
            'name'               => $name,
            'description'        => self::MODULE_MARKER,
            'version'            => '1.0.0-test',
            'is_core'            => $isCore ? 1 : 0,
            'enabled_by_default' => 0,
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

    private function cleanupFixtures(): void
    {
        $cleanupDb = $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        /*
         * Cascade : tenant_users, roles, user_roles,
         * role_permissions et tenant_modules.
         */
        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__module_write_tenant_a__',
                '__module_write_tenant_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'module-write-actor@invalid.example',
                'module-write-unauthorized@invalid.example'
             )"
        );

        /*
         * Seulement les modules créés par ce test.
         */
        $this->db
            ->table('modules')
            ->where(
                'description',
                self::MODULE_MARKER
            )
            ->delete();

        /*
         * Ne jamais supprimer modules.manage si elle
         * appartenait déjà au catalogue.
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
