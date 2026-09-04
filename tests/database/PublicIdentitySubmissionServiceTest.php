<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\ContactVerificationStatus;
use App\Services\IdentityCryptoService;
use App\Services\PublicIdentitySubmissionService;
use App\Services\PublicIdentityTrackingService;
use App\Services\TenantContext;
use App\Services\VerificationDocumentWriteService;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicIdentitySubmissionServiceTest
    extends CIUnitTestCase
{
    private const NINU = '0000000000';

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

        [$this->tenantA, $this->tenantUuidA] =
            $this->insertTenant(
                '__public_submission_a__',
                'Public Submission A'
            );

        [$this->tenantB, $this->tenantUuidB] =
            $this->insertTenant(
                '__public_submission_b__',
                'Public Submission B'
            );

        $this->documents = $this->syntheticDocuments();
    }

    protected function tearDown(): void
    {
        $this->removeSyntheticDocuments();
        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function testSubmissionEncryptsStoresAndAudits(): void
    {
        $result = $this->service($this->tenantA)->submit(
            self::NINU,
            self::PHONE,
            'public-test-v1',
            $this->documents,
            email: 'citizen@example.test',
            firstName: 'Marie',
            lastName: 'D’Haïti'
        );

        $identity = $this->identity(
            $this->tenantA,
            (int) $result['id']
        );

        $this->assertNotNull($identity);
        $this->assertSame('pending', $identity['verification_status']);
        $this->assertSame(
            ContactVerificationStatus::OTP_VERIFIED,
            $identity['contact_verification_status']
        );
        $this->assertSame('public-test-v1', $identity['consent_version']);
        $this->assertNotSame(self::NINU, $identity['ninu_ciphertext']);
        $this->assertNotSame(
            '+509' . self::PHONE,
            $identity['phone_ciphertext']
        );
        $this->assertNotSame(
            'citizen@example.test',
            $identity['email_ciphertext']
        );
        $this->assertSame(64, strlen($identity['ninu_fingerprint']));

        $crypto = new IdentityCryptoService(
            (new TenantContext())->set($this->tenantA)
        );

        $this->assertSame(
            self::NINU,
            $crypto->decryptNinu(
                $identity['ninu_ciphertext'],
                $identity['uuid']
            )
        );
        $this->assertSame(
            '+509' . self::PHONE,
            $crypto->decryptPhone(
                $identity['phone_ciphertext'],
                $identity['uuid']
            )
        );
        $this->assertSame(
            'citizen@example.test',
            $crypto->decryptEmail(
                $identity['email_ciphertext'],
                $identity['uuid']
            )
        );
        $this->assertSame(
            'Marie',
            $crypto->decryptFirstName(
                $identity['first_name_ciphertext'],
                $identity['uuid']
            )
        );
        $this->assertSame(
            'D’Haïti',
            $crypto->decryptLastName(
                $identity['last_name_ciphertext'],
                $identity['uuid']
            )
        );

        $documents = $this->db
            ->table('verification_documents')
            ->where('tenant_id', $this->tenantA)
            ->where('citizen_identity_id', (int) $result['id'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertCount(3, $documents);
        $this->assertSame(
            [
                VerificationDocumentWriteService::CIN_FRONT,
                VerificationDocumentWriteService::CIN_BACK,
                VerificationDocumentWriteService::PORTRAIT,
            ],
            array_column($documents, 'document_type')
        );

        foreach ($documents as $document) {
            $this->assertStringStartsWith(
                'local://',
                $document['storage_ref']
            );
            $this->assertSame('image/png', $document['content_type']);
            $this->assertSame(64, strlen($document['sha256']));
            $this->assertSame('active', $document['status']);
        }

        $files = glob(
            WRITEPATH
            . 'uploads/identity/'
            . strtolower($this->tenantUuidA)
            . '/'
            . strtolower($identity['uuid'])
            . '/*'
        );

        $this->assertIsArray($files);
        $this->assertCount(3, $files);

        $event = $this->db
            ->table('identity_verification_events')
            ->where('tenant_id', $this->tenantA)
            ->where('citizen_identity_id', (int) $result['id'])
            ->where('event_type', 'identity.public_submitted')
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($event);
        $this->assertNull($event['actor_user_id']);
        $this->assertSame('pending', $event['to_status']);

        $audit = $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantA)
            ->where('event', 'citizen_identity.public_submitted')
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($audit);
        $this->assertSame('public', $audit['actor_type']);
        $this->assertNull($audit['actor_user_id']);
        $this->assertSame(64, strlen($audit['entry_hash']));
        $this->assertStringNotContainsString(
            self::NINU,
            $audit['context_json']
        );
        $this->assertStringNotContainsString(
            '+509' . self::PHONE,
            $audit['context_json']
        );

        $verification = (new AuditService(
            (new TenantContext())->set($this->tenantA),
            $this->db
        ))->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
    }

    public function testTrackingReferenceCanBeRecoveredByTenantAndNinu(): void
    {
        $result = $this->service($this->tenantA)->submit(
            self::NINU,
            self::PHONE,
            'public-test-v1',
            $this->documents
        );
        $tracking = new PublicIdentityTrackingService($this->db);

        $this->assertSame(
            $result['public_reference'],
            $tracking->referenceForNinu(
                '__public_submission_a__',
                self::NINU
            )
        );
        $this->assertNull(
            $tracking->referenceForNinu(
                '__public_submission_b__',
                self::NINU
            )
        );
    }

    public function testSameNinuIsIsolatedByTenant(): void
    {
        $a = $this->service($this->tenantA)->submit(
            self::NINU,
            self::PHONE,
            'public-test-v1',
            $this->documents
        );

        $b = $this->service($this->tenantB)->submit(
            self::NINU,
            self::PHONE,
            'public-test-v1',
            $this->documents
        );

        $identityA = $this->identity($this->tenantA, (int) $a['id']);
        $identityB = $this->identity($this->tenantB, (int) $b['id']);

        $this->assertNotNull($identityA);
        $this->assertNotNull($identityB);
        $this->assertNotSame(
            $identityA['ninu_fingerprint'],
            $identityB['ninu_fingerprint']
        );
        $this->assertSame(1, $this->identityCount($this->tenantA));
        $this->assertSame(1, $this->identityCount($this->tenantB));
    }

    public function testManualContactReviewIsPersistedAndAudited(): void
    {
        $result = $this->service($this->tenantA)->submit(
            self::NINU,
            self::PHONE,
            'public-test-v1',
            $this->documents,
            contactVerificationStatus:
                ContactVerificationStatus::MANUAL_REVIEW
        );

        $identity = $this->identity(
            $this->tenantA,
            (int) $result['id']
        );

        $this->assertNotNull($identity);
        $this->assertSame(
            ContactVerificationStatus::MANUAL_REVIEW,
            $identity['contact_verification_status']
        );
        $this->assertSame(
            ContactVerificationStatus::MANUAL_REVIEW,
            $result['contact_verification_status']
        );

        $event = $this->db
            ->table('identity_verification_events')
            ->select('context_json')
            ->where('tenant_id', $this->tenantA)
            ->where('citizen_identity_id', (int) $result['id'])
            ->where('event_type', 'identity.public_submitted')
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($event);
        $this->assertStringContainsString(
            'manual_review',
            (string) $event['context_json']
        );
    }

    public function testDuplicateIsRejectedWithoutExtraResidue(): void
    {
        $this->service($this->tenantA)->submit(
            self::NINU,
            self::PHONE,
            'public-test-v1',
            $this->documents
        );

        $exception = $this->captureException(
            fn () => $this->service($this->tenantA)->submit(
                self::NINU,
                self::PHONE,
                'public-test-v1',
                $this->documents
            )
        );

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );
        $this->assertSame(
            'Citizen identity already exists in the current tenant.',
            $exception->getMessage()
        );
        $this->assertSame(1, $this->identityCount($this->tenantA));
        $this->assertSame(3, $this->documentCount($this->tenantA));
        $this->assertSame(1, $this->eventCount($this->tenantA));
        $this->assertSame(1, $this->auditCount($this->tenantA));
    }

    public function testInvalidDocumentRollsBackDatabaseAndFiles(): void
    {
        $invalid = tempnam(sys_get_temp_dir(), 'civic-invalid-');

        if ($invalid === false) {
            throw new RuntimeException('Could not create invalid fixture.');
        }

        file_put_contents($invalid, 'not-an-image');

        $documents = $this->documents;
        $documents[VerificationDocumentWriteService::CIN_BACK] = $invalid;

        try {
            $exception = $this->captureException(
                fn () => $this->service($this->tenantA)->submit(
                    self::NINU,
                    self::PHONE,
                    'public-test-v1',
                    $documents
                )
            );
        } finally {
            @unlink($invalid);
        }

        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $exception
        );
        $this->assertSame(0, $this->identityCount($this->tenantA));
        $this->assertSame(0, $this->documentCount($this->tenantA));
        $this->assertSame(0, $this->eventCount($this->tenantA));
        $this->assertSame(0, $this->auditCount($this->tenantA));

        $root = WRITEPATH
            . 'uploads/identity/'
            . strtolower($this->tenantUuidA);

        $files = $this->filesRecursively($root);
        $this->assertSame([], $files);
    }

    private function service(int $tenantId): PublicIdentitySubmissionService
    {
        return new PublicIdentitySubmissionService(
            (new TenantContext())->set($tenantId),
            $this->db
        );
    }

    private function identity(int $tenantId, int $identityId): ?array
    {
        return $this->db
            ->table('citizen_identities')
            ->where('tenant_id', $tenantId)
            ->where('id', $identityId)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    private function identityCount(int $tenantId): int
    {
        return $this->db
            ->table('citizen_identities')
            ->where('tenant_id', $tenantId)
            ->countAllResults();
    }

    private function documentCount(int $tenantId): int
    {
        return $this->db
            ->table('verification_documents')
            ->where('tenant_id', $tenantId)
            ->countAllResults();
    }

    private function eventCount(int $tenantId): int
    {
        return $this->db
            ->table('identity_verification_events')
            ->where('tenant_id', $tenantId)
            ->countAllResults();
    }

    private function auditCount(int $tenantId): int
    {
        return $this->db
            ->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->countAllResults();
    }

    /**
     * @return array{0: int, 1: string}
     */
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

    /**
     * @return array<string, string>
     */
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
            $path = tempnam(sys_get_temp_dir(), 'civic-png-');

            if ($path === false) {
                throw new RuntimeException(
                    'Could not create synthetic PNG fixture.'
                );
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

        foreach ([
            'identity_verification_events',
            'verification_documents',
            'citizen_identities',
        ] as $table) {
            $this->db->query(
                "DELETE x FROM `{$table}` x "
                . 'INNER JOIN tenants t ON t.id = x.tenant_id '
                . "WHERE t.slug IN ('__public_submission_a__', "
                . "'__public_submission_b__')"
            );
        }

        $this->db->query(
            "DELETE FROM tenants WHERE slug IN ("
            . "'__public_submission_a__', "
            . "'__public_submission_b__')"
        );
    }

    /** @return list<string> */
    private function fixtureTenantUuids(): array
    {
        $rows = $this->db
            ->table('tenants')
            ->select('uuid')
            ->whereIn('slug', [
                '__public_submission_a__',
                '__public_submission_b__',
            ])
            ->get()
            ->getResultArray();

        return array_values(array_map(
            static fn (array $row): string => (string) $row['uuid'],
            $rows
        ));
    }

    /** @return list<string> */
    private function filesRecursively(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);
        return $files;
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
