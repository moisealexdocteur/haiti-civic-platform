<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Throwable;

final class ConcurrentWriteServiceTest
    extends CIUnitTestCase
{
    private const TENANT_SLUG =
        '__concurrency_tenant__';

    private const ROLE_CODE =
        '__concurrency_manager__';

    private const USER_A_EMAIL =
        'concurrency-owner-a@invalid.example';

    private const USER_B_EMAIL =
        'concurrency-owner-b@invalid.example';

    private const ORG_A_CODE =
        '__concurrency_org_a__';

    private const ORG_B_CODE =
        '__concurrency_org_b__';

    private const PERMISSION_PREFIX =
        '__concurrency_test_permission__';

    private int $tenantId;
    private int $ownerA;
    private int $ownerB;

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

    public function testConcurrentAuditedWritesAreSerialized(): void
    {
        $run = $this->runContendedWorkers(
            [
                [
                    'operation' =>
                        'organization_create',
                    'actor' => $this->ownerA,
                    'target' =>
                        self::ORG_A_CODE,
                ],
                [
                    'operation' =>
                        'organization_create',
                    'actor' => $this->ownerA,
                    'target' =>
                        self::ORG_B_CODE,
                ],
            ],
            fn (): array => [
                'organizations' =>
                    $this->organizationCount(),
                'audits' =>
                    $this->auditCount(),
            ]
        );

        $this->assertSame(
            [true, true],
            $run['running_while_locked']
        );

        $this->assertSame(
            0,
            $run['snapshot']['organizations']
        );

        $this->assertSame(
            0,
            $run['snapshot']['audits']
        );

        [$workerA, $workerB] =
            $run['results'];

        $this->assertTrue($workerA['ok']);
        $this->assertTrue($workerB['ok']);

        $this->assertNotSame(
            $workerA['pid'],
            $workerB['pid']
        );

        $this->assertNotSame(
            $workerA['connection_id'],
            $workerB['connection_id']
        );

        $this->assertSame(
            $run['parent_connection_id'],
            $workerA['observed_lock_holder']
        );

        $this->assertSame(
            $run['parent_connection_id'],
            $workerB['observed_lock_holder']
        );

        /*
         * Le parent conserve le verrou au moins
         * 300 ms après réception des deux signaux ready.
         */
        $this->assertGreaterThanOrEqual(
            250.0,
            (float) $workerA['elapsed_ms']
        );

        $this->assertGreaterThanOrEqual(
            250.0,
            (float) $workerB['elapsed_ms']
        );

        $this->assertSame(
            2,
            $this->organizationCount()
        );

        $this->assertSame(
            2,
            $this->auditCount()
        );

        $events = $this->db
            ->table('audit_logs')
            ->select('event')
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertSame(
            [
                'organization.created',
                'organization.created',
            ],
            array_column($events, 'event')
        );

        $verification =
            $this->auditService()
                ->verifyCurrentTenantChain();

        $this->assertTrue(
            $verification['valid']
        );

        $this->assertSame(
            2,
            $verification['count']
        );
    }

    public function testConcurrentOwnerDemotionsPreserveLastOwner(): void
    {
        $run = $this->runContendedWorkers(
            [
                [
                    'operation' =>
                        'owner_demote',
                    'actor' => $this->ownerA,
                    'target' =>
                        (string) $this->ownerA,
                ],
                [
                    'operation' =>
                        'owner_demote',
                    'actor' => $this->ownerB,
                    'target' =>
                        (string) $this->ownerB,
                ],
            ],
            fn (): array => [
                'owners' =>
                    $this->activeOwnerCount(),
                'audits' =>
                    $this->auditCount(),
            ]
        );

        $this->assertSame(
            [true, true],
            $run['running_while_locked']
        );

        $this->assertSame(
            2,
            $run['snapshot']['owners']
        );

        $this->assertSame(
            0,
            $run['snapshot']['audits']
        );

        [$workerA, $workerB] =
            $run['results'];

        $this->assertNotSame(
            $workerA['pid'],
            $workerB['pid']
        );

        $this->assertNotSame(
            $workerA['connection_id'],
            $workerB['connection_id']
        );

        $this->assertSame(
            $run['parent_connection_id'],
            $workerA['observed_lock_holder']
        );

        $this->assertSame(
            $run['parent_connection_id'],
            $workerB['observed_lock_holder']
        );

        $this->assertGreaterThanOrEqual(
            250.0,
            (float) $workerA['elapsed_ms']
        );

        $this->assertGreaterThanOrEqual(
            250.0,
            (float) $workerB['elapsed_ms']
        );

        $successes = array_values(
            array_filter(
                $run['results'],
                static fn (array $result): bool =>
                    $result['ok'] === true
            )
        );

        $failures = array_values(
            array_filter(
                $run['results'],
                static fn (array $result): bool =>
                    $result['ok'] === false
            )
        );

        $this->assertCount(1, $successes);
        $this->assertCount(1, $failures);

        $this->assertSame(
            RuntimeException::class,
            $failures[0]['exception_class']
        );

        $this->assertSame(
            'The last active tenant owner '
            . 'cannot be removed or disabled.',
            $failures[0]['exception_message']
        );

        $this->assertSame(
            1,
            $this->activeOwnerCount()
        );

        $ownerStates = [
            $this->ownerState(
                $this->ownerA
            ),
            $this->ownerState(
                $this->ownerB
            ),
        ];

        sort($ownerStates);

        $this->assertSame(
            [0, 1],
            $ownerStates
        );

        /*
         * Une seule mutation a été commise.
         * La seconde a été refusée et rollbackée.
         */
        $this->assertSame(
            1,
            $this->auditCount()
        );

        $events = $this->db
            ->table('audit_logs')
            ->select('event')
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->get()
            ->getResultArray();

        $this->assertSame(
            ['tenant_user.owner_changed'],
            array_column($events, 'event')
        );

        $verification =
            $this->auditService()
                ->verifyCurrentTenantChain();

        $this->assertTrue(
            $verification['valid']
        );

        $this->assertSame(
            1,
            $verification['count']
        );
    }

    private function runContendedWorkers(
        array $specifications,
        callable $snapshot
    ): array {
        $lockName =
            'civic_audit_tenant_'
            . $this->tenantId;

        $parentConnectionId =
            $this->connectionId();

        $acquired = $this->db
            ->query(
                'SELECT GET_LOCK(?, 2) AS acquired',
                [$lockName]
            )
            ->getFirstRow('array');

        if (
            (int) ($acquired['acquired'] ?? 0)
            !== 1
        ) {
            throw new RuntimeException(
                'Parent could not acquire audit lock.'
            );
        }

        $workers = [];
        $released = false;
        $error = null;
        $running = [];
        $before = [];

        try {
            foreach ($specifications as $specification) {
                $workers[] =
                    $this->spawnWorker(
                        (string)
                            $specification['operation'],
                        (int)
                            $specification['actor'],
                        (string)
                            $specification['target']
                    );
            }

            $this->waitForWorkersReady(
                $workers
            );

            /*
             * Les deux workers ont observé le verrou
             * du parent et vont maintenant attendre
             * dans le GET_LOCK du service.
             */
            usleep(300000);

            foreach ($workers as $worker) {
                $status = proc_get_status(
                    $worker['process']
                );

                $running[] =
                    (bool) $status['running'];
            }

            $before = $snapshot();
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            $this->db->query(
                'SELECT RELEASE_LOCK(?)',
                [$lockName]
            );

            $released = true;
        }

        if (! $released) {
            throw new RuntimeException(
                'Parent audit lock was not released.'
            );
        }

        if ($error !== null) {
            $this->terminateWorkers(
                $workers
            );

            throw $error;
        }

        $results = [];

        foreach ($workers as $worker) {
            $results[] =
                $this->collectWorker(
                    $worker
                );
        }

        return [
            'parent_connection_id' =>
                $parentConnectionId,
            'running_while_locked' =>
                $running,
            'snapshot' => $before,
            'results' => $results,
        ];
    }

    private function spawnWorker(
        string $operation,
        int $actorUserId,
        string $target
    ): array {
        $readyFile =
            $this->temporaryPath(
                'civic-ready-'
            );

        $resultFile =
            $this->temporaryPath(
                'civic-result-'
            );

        $stdoutFile = tempnam(
            '/tmp',
            'civic-stdout-'
        );

        $stderrFile = tempnam(
            '/tmp',
            'civic-stderr-'
        );

        if (
            $stdoutFile === false
            || $stderrFile === false
        ) {
            throw new RuntimeException(
                'Could not create worker output files.'
            );
        }

        $command = [
            PHP_BINARY,
            HOMEPATH
                . 'tests/support/'
                . 'concurrency_worker.php',
            $operation,
            (string) $this->tenantId,
            (string) $actorUserId,
            $target,
            $readyFile,
            $resultFile,
        ];

        $process = proc_open(
            $command,
            [
                0 => [
                    'file',
                    '/dev/null',
                    'r',
                ],
                1 => [
                    'file',
                    $stdoutFile,
                    'w',
                ],
                2 => [
                    'file',
                    $stderrFile,
                    'w',
                ],
            ],
            $pipes,
            HOMEPATH
        );

        if (! is_resource($process)) {
            throw new RuntimeException(
                'Could not start concurrency worker.'
            );
        }

        return [
            'process' => $process,
            'ready' => $readyFile,
            'result' => $resultFile,
            'stdout' => $stdoutFile,
            'stderr' => $stderrFile,
        ];
    }

    private function waitForWorkersReady(
        array $workers
    ): void {
        $deadline =
            microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            $ready = true;

            foreach ($workers as $worker) {
                clearstatcache(
                    true,
                    $worker['ready']
                );

                if (
                    ! is_file($worker['ready'])
                    || filesize(
                        $worker['ready']
                    ) === 0
                ) {
                    $ready = false;
                    break;
                }
            }

            if ($ready) {
                return;
            }

            usleep(10000);
        }

        throw new RuntimeException(
            'Concurrency workers did not become ready.'
        );
    }

    private function collectWorker(
        array $worker
    ): array {
        $exitCode = proc_close(
            $worker['process']
        );

        $stdout = (string)
            file_get_contents(
                $worker['stdout']
            );

        $stderr = (string)
            file_get_contents(
                $worker['stderr']
            );

        if ($exitCode !== 0) {
            $this->removeWorkerFiles(
                $worker
            );

            throw new RuntimeException(
                'Concurrency worker exited with '
                . $exitCode
                . '. stdout='
                . trim($stdout)
                . ' stderr='
                . trim($stderr)
            );
        }

        if (! is_file($worker['result'])) {
            $this->removeWorkerFiles(
                $worker
            );

            throw new RuntimeException(
                'Concurrency worker produced no result.'
            );
        }

        $result = json_decode(
            (string) file_get_contents(
                $worker['result']
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->removeWorkerFiles(
            $worker
        );

        return $result;
    }

    private function terminateWorkers(
        array $workers
    ): void {
        foreach ($workers as $worker) {
            if (
                isset($worker['process'])
                && is_resource(
                    $worker['process']
                )
            ) {
                $status = proc_get_status(
                    $worker['process']
                );

                if ($status['running']) {
                    proc_terminate(
                        $worker['process']
                    );
                }

                proc_close(
                    $worker['process']
                );
            }

            $this->removeWorkerFiles(
                $worker
            );
        }
    }

    private function removeWorkerFiles(
        array $worker
    ): void {
        foreach (
            [
                'ready',
                'result',
                'stdout',
                'stderr',
            ] as $key
        ) {
            if (
                isset($worker[$key])
                && is_string(
                    $worker[$key]
                )
            ) {
                @unlink($worker[$key]);
            }
        }
    }

    private function temporaryPath(
        string $prefix
    ): string {
        $path = tempnam(
            '/tmp',
            $prefix
        );

        if ($path === false) {
            throw new RuntimeException(
                'Could not allocate temporary path.'
            );
        }

        unlink($path);

        return $path;
    }

    private function connectionId(): int
    {
        $row = $this->db
            ->query(
                'SELECT CONNECTION_ID() AS id'
            )
            ->getFirstRow('array');

        return (int) $row['id'];
    }

    private function organizationCount(): int
    {
        return $this->db
            ->table('organizations')
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->whereIn(
                'code',
                [
                    self::ORG_A_CODE,
                    self::ORG_B_CODE,
                ]
            )
            ->countAllResults();
    }

    private function activeOwnerCount(): int
    {
        return $this->db
            ->table('tenant_users')
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->where('status', 'active')
            ->where('is_owner', 1)
            ->countAllResults();
    }

    private function ownerState(
        int $userId
    ): int {
        $row = $this->db
            ->table('tenant_users')
            ->select('is_owner')
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->where('user_id', $userId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new RuntimeException(
                'Owner membership disappeared.'
            );
        }

        return (int) $row['is_owner'];
    }

    private function auditCount(): int
    {
        return $this->db
            ->table('audit_logs')
            ->where(
                'tenant_id',
                $this->tenantId
            )
            ->countAllResults();
    }

    private function auditService(): AuditService
    {
        return new AuditService(
            (new TenantContext())
                ->set($this->tenantId),
            $this->db
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
                    self::TENANT_SLUG,
                'name' =>
                    'Concurrency Tenant',
            ]);

        $this->tenantId =
            (int) $this->db->insertID();

        $this->ownerA =
            $this->insertUser(
                self::USER_A_EMAIL,
                'Concurrency Owner A'
            );

        $this->ownerB =
            $this->insertUser(
                self::USER_B_EMAIL,
                'Concurrency Owner B'
            );

        foreach (
            [
                $this->ownerA,
                $this->ownerB,
            ] as $userId
        ) {
            $this->db
                ->table('tenant_users')
                ->insert([
                    'tenant_id' =>
                        $this->tenantId,
                    'user_id' =>
                        $userId,
                    'status' =>
                        'active',
                    'is_owner' =>
                        1,
                ]);
        }

        $organizationPermission =
            $this->ensurePermission(
                'organizations.manage'
            );

        $userPermission =
            $this->ensurePermission(
                'users.manage'
            );

        $this->db
            ->table('roles')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'tenant_id' =>
                    $this->tenantId,
                'code' =>
                    self::ROLE_CODE,
                'name' =>
                    'Concurrency Manager',
                'is_system' =>
                    0,
            ]);

        $roleId =
            (int) $this->db->insertID();

        foreach (
            [
                $organizationPermission,
                $userPermission,
            ] as $permissionId
        ) {
            $this->db
                ->table('role_permissions')
                ->insert([
                    'role_id' =>
                        $roleId,
                    'permission_id' =>
                        $permissionId,
                ]);
        }

        foreach (
            [
                $this->ownerA,
                $this->ownerB,
            ] as $userId
        ) {
            $this->db
                ->table('user_roles')
                ->insert([
                    'tenant_id' =>
                        $this->tenantId,
                    'user_id' =>
                        $userId,
                    'role_id' =>
                        $roleId,
                ]);
        }
    }

    private function ensurePermission(
        string $code
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

        $this->db
            ->table('permissions')
            ->insert([
                'code' => $code,
                'name' =>
                    self::PERMISSION_PREFIX
                    . $code,
                'domain' => 'core',
            ]);

        return (int) $this->db->insertID();
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

        return (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        $cleanupDb =
            $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        /*
         * organizations.tenant_id est RESTRICT :
         * supprimer les organisations du fixture
         * avant le tenant.
         */
        $this->db->query(
            "DELETE o
             FROM organizations o
             INNER JOIN tenants t
                 ON t.id = o.tenant_id
             WHERE t.slug = '"
             . self::TENANT_SLUG
             . "'"
        );

        $this->db
            ->table('tenants')
            ->where(
                'slug',
                self::TENANT_SLUG
            )
            ->delete();

        $this->db
            ->table('users')
            ->whereIn(
                'email',
                [
                    self::USER_A_EMAIL,
                    self::USER_B_EMAIL,
                ]
            )
            ->delete();

        $this->db
            ->table('permissions')
            ->whereIn(
                'name',
                [
                    self::PERMISSION_PREFIX
                        . 'organizations.manage',
                    self::PERMISSION_PREFIX
                        . 'users.manage',
                ]
            )
            ->delete();
    }

    private function privilegedCleanupConnection(): \mysqli
    {
        $username =
            getenv('MIGRATION_DB_USERNAME');

        $password =
            getenv('MIGRATION_DB_PASSWORD');

        $database =
            getenv('TEST_DATABASE');

        if (
            ! is_string($username)
            || $username === ''
            || ! is_string($password)
            || $password === ''
            || ! is_string($database)
            || $database === ''
        ) {
            throw new RuntimeException(
                'Privileged test DB credentials '
                . 'are missing.'
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
