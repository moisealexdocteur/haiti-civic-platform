<?php

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Throwable;

final class MultiTenantIsolationTest extends CIUnitTestCase
{
    private int $tenantA;
    private int $tenantB;
    private int $userA;
    private int $userB;
    private int $roleA;
    private int $roleB;

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

    public function testValidRoleAssignmentInsideSameTenant(): void
    {
        $result = $this->db->table('user_roles')->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->userA,
            'role_id'   => $this->roleA,
        ]);

        $this->assertTrue($result);

        $count = $this->db->table('user_roles')
            ->where('tenant_id', $this->tenantA)
            ->where('user_id', $this->userA)
            ->where('role_id', $this->roleA)
            ->countAllResults();

        $this->assertSame(1, $count);
    }

    public function testRoleFromAnotherTenantIsRejectedByDatabase(): void
    {
        try {
            $this->db->table('user_roles')->insert([
                'tenant_id' => $this->tenantA,
                'user_id'   => $this->userA,
                'role_id'   => $this->roleB,
            ]);

            $this->fail(
                'MariaDB accepted a role belonging to another tenant.'
            );
        } catch (Throwable $e) {
            $this->assertStringContainsString(
                'fk_user_roles_role_tenant',
                $e->getMessage()
            );
        }
    }

    public function testUserOutsideTenantIsRejectedByDatabase(): void
    {
        try {
            $this->db->table('user_roles')->insert([
                'tenant_id' => $this->tenantB,
                'user_id'   => $this->userA,
                'role_id'   => $this->roleB,
            ]);

            $this->fail(
                'MariaDB accepted a user not belonging to the tenant.'
            );
        } catch (Throwable $e) {
            $this->assertStringContainsString(
                'fk_user_roles_tenant_user',
                $e->getMessage()
            );
        }
    }

    private function createFixtures(): void
    {
        $tenants = $this->db->table('tenants');

        $tenants->insert([
            'uuid'   => $this->uuid(),
            'slug'   => '__phpunit_tenant_a__',
            'name'   => 'PHPUnit Tenant A',
            'status' => 'active',
        ]);

        $this->tenantA = (int) $this->db->insertID();

        $tenants->insert([
            'uuid'   => $this->uuid(),
            'slug'   => '__phpunit_tenant_b__',
            'name'   => 'PHPUnit Tenant B',
            'status' => 'active',
        ]);

        $this->tenantB = (int) $this->db->insertID();

        $users = $this->db->table('users');

        $users->insert([
            'uuid'         => $this->uuid(),
            'email'        => 'phpunit-a@invalid.example',
            'display_name' => 'PHPUnit User A',
            'status'       => 'active',
        ]);

        $this->userA = (int) $this->db->insertID();

        $users->insert([
            'uuid'         => $this->uuid(),
            'email'        => 'phpunit-b@invalid.example',
            'display_name' => 'PHPUnit User B',
            'status'       => 'active',
        ]);

        $this->userB = (int) $this->db->insertID();

        $tenantUsers = $this->db->table('tenant_users');

        $tenantUsers->insert([
            'tenant_id' => $this->tenantA,
            'user_id'   => $this->userA,
            'status'    => 'active',
        ]);

        $tenantUsers->insert([
            'tenant_id' => $this->tenantB,
            'user_id'   => $this->userB,
            'status'    => 'active',
        ]);

        $roles = $this->db->table('roles');

        $roles->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantA,
            'code'      => 'phpunit_admin',
            'name'      => 'PHPUnit Admin A',
        ]);

        $this->roleA = (int) $this->db->insertID();

        $roles->insert([
            'uuid'      => $this->uuid(),
            'tenant_id' => $this->tenantB,
            'code'      => 'phpunit_admin',
            'name'      => 'PHPUnit Admin B',
        ]);

        $this->roleB = (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__phpunit_tenant_a__',
                '__phpunit_tenant_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'phpunit-a@invalid.example',
                'phpunit-b@invalid.example'
             )"
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
