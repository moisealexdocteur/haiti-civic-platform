<?php

namespace Tests\Database;

use App\Services\AdminAuthService;
use App\Services\AdminBootstrapService;
use App\Services\AdminIdentityDecisionService;
use App\Services\AdminIdentityReadService;
use App\Services\PublicIdentitySubmissionService;
use App\Services\TenantContext;
use App\Services\VerificationDocumentWriteService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminIdentityServicesTest extends CIUnitTestCase
{
    private const TENANT_A_SLUG = '__admin_identity_a__';
    private const TENANT_B_SLUG = '__admin_identity_b__';

    private const ADMIN_A_EMAIL = '__admin_identity_a__@example.test';
    private const ADMIN_B_EMAIL = '__admin_identity_b__@example.test';
    private const VIEW_ONLY_EMAIL = '__admin_identity_view__@example.test';
    private const NO_VIEW_EMAIL = '__admin_identity_none__@example.test';

    private const PASSWORD = 'Synthetic-Admin-Password-2026!';

    private const NINU =
        '11111111111111111111111111111111'
        . '11111111111111111111111111111111';

    private const PHONE = '00000000';

    private int $tenantA;
    private int $tenantB;
    private string $tenantUuidA;
    private string $tenantUuidB;

    /** @var array<string, string> */
    private array $documents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $this->cleanupFixtures();

        [$this->tenantA, $this->tenantUuidA] = $this->insertTenant(
            self::TENANT_A_SLUG,
            'Admin Identity A'
        );

        [$this->tenantB, $this->tenantUuidB] = $this->insertTenant(
            self::TENANT_B_SLUG,
            'Admin Identity B'
        );

        $this->documents = $this->syntheticDocuments();
    }

    protected function tearDown(): void
    {
        $this->removeSyntheticDocuments();
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function testBootstrapAuthenticationAndSessionRevocation(): void
    {
        $result = (new AdminBootstrapService($this->db))
            ->bootstrapFirstAdministrator(
                self::TENANT_A_SLUG,
                self::ADMIN_A_EMAIL,
                'Administrateur A',
                self::PASSWORD
            );

        $this->assertSame('identity_admin', $result['role_code']);

        $user = $this->db
            ->table('users')
            ->where('id', (int) $result['user_id'])
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($user);
        $this->assertSame(self::ADMIN_A_EMAIL, $user['email']);
        $this->assertTrue(
            password_verify(self::PASSWORD, (string) $user['password_hash'])
        );

        $this->assertSame(
            ['identity.manage', 'identity.view'],
            $this->permissionCodesForUser(
                $this->tenantA,
                (int) $result['user_id']
            )
        );

        $auth = new AdminAuthService($this->db);
        $authenticated = $auth->authenticate(
            self::TENANT_A_SLUG,
            self::ADMIN_A_EMAIL,
            self::PASSWORD
        );

        $this->assertNotNull($authenticated);
        $this->assertSame(
            (int) $result['user_id'],
            (int) $authenticated['user_id']
        );
        $this->assertNull(
            $auth->authenticate(
                self::TENANT_A_SLUG,
                self::ADMIN_A_EMAIL,
                'Wrong-Password-For-Test'
            )
        );
        $this->assertTrue(
            $auth->sessionIsActive(
                (int) $result['user_id'],
                $this->tenantA,
                self::TENANT_A_SLUG
            )
        );

        $this->db
            ->table('tenant_users')
            ->where('tenant_id', $this->tenantA)
            ->where('user_id', (int) $result['user_id'])
            ->update(['status' => 'inactive']);

        $this->assertFalse(
            $auth->sessionIsActive(
                (int) $result['user_id'],
                $this->tenantA,
                self::TENANT_A_SLUG
            )
        );

        $bootstrapException = $this->captureException(
            fn () => (new AdminBootstrapService($this->db))
                ->bootstrapFirstAdministrator(
                    self::TENANT_A_SLUG,
                    '__another_admin__@example.test',
                    'Deuxième administrateur',
                    self::PASSWORD
                )
        );

        $this->assertInstanceOf(RuntimeException::class, $bootstrapException);

        $audit = $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('event', 'admin.bootstrap_created')
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($audit);
        $this->assertSame('system', $audit['actor_type']);
        $this->assertNull($audit['actor_user_id']);
        $this->assertSame(64, strlen((string) $audit['entry_hash']));
    }

    public function testReadServiceDecryptsAndEnforcesTenantIsolation(): void
    {
        $adminA = $this->bootstrapA();
        $adminB = $this->bootstrapB();

        $identityA = $this->submitIdentity($this->tenantA, self::NINU);
        $identityB = $this->submitIdentity($this->tenantB, self::NINU);

        $readA = new AdminIdentityReadService(
            (new TenantContext())->set($this->tenantA),
            $this->db
        );

        $queue = $readA->listForActor(
            (int) $adminA['user_id'],
            'pending'
        );

        $this->assertCount(1, $queue);
        $this->assertSame($identityA['uuid'], $queue[0]['uuid']);
        $this->assertSame(3, (int) $queue[0]['document_count']);

        $detail = $readA->detailForActorByUuid(
            (int) $adminA['user_id'],
            $identityA['uuid']
        );

        $this->assertNotNull($detail);
        $this->assertSame(self::NINU, $detail['ninu']);
        $this->assertSame('+509' . self::PHONE, $detail['phone']);
        $this->assertSame('pending', $detail['verification_status']);
        $this->assertCount(3, $detail['documents']);

        $this->assertNull(
            $readA->detailForActorByUuid(
                (int) $adminA['user_id'],
                $identityB['uuid']
            )
        );

        $readB = new AdminIdentityReadService(
            (new TenantContext())->set($this->tenantB),
            $this->db
        );
        $detailB = $readB->detailForActorByUuid(
            (int) $adminB['user_id'],
            $identityB['uuid']
        );

        $this->assertNotNull($detailB);

        $documentA = $detail['documents'][0];
        $resolvedA = $readA->documentForActor(
            (int) $adminA['user_id'],
            $identityA['uuid'],
            (string) $documentA['uuid']
        );

        $this->assertNotNull($resolvedA);
        $this->assertFileExists((string) $resolvedA['absolute_path']);
        $this->assertSame('image/png', $resolvedA['content_type']);

        $documentB = $detailB['documents'][0];
        $this->assertNull(
            $readA->documentForActor(
                (int) $adminA['user_id'],
                $identityB['uuid'],
                (string) $documentB['uuid']
            )
        );
    }

    public function testPermissionBoundariesAndAuditedDecisionCycle(): void
    {
        $adminA = $this->bootstrapA();
        $identityA = $this->submitIdentity($this->tenantA, self::NINU);
        $identityB = $this->submitIdentity($this->tenantB, self::NINU);

        $viewOnlyUserId = $this->insertRestrictedUser(
            $this->tenantA,
            self::VIEW_ONLY_EMAIL,
            true
        );
        $noViewUserId = $this->insertRestrictedUser(
            $this->tenantA,
            self::NO_VIEW_EMAIL,
            false
        );

        $contextA = (new TenantContext())->set($this->tenantA);
        $readA = new AdminIdentityReadService($contextA, $this->db);
        $decisionA = new AdminIdentityDecisionService($contextA, $this->db);

        $this->assertCount(
            1,
            $readA->listForActor($viewOnlyUserId, 'pending')
        );

        $noViewException = $this->captureException(
            fn () => $readA->listForActor($noViewUserId, 'pending')
        );
        $this->assertInstanceOf(RuntimeException::class, $noViewException);
        $this->assertSame(
            'Permission denied: identity.view',
            $noViewException->getMessage()
        );

        $viewOnlyDecision = $this->captureException(
            fn () => $decisionA->transition(
                $viewOnlyUserId,
                $identityA['uuid'],
                'rejected',
                'test_document_illisible'
            )
        );
        $this->assertInstanceOf(RuntimeException::class, $viewOnlyDecision);
        $this->assertSame(
            'Permission denied: identity.manage',
            $viewOnlyDecision->getMessage()
        );

        $crossTenant = $this->captureException(
            fn () => $decisionA->transition(
                (int) $adminA['user_id'],
                $identityB['uuid'],
                'rejected',
                'test_cross_tenant'
            )
        );
        $this->assertInstanceOf(InvalidArgumentException::class, $crossTenant);

        $decisionA->transition(
            (int) $adminA['user_id'],
            $identityA['uuid'],
            'rejected',
            'test_document_illisible'
        );

        $rejected = $this->identityByUuid(
            $this->tenantA,
            $identityA['uuid']
        );
        $this->assertSame('rejected', $rejected['verification_status']);
        $this->assertNull($rejected['verified_at']);

        $event = $this->db
            ->table('identity_verification_events')
            ->where('tenant_id', $this->tenantA)
            ->where('citizen_identity_id', (int) $identityA['id'])
            ->where('event_type', 'identity.verification_status_changed')
            ->orderBy('id', 'DESC')
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($event);
        $this->assertSame('pending', $event['from_status']);
        $this->assertSame('rejected', $event['to_status']);
        $this->assertSame('test_document_illisible', $event['reason_code']);
        $this->assertSame((int) $adminA['user_id'], (int) $event['actor_user_id']);

        $audit = $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('entity_type', 'citizen_identity')
            ->where('entity_id', (string) $identityA['id'])
            ->where('event', 'citizen_identity.verification_status_changed')
            ->orderBy('id', 'DESC')
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($audit);
        $this->assertSame('user', $audit['actor_type']);
        $this->assertSame((int) $adminA['user_id'], (int) $audit['actor_user_id']);
        $this->assertSame(64, strlen((string) $audit['entry_hash']));

        $decisionA->transition(
            (int) $adminA['user_id'],
            $identityA['uuid'],
            'pending'
        );

        $pending = $this->identityByUuid(
            $this->tenantA,
            $identityA['uuid']
        );
        $this->assertSame('pending', $pending['verification_status']);
        $this->assertNull($pending['verified_at']);

        $decisionEvents = $this->db
            ->table('identity_verification_events')
            ->where('tenant_id', $this->tenantA)
            ->where('citizen_identity_id', (int) $identityA['id'])
            ->where('event_type', 'identity.verification_status_changed')
            ->countAllResults();

        $this->assertSame(2, $decisionEvents);
    }

    private function bootstrapA(): array
    {
        return (new AdminBootstrapService($this->db))
            ->bootstrapFirstAdministrator(
                self::TENANT_A_SLUG,
                self::ADMIN_A_EMAIL,
                'Administrateur A',
                self::PASSWORD
            );
    }

    private function bootstrapB(): array
    {
        return (new AdminBootstrapService($this->db))
            ->bootstrapFirstAdministrator(
                self::TENANT_B_SLUG,
                self::ADMIN_B_EMAIL,
                'Administrateur B',
                self::PASSWORD
            );
    }

    private function submitIdentity(int $tenantId, string $ninu): array
    {
        return (new PublicIdentitySubmissionService(
            (new TenantContext())->set($tenantId),
            $this->db
        ))->submit(
            $ninu,
            self::PHONE,
            'admin-test-v1',
            $this->documents
        );
    }

    private function insertRestrictedUser(
        int $tenantId,
        string $email,
        bool $withViewPermission
    ): int {
        $this->db->table('users')->insert([
            'uuid' => $this->uuid(),
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'display_name' => $withViewPermission ? 'Lecture seule' : 'Sans permission',
            'status' => 'active',
            'locale' => 'fr',
        ]);
        $userId = (int) $this->db->insertID();

        $this->db->table('tenant_users')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => 0,
        ]);

        if (! $withViewPermission) {
            return $userId;
        }

        $this->db->table('roles')->insert([
            'uuid' => $this->uuid(),
            'tenant_id' => $tenantId,
            'code' => 'identity_view_only',
            'name' => 'Lecture identités',
            'description' => 'Rôle synthétique de test.',
            'is_system' => 0,
        ]);
        $roleId = (int) $this->db->insertID();

        $permission = $this->db
            ->table('permissions')
            ->select('id')
            ->where('code', 'identity.view')
            ->get()
            ->getFirstRow('array');

        if ($permission === null) {
            throw new RuntimeException('identity.view test permission is missing.');
        }

        $this->db->table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => (int) $permission['id'],
        ]);
        $this->db->table('user_roles')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);

        return $userId;
    }

    /** @return list<string> */
    private function permissionCodesForUser(int $tenantId, int $userId): array
    {
        $rows = $this->db
            ->table('user_roles ur')
            ->select('p.code')
            ->join('roles r', 'r.id = ur.role_id')
            ->join('role_permissions rp', 'rp.role_id = r.id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ur.tenant_id', $tenantId)
            ->where('ur.user_id', $userId)
            ->orderBy('p.code', 'ASC')
            ->get()
            ->getResultArray();

        return array_values(array_column($rows, 'code'));
    }

    private function identityByUuid(int $tenantId, string $uuid): array
    {
        $row = $this->db
            ->table('citizen_identities')
            ->where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new RuntimeException('Synthetic identity fixture is missing.');
        }

        return $row;
    }

    /** @return array{0:int,1:string} */
    private function insertTenant(string $slug, string $name): array
    {
        $uuid = $this->uuid();

        $this->db->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'default_locale' => 'fr',
            'timezone' => 'America/Port-au-Prince',
        ]);

        return [(int) $this->db->insertID(), $uuid];
    }

    /** @return array<string,string> */
    private function syntheticDocuments(): array
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
            . 'AAAAC0lEQVR42mP8/x8AAusB9Wl2nKsAAAAASUVORK5CYII=',
            true
        );

        if (! is_string($png)) {
            throw new RuntimeException('Synthetic PNG fixture is invalid.');
        }

        $documents = [];

        foreach ([
            VerificationDocumentWriteService::CIN_FRONT,
            VerificationDocumentWriteService::CIN_BACK,
            VerificationDocumentWriteService::PORTRAIT,
        ] as $type) {
            $path = tempnam(sys_get_temp_dir(), 'civic-admin-png-');

            if ($path === false) {
                throw new RuntimeException('Could not create synthetic PNG fixture.');
            }

            file_put_contents($path, $png);
            $documents[$type] = $path;
        }

        return $documents;
    }

    private function removeSyntheticDocuments(): void
    {
        foreach ($this->documents as $path) {
            @unlink($path);
        }

        $this->documents = [];
    }

    private function cleanupFixtures(): void
    {
        foreach ($this->fixtureTenantUuids() as $uuid) {
            $this->removeTree(
                WRITEPATH . 'uploads/identity/' . strtolower($uuid)
            );
        }

        $cleanupDb = $this->privilegedCleanupConnection();
        $cleanupDb->query('TRUNCATE TABLE `audit_logs`');
        $cleanupDb->close();

        $slugA = self::TENANT_A_SLUG;
        $slugB = self::TENANT_B_SLUG;

        foreach ([
            'identity_verification_events',
            'verification_documents',
            'citizen_identities',
        ] as $table) {
            $this->db->query(
                "DELETE x FROM `{$table}` x "
                . 'INNER JOIN tenants t ON t.id = x.tenant_id '
                . "WHERE t.slug IN ('{$slugA}', '{$slugB}')"
            );
        }

        $this->db->query(
            'DELETE ur FROM user_roles ur '
            . 'INNER JOIN tenants t ON t.id = ur.tenant_id '
            . "WHERE t.slug IN ('{$slugA}', '{$slugB}')"
        );

        $this->db->query(
            'DELETE rp FROM role_permissions rp '
            . 'INNER JOIN roles r ON r.id = rp.role_id '
            . 'INNER JOIN tenants t ON t.id = r.tenant_id '
            . "WHERE t.slug IN ('{$slugA}', '{$slugB}')"
        );

        $this->db->query(
            'DELETE r FROM roles r '
            . 'INNER JOIN tenants t ON t.id = r.tenant_id '
            . "WHERE t.slug IN ('{$slugA}', '{$slugB}')"
        );

        $this->db->query(
            'DELETE tu FROM tenant_users tu '
            . 'INNER JOIN tenants t ON t.id = tu.tenant_id '
            . "WHERE t.slug IN ('{$slugA}', '{$slugB}')"
        );

        $this->db->table('users')->whereIn('email', [
            self::ADMIN_A_EMAIL,
            self::ADMIN_B_EMAIL,
            self::VIEW_ONLY_EMAIL,
            self::NO_VIEW_EMAIL,
            '__another_admin__@example.test',
        ])->delete();

        $this->db->table('tenants')->whereIn('slug', [
            self::TENANT_A_SLUG,
            self::TENANT_B_SLUG,
        ])->delete();
    }

    /** @return list<string> */
    private function fixtureTenantUuids(): array
    {
        $rows = $this->db
            ->table('tenants')
            ->select('uuid')
            ->whereIn('slug', [self::TENANT_A_SLUG, self::TENANT_B_SLUG])
            ->get()
            ->getResultArray();

        return array_values(array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $rows
        ));
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
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

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        return new \mysqli(
            'db',
            $username,
            $password,
            $database,
            3306
        );
    }

    private function captureException(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Expected operation to throw an exception.');
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
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
