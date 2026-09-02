<?php

namespace Tests\Database;

use App\Services\AuthorizationService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

final class IdentityPermissionCatalogTest
    extends CIUnitTestCase
{
    private const TENANT_A =
        '__identity_permission_a__';

    private const TENANT_B =
        '__identity_permission_b__';

    private const USER_A =
        'identity-permission-a@invalid.example';

    private const USER_B =
        'identity-permission-b@invalid.example';

    private int $tenantA;
    private int $tenantB;
    private int $userA;
    private int $userB;

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

    public function testCanonicalIdentityPermissionsExist(): void
    {
        $rows = $this->db
            ->table('permissions')
            ->select(
                'code, name, domain'
            )
            ->whereIn(
                'code',
                [
                    'identity.view',
                    'identity.manage',
                ]
            )
            ->orderBy(
                'code',
                'ASC'
            )
            ->get()
            ->getResultArray();

        $this->assertCount(
            2,
            $rows
        );

        $byCode = [];

        foreach ($rows as $row) {
            $byCode[$row['code']] = $row;
        }

        $this->assertArrayHasKey(
            'identity.view',
            $byCode
        );

        $this->assertArrayHasKey(
            'identity.manage',
            $byCode
        );

        $this->assertSame(
            'identity_verification',
            $byCode['identity.view']['domain']
        );

        $this->assertSame(
            'identity_verification',
            $byCode['identity.manage']['domain']
        );
    }

    public function testIdentityManageAuthorizationIsTenantScoped(): void
    {
        $tenantA =
            new AuthorizationService(
                (new TenantContext())
                    ->set($this->tenantA),
                $this->db
            );

        $tenantB =
            new AuthorizationService(
                (new TenantContext())
                    ->set($this->tenantB),
                $this->db
            );

        $this->assertTrue(
            $tenantA->userHasPermission(
                $this->userA,
                'identity.manage'
            )
        );

        /*
         * userB appartient au tenant B.
         * Il ne doit jamais hériter du rôle du tenant A.
         */
        $this->assertFalse(
            $tenantA->userHasPermission(
                $this->userB,
                'identity.manage'
            )
        );

        /*
         * Aucun rôle identity.manage n'est donné dans B.
         */
        $this->assertFalse(
            $tenantB->userHasPermission(
                $this->userB,
                'identity.manage'
            )
        );

        /*
         * userA n'appartient pas au tenant B.
         */
        $this->assertFalse(
            $tenantB->userHasPermission(
                $this->userA,
                'identity.manage'
            )
        );
    }

    private function createFixtures(): void
    {
        $this->db
            ->table('tenants')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'slug' =>
                    self::TENANT_A,
                'name' =>
                    'Identity Permission A',
            ]);

        $this->tenantA =
            (int) $this->db->insertID();

        $this->db
            ->table('tenants')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'slug' =>
                    self::TENANT_B,
                'name' =>
                    'Identity Permission B',
            ]);

        $this->tenantB =
            (int) $this->db->insertID();

        $this->userA =
            $this->insertUser(
                self::USER_A,
                'Identity Permission User A'
            );

        $this->userB =
            $this->insertUser(
                self::USER_B,
                'Identity Permission User B'
            );

        $this->insertMembership(
            $this->tenantA,
            $this->userA
        );

        $this->insertMembership(
            $this->tenantB,
            $this->userB
        );

        $permission = $this->db
            ->table('permissions')
            ->select('id')
            ->where(
                'code',
                'identity.manage'
            )
            ->get()
            ->getFirstRow('array');

        if ($permission === null) {
            throw new RuntimeException(
                'identity.manage is absent.'
            );
        }

        $this->db
            ->table('roles')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'tenant_id' =>
                    $this->tenantA,
                'code' =>
                    '__identity_manager__',
                'name' =>
                    'Identity Manager',
            ]);

        $roleId =
            (int) $this->db->insertID();

        $this->db
            ->table('role_permissions')
            ->insert([
                'role_id' =>
                    $roleId,
                'permission_id' =>
                    (int) $permission['id'],
            ]);

        $this->db
            ->table('user_roles')
            ->insert([
                'tenant_id' =>
                    $this->tenantA,
                'user_id' =>
                    $this->userA,
                'role_id' =>
                    $roleId,
            ]);
    }

    private function insertUser(
        string $email,
        string $displayName
    ): int {
        $this->db
            ->table('users')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'email' =>
                    $email,
                'display_name' =>
                    $displayName,
                'status' =>
                    'active',
            ]);

        return (int)
            $this->db->insertID();
    }

    private function insertMembership(
        int $tenantId,
        int $userId
    ): void {
        $this->db
            ->table('tenant_users')
            ->insert([
                'tenant_id' =>
                    $tenantId,
                'user_id' =>
                    $userId,
                'status' =>
                    'active',
                'is_owner' =>
                    0,
            ]);
    }

    private function cleanupFixtures(): void
    {
        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__identity_permission_a__',
                '__identity_permission_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'identity-permission-a@invalid.example',
                'identity-permission-b@invalid.example'
             )"
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f)
            | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f)
            | 0x80
        );

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
