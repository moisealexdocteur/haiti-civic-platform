<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\OrganizationWriteService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;

final class OrganizationWriteServiceTest extends CIUnitTestCase
{
    private int $tenantA;
    private int $tenantB;
    private int $actorUserId;
    private int $unauthorizedUserId;
    private int $foreignOrganizationId;
    private int $permissionId;
    private int $roleId;

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

    public function testCreateRequiresManagePermission(): void
    {
        $service = $this->service();

        try {
            $service->create(
                $this->unauthorizedUserId,
                'Unauthorized Organization',
                '__write_unauthorized__'
            );

            $this->fail(
                'Unauthorized organization creation succeeded.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Permission denied: organizations.manage',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            $this->organizationCountByCode(
                '__write_unauthorized__'
            )
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testCreateUsesCurrentTenantAndAppendsAudit(): void
    {
        $service = $this->service();

        $organizationId = $service->create(
            $this->actorUserId,
            'Created Organization',
            '__write_created__',
            'organization',
            'Created Organization Legal'
        );

        $row = $this->db
            ->table('organizations')
            ->where('id', $organizationId)
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($row);

        $this->assertSame(
            $this->tenantA,
            (int) $row['tenant_id']
        );

        $this->assertSame(
            '__write_created__',
            $row['code']
        );

        $audit = $this->latestAuditRow();

        $this->assertSame(
            'organization.created',
            $audit['event']
        );

        $this->assertSame(
            (string) $organizationId,
            $audit['entity_id']
        );

        $verification =
            $this->auditService()
                ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(1, $verification['count']);
    }

    public function testForeignTenantOrganizationCannotBeUpdated(): void
    {
        $service = $this->service();

        try {
            $service->update(
                $this->actorUserId,
                $this->foreignOrganizationId,
                ['name' => 'Cross Tenant Mutation']
            );

            $this->fail(
                'Cross-tenant organization update succeeded.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Organization does not exist '
                . 'in the current tenant.',
                $exception->getMessage()
            );
        }

        $row = $this->db
            ->table('organizations')
            ->select('name')
            ->where('id', $this->foreignOrganizationId)
            ->get()
            ->getFirstRow('array');

        $this->assertSame(
            'Foreign Organization',
            $row['name']
        );

        $this->assertSame(0, $this->auditCount());
    }

    public function testUpdateAndArchiveAreAudited(): void
    {
        $service = $this->service();

        $organizationId = $service->create(
            $this->actorUserId,
            'Lifecycle Organization',
            '__write_lifecycle__'
        );

        $service->update(
            $this->actorUserId,
            $organizationId,
            [
                'name'       => 'Lifecycle Organization Updated',
                'legal_name' => 'Lifecycle Legal Name',
            ]
        );

        $service->archive(
            $this->actorUserId,
            $organizationId
        );

        $row = $this->db
            ->table('organizations')
            ->where('id', $organizationId)
            ->get()
            ->getFirstRow('array');

        $this->assertSame(
            'Lifecycle Organization Updated',
            $row['name']
        );

        $this->assertSame(
            'inactive',
            $row['status']
        );

        $this->assertNotNull($row['deleted_at']);

        $events = array_column(
            $this->db
                ->table('audit_logs')
                ->select('event')
                ->where('tenant_id', $this->tenantA)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray(),
            'event'
        );

        $this->assertSame(
            [
                'organization.created',
                'organization.updated',
                'organization.archived',
            ],
            $events
        );

        $verification =
            $this->auditService()
                ->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(3, $verification['count']);
    }

    public function testAuditFailureRollsBackBusinessWrite(): void
    {
        $this->db->table('audit_logs')->insert([
            'tenant_id'     => $this->tenantA,
            'actor_user_id' => $this->actorUserId,
            'actor_type'    => 'user',
            'event'         => '__broken_chain_fixture__',
            'entry_hash'    => str_repeat('x', 64),
        ]);

        $service = $this->service();

        try {
            $service->create(
                $this->actorUserId,
                'Must Roll Back',
                '__write_rollback__'
            );

            $this->fail(
                'Business write survived audit failure.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot append to an invalid audit chain.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            0,
            $this->organizationCountByCode(
                '__write_rollback__'
            )
        );

        $this->assertSame(1, $this->auditCount());
    }

    public function testUpdateRejectsUnsupportedFields(): void
    {
        $service = $this->service();

        $organizationId = $service->create(
            $this->actorUserId,
            'Whitelisted Organization',
            '__write_whitelist__'
        );

        try {
            $service->update(
                $this->actorUserId,
                $organizationId,
                [
                    'tenant_id' => $this->tenantB,
                ]
            );

            $this->fail(
                'Unsupported organization field was accepted.'
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Unsupported organization field: tenant_id',
                $exception->getMessage()
            );
        }

        $row = $this->db
            ->table('organizations')
            ->select('tenant_id')
            ->where('id', $organizationId)
            ->get()
            ->getFirstRow('array');

        $this->assertSame(
            $this->tenantA,
            (int) $row['tenant_id']
        );

        $this->assertSame(1, $this->auditCount());
    }

    private function service(): OrganizationWriteService
    {
        $context = (new TenantContext())
            ->set($this->tenantA);

        $authorization = new AuthorizationService(
            $context,
            $this->db
        );

        $audit = new AuditService(
            $context,
            $this->db
        );

        return new OrganizationWriteService(
            $context,
            $authorization,
            $audit,
            $this->db
        );
    }

    private function auditService(): AuditService
    {
        $context = (new TenantContext())
            ->set($this->tenantA);

        return new AuditService(
            $context,
            $this->db
        );
    }

    private function createFixtures(): void
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__write_tenant_a__',
            'name' => 'Write Tenant A',
        ]);

        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__write_tenant_b__',
            'name' => 'Write Tenant B',
        ]);

