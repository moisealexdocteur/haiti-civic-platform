<?php

namespace Tests\Database;

use App\Models\OrganizationModel;
use App\Services\AuthorizationService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use RuntimeException;

final class CoreServicesIsolationTest extends CIUnitTestCase
{
    private int $tenantA;
    private int $tenantB;
    private int $userA;
    private int $roleA;

    private int $permissionId;

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

    public function testTenantContextFailsClosedWhenUnset(): void
    {
        $context = new TenantContext();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Tenant context has not been resolved.'
        );

        $context->id();
    }

    public function testOrganizationModelOnlyReadsCurrentTenant(): void
    {
        $context = (new TenantContext())->set($this->tenantA);

        $model = new OrganizationModel(
            $context,
            $this->db
        );

        $rows = $model->findAll();

        $this->assertCount(1, $rows);

        $this->assertSame(
            '__services_org_a__',
            $rows[0]['code']
        );

        $this->assertSame(
            1,
            $model->countAllResults()
        );

        $first = $model->first();

        $this->assertNotNull($first);

        $this->assertSame(
            '__services_org_a__',
            $first['code']
        );

        $foreign = $model->find(
            $this->organizationIdForTenant($this->tenantB)
        );

        $this->assertNull($foreign);
    }

    public function testTenantScopedModelDoesNotExposeWriteApi(): void
    {
        $context = (new TenantContext())->set($this->tenantA);

        $model = new OrganizationModel(
            $context,
            $this->db
        );

        foreach ([
            'insert',
            'insertBatch',
            'update',
            'updateBatch',
            'save',
            'delete',
            'purgeDeleted',
            'replace',
            'builder',
        ] as $method) {
            $this->assertFalse(
                method_exists($model, $method),
                'Read-only model unexpectedly exposes ' . $method
            );
        }
    }

    public function testAuthorizationIsTenantScoped(): void
    {
        $context = (new TenantContext())->set($this->tenantA);

        $authorization = new AuthorizationService(
            $context,
            $this->db
        );

        $this->assertTrue(
            $authorization->userHasPermission(
                $this->userA,
                '__services_permission__'
            )
        );

        $this->assertContains(
            '__services_permission__',
            $authorization->permissionsForUser(
                $this->userA
            )
        );

        $context->set($this->tenantB);

        $this->assertFalse(
            $authorization->userHasPermission(
                $this->userA,
                '__services_permission__'
            )
        );

        $this->assertSame(
            [],
            $authorization->permissionsForUser(
                $this->userA
            )
        );
    }

    private function createFixtures(): void
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__services_tenant_a__',
            'name' => 'Services Tenant A',
        ]);

        $this->tenantA = (int) $this->db->insertID();

        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__services_tenant_b__',
            'name' => 'Services Tenant B',
        ]);

        $this->tenantB = (int) $this->db->insertID();

        $this->db->table('organizations')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantA,
            'code'      => '__services_org_a__',
            'name'      => 'Services Organization A',
        ]);

        $this->db->table('organizations')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantB,
            'code'      => '__services_org_b__',
            'name'      => 'Services Organization B',
        ]);

        $this->db->table('users')->insert([
            'uuid'         => $this->uuid(),
            'email'        => 'services-user-a@invalid.example',
            'display_name' => 'Services User A',
        ]);

        $this->userA = (int) $this->db->insertID();

        $this->db->table('tenant_users')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->userA,
            'status'    => 'active',
        ]);

        $this->db->table('permissions')->insert([
            'code'   => '__services_permission__',
            'name'   => 'PHPUnit Services Permission',
            'domain' => 'test',
        ]);

        $this->permissionId = (int) $this->db->insertID();

        $this->db->table('roles')->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantA,
            'code'      => '__services_role_a__',
            'name'      => 'Services Role A',
        ]);

        $this->roleA = (int) $this->db->insertID();

        $this->db->table('role_permissions')->insert([
            'role_id'       => $this->roleA,
            'permission_id' => $this->permissionId,
        ]);

        $this->db->table('user_roles')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->userA,
            'role_id'   => $this->roleA,
        ]);
    }

    private function organizationIdForTenant(
        int $tenantId
    ): int {
        $row = $this->db
            ->table('organizations')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new RuntimeException(
                'Expected organization fixture is missing.'
            );
        }

        return (int) $row['id'];
    }

    private function cleanupFixtures(): void
    {
        // organizations -> tenants is RESTRICT:
        // child rows must be removed first.
        $this->db->query(
            "DELETE FROM organizations
             WHERE code IN (
                '__services_org_a__',
                '__services_org_b__'
             )"
        );

        // Removing tenants now cascades tenant_users, roles,
        // user_roles, role_permissions and tenant_modules.
        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__services_tenant_a__',
                '__services_tenant_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email =
                'services-user-a@invalid.example'"
        );

        // role_permissions are gone after tenant/role cascade,
        // so the isolated test permission can now be removed.
        $this->db->query(
            "DELETE FROM permissions
             WHERE code =
                '__services_permission__'"
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
