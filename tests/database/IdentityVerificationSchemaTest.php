<?php

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use Throwable;

final class IdentityVerificationSchemaTest
    extends CIUnitTestCase
{
    private const TENANT_A =
        '__identity_schema_a__';

    private const TENANT_B =
        '__identity_schema_b__';

    private const USER_A =
        'identity-schema-a@invalid.example';

    private const USER_B =
        'identity-schema-b@invalid.example';

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

    public function testNinuFingerprintIsUniqueOnlyWithinTenant(): void
    {
        $fingerprint =
            str_repeat('a', 64);

        $this->insertIdentity(
            $this->tenantA,
            $fingerprint
        );

        $duplicate =
            $this->captureException(
                fn () =>
                    $this->insertIdentity(
                        $this->tenantA,
                        $fingerprint
                    )
            );

        $this->assertStringContainsString(
            'Duplicate entry',
            $duplicate->getMessage()
        );

        $foreignId =
            $this->insertIdentity(
                $this->tenantB,
                $fingerprint
            );

        $this->assertGreaterThan(
            0,
            $foreignId
        );

        $count = $this->db
            ->table('citizen_identities')
            ->where(
                'ninu_fingerprint',
                $fingerprint
            )
            ->countAllResults();

        $this->assertSame(
            2,
            $count
        );
    }

    public function testDocumentCannotCrossTenantBoundary(): void
    {
        $identityId =
            $this->insertIdentity(
                $this->tenantA,
                str_repeat('b', 64)
            );

        $this->insertDocument(
            $this->tenantA,
            $identityId,
            'cin_front',
            1
        );

        $crossTenant =
            $this->captureException(
                fn () =>
                    $this->insertDocument(
                        $this->tenantB,
                        $identityId,
                        'cin_front',
                        1
                    )
            );

        $this->assertNotSame(
            '',
            $crossTenant->getMessage()
        );

        $this->insertDocument(
            $this->tenantA,
            $identityId,
            'cin_front',
            2
        );

        $duplicateRevision =
            $this->captureException(
                fn () =>
                    $this->insertDocument(
                        $this->tenantA,
                        $identityId,
                        'cin_front',
                        2
                    )
            );

        $this->assertStringContainsString(
            'Duplicate entry',
            $duplicateRevision->getMessage()
        );

        $count = $this->db
            ->table('verification_documents')
            ->where(
                'tenant_id',
                $this->tenantA
            )
            ->where(
                'citizen_identity_id',
                $identityId
            )
            ->countAllResults();

        $this->assertSame(
            2,
            $count
        );
    }

    public function testVerificationEventActorMustBelongToTenant(): void
    {
        $identityId =
            $this->insertIdentity(
                $this->tenantA,
                str_repeat('c', 64)
            );

        $this->insertEvent(
            $this->tenantA,
            $identityId,
            $this->userA,
            '{}'
        );

        $foreignActor =
            $this->captureException(
                fn () =>
                    $this->insertEvent(
                        $this->tenantA,
                        $identityId,
                        $this->userB,
                        '{}'
                    )
            );

        $this->assertNotSame(
            '',
            $foreignActor->getMessage()
        );

        $count = $this->db
            ->table(
                'identity_verification_events'
            )
            ->where(
                'tenant_id',
                $this->tenantA
            )
            ->countAllResults();

        $this->assertSame(
            1,
            $count
        );
    }

    public function testVerificationEventRejectsInvalidJson(): void
    {
        $identityId =
            $this->insertIdentity(
                $this->tenantA,
                str_repeat('d', 64)
            );

        $invalid =
            $this->captureException(
                fn () =>
                    $this->insertEvent(
                        $this->tenantA,
                        $identityId,
                        null,
                        '{invalid'
                    )
            );

        $this->assertNotSame(
            '',
            $invalid->getMessage()
        );

        $this->insertEvent(
            $this->tenantA,
            $identityId,
            null,
            '{}'
        );

        $count = $this->db
            ->table(
                'identity_verification_events'
            )
            ->where(
                'tenant_id',
                $this->tenantA
            )
            ->countAllResults();

        $this->assertSame(
            1,
            $count
        );
    }

    private function insertIdentity(
        int $tenantId,
        string $fingerprint
    ): int {
        $this->db
            ->table('citizen_identities')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'tenant_id' =>
                    $tenantId,
                'ninu_ciphertext' =>
                    'v1.TEST-CIPHERTEXT',
                'ninu_fingerprint' =>
                    $fingerprint,
                'verification_status' =>
                    'pending',
                'consent_version' =>
                    'test-consent-v1',
                'consented_at' =>
                    gmdate('Y-m-d H:i:s'),
            ]);

        return (int)
            $this->db->insertID();
    }

    private function insertDocument(
        int $tenantId,
        int $identityId,
        string $type,
        int $revision
    ): int {
        $this->db
            ->table('verification_documents')
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'tenant_id' =>
                    $tenantId,
                'citizen_identity_id' =>
                    $identityId,
                'document_type' =>
                    $type,
                'revision_no' =>
                    $revision,
                'storage_ref' =>
                    'test://opaque/document',
                'content_type' =>
                    'application/octet-stream',
                'size_bytes' =>
                    123,
                'sha256' =>
                    str_repeat('e', 64),
            ]);

        return (int)
            $this->db->insertID();
    }

    private function insertEvent(
        int $tenantId,
        int $identityId,
        ?int $actorUserId,
        ?string $context
    ): int {
        $this->db
            ->table(
                'identity_verification_events'
            )
            ->insert([
                'uuid' =>
                    $this->uuid(),
                'tenant_id' =>
                    $tenantId,
                'citizen_identity_id' =>
                    $identityId,
                'event_type' =>
                    'verification.tested',
                'from_status' =>
                    'pending',
                'to_status' =>
                    'under_review',
                'actor_user_id' =>
                    $actorUserId,
                'context_json' =>
                    $context,
                'occurred_at' =>
                    gmdate('Y-m-d H:i:s'),
            ]);

        return (int)
            $this->db->insertID();
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
                    'Identity Schema A',
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
                    'Identity Schema B',
            ]);

        $this->tenantB =
            (int) $this->db->insertID();

        $this->userA =
            $this->insertUser(
                self::USER_A,
                'Identity Schema User A'
            );

        $this->userB =
            $this->insertUser(
                self::USER_B,
                'Identity Schema User B'
            );

        $this->insertMembership(
            $this->tenantA,
            $this->userA
        );

        $this->insertMembership(
            $this->tenantB,
            $this->userB
        );
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

    private function captureException(
        callable $callback
    ): Throwable {
        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail(
            'Expected database operation to fail.'
        );
    }

    private function cleanupFixtures(): void
    {
        $this->db->query(
            "DELETE ive
             FROM identity_verification_events ive
             INNER JOIN tenants t
                 ON t.id = ive.tenant_id
             WHERE t.slug IN (
                '__identity_schema_a__',
                '__identity_schema_b__'
             )"
        );

        $this->db->query(
            "DELETE vd
             FROM verification_documents vd
             INNER JOIN tenants t
                 ON t.id = vd.tenant_id
             WHERE t.slug IN (
                '__identity_schema_a__',
                '__identity_schema_b__'
             )"
        );

        $this->db->query(
            "DELETE ci
             FROM citizen_identities ci
             INNER JOIN tenants t
                 ON t.id = ci.tenant_id
             WHERE t.slug IN (
                '__identity_schema_a__',
                '__identity_schema_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__identity_schema_a__',
                '__identity_schema_b__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'identity-schema-a@invalid.example',
                'identity-schema-b@invalid.example'
             )"
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