        $this->tenantB = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'uuid'         => $this->uuid(),
            'email'        => 'write-actor@invalid.example',
            'display_name' => 'Write Actor',
        ]);

        $this->actorUserId =
            (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'uuid'         => $this->uuid(),
            'email'        => 'write-unauthorized@invalid.example',
            'display_name' => 'Write Unauthorized',
        ]);

        $this->unauthorizedUserId =
            (int) $this->db->insertID();

        foreach ([
            $this->actorUserId,
            $this->unauthorizedUserId,
        ] as $userId) {
            $this->db->table('tenant_users')->insert([
                'tenant_id' => $this->tenantA,
                'user_id'   => $userId,
                'status'    => 'active',
            ]);
        }

        $permission = $this->db
            ->table('permissions')
            ->select('id')
            ->where(
                'code',
                'organizations.manage'
            )
            ->get()
            ->getFirstRow('array');

        if ($permission === null) {
            $this->db->table('permissions')->insert([
                'code'   => 'organizations.manage',
                'name'   => '__write_fixture_permission__',
                'domain' => 'core',
            ]);

            $this->permissionId =
                (int) $this->db->insertID();
        } else {
            $this->permissionId =
                (int) $permission['id'];
        }

        $this->db->table('roles')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantA,
            'code'      => '__write_role__',
            'name'      => 'Write Manager',
        ]);

        $this->roleId = (int) $this->db->insertID();

        $this->db->table('role_permissions')->insert([
            'role_id'       => $this->roleId,
            'permission_id' => $this->permissionId,
        ]);

        $this->db->table('user_roles')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->actorUserId,
            'role_id'   => $this->roleId,
        ]);

        $this->db->table('organizations')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantB,
            'code'      => '__write_foreign__',
            'name'      => 'Foreign Organization',
        ]);

        $this->foreignOrganizationId =
            (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        $cleanupDb =
            $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        $this->db->query(
            "DELETE FROM organizations
             WHERE tenant_id IN (
                SELECT id
                FROM tenants
                WHERE slug IN (
                    '__write_tenant_a__',
                    '__write_tenant_b__'
                )
             )"
        );

        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__write_tenant_a__',
                '__write_tenant_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'write-actor@invalid.example',
                'write-unauthorized@invalid.example'
             )"
        );

        $this->db->query(
            "DELETE FROM permissions
             WHERE code = 'organizations.manage'
               AND name = '__write_fixture_permission__'"
        );
    }

    private function organizationCountByCode(
        string $code
    ): int {
        return $this->db
            ->table('organizations')
            ->where('code', $code)
            ->countAllResults();
    }

    private function auditCount(): int
    {
        return $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->countAllResults();
    }

    private function latestAuditRow(): array
    {
        $row = $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new RuntimeException(
                'Expected audit row is missing.'
            );
        }

        return $row;
    }

    private function privilegedCleanupConnection(): \mysqli
    {
        $username = getenv(
            'MIGRATION_DB_USERNAME'
        );

        $password = getenv(
            'MIGRATION_DB_PASSWORD'
        );

        $database = getenv(
            'TEST_DATABASE'
        );

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
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
