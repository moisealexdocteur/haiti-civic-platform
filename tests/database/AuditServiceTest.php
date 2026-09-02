<?php

namespace Tests\Database;

use App\Services\AuditService;
use App\Services\TenantContext;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class AuditServiceTest extends CIUnitTestCase
{
    private int $tenantId;
    private int $userId;

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

    public function testRecordsChainedAuditEntries(): void
    {
        $service = $this->service();

        $firstId = $service->record(
            'organization.created',
            $this->userId,
            'user',
            'organization',
            '100',
            null,
            null,
            [
                'z' => 2,
                'a' => 1,
            ]
        );

        $secondId = $service->record(
            'organization.updated',
            $this->userId,
            'user',
            'organization',
            '100'
        );

        $this->assertGreaterThan(0, $firstId);
        $this->assertGreaterThan($firstId, $secondId);

        $rows = $this->db
            ->table('audit_logs')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertCount(2, $rows);

        $this->assertNull($rows[0]['prev_hash']);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $rows[0]['entry_hash']
        );

        $this->assertSame(
            $rows[0]['entry_hash'],
            $rows[1]['prev_hash']
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $rows[1]['entry_hash']
        );

        $this->assertSame(
            '{"a":1,"z":2}',
            $rows[0]['context_json']
        );

        $verification =
            $service->verifyCurrentTenantChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(2, $verification['count']);
        $this->assertNull($verification['broken_id']);
        $this->assertNull($verification['reason']);
    }

    public function testUpdateOfAuditLogIsRejected(): void
    {
        $id = $this->service()->record(
            'organization.created',
            $this->userId
        );

        $this->expectException(DatabaseException::class);

        $this->db
            ->table('audit_logs')
            ->where('id', $id)
            ->update([
                'event' => 'tampered.event',
            ]);
    }

    public function testDeleteOfAuditLogIsRejected(): void
    {
        $id = $this->service()->record(
            'organization.created',
            $this->userId
        );

        $this->expectException(DatabaseException::class);

        $this->db
            ->table('audit_logs')
            ->where('id', $id)
            ->delete();
    }

    public function testActorHardDeleteIsRestricted(): void
    {
        $this->service()->record(
            'organization.created',
            $this->userId
        );

        $this->expectException(DatabaseException::class);

        $this->db
            ->table('users')
            ->where('id', $this->userId)
            ->delete();
    }

    public function testVerificationDetectsBrokenInjectedEntry(): void
    {
        $service = $this->service();

        $service->record(
            'organization.created',
            $this->userId
        );

        $this->db->table('audit_logs')->insert([
            'tenant_id'    => $this->tenantId,
            'actor_type'   => 'system',
            'event'        => 'injected.invalid',
            'request_id'   =>
                '00000000-0000-4000-8000-000000000001',
            'context_json' => '{}',
            'prev_hash'    => str_repeat('0', 64),
            'entry_hash'   => str_repeat('f', 64),
            'occurred_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $verification =
            $service->verifyCurrentTenantChain();

        $this->assertFalse($verification['valid']);

        $this->assertSame(
            'prev_hash_mismatch',
            $verification['reason']
        );

        $this->assertNotNull(
            $verification['broken_id']
        );
    }

    public function testUserActorRequiresUserId(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'A user actor requires an actor user ID.'
        );

        $this->service()->record(
            'organization.created'
        );
    }

    public function testForeignTenantActorIsRejected(): void
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__audit_foreign_tenant__',
            'name' => 'Audit Foreign Tenant',
        ]);

        $foreignTenantId =
            (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'uuid' => $this->uuid(),
            'email' => 'audit-foreign@invalid.example',
            'display_name' => 'Audit Foreign User',
        ]);

        $foreignUserId =
            (int) $this->db->insertID();

        $this->db->table('tenant_users')->insert([
            'tenant_id' => $foreignTenantId,
            'user_id'   => $foreignUserId,
            'status'    => 'active',
        ]);

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Actor user is not an active member '
            . 'of the current tenant.'
        );

        $this->service()->record(
            'organization.created',
            $foreignUserId
        );
    }

    public function testRuntimeDatabaseUserHasNoDdlPrivileges(): void
    {
        $rows = $this->db
            ->query(
                'SHOW GRANTS FOR CURRENT_USER()'
            )
            ->getResultArray();

        $grants = [];

        foreach ($rows as $row) {
            $grants[] = (string) array_values($row)[0];
        }

        $grantText = implode("\n", $grants);

        $this->assertStringContainsString(
            'SELECT',
            $grantText
        );

        $this->assertStringContainsString(
            'INSERT',
            $grantText
        );

        $this->assertStringContainsString(
            'UPDATE',
            $grantText
        );

        $this->assertStringContainsString(
            'DELETE',
            $grantText
        );

        $this->assertStringNotContainsString(
            'ALL PRIVILEGES',
            $grantText
        );

        $this->assertStringNotContainsString(
            'TRIGGER',
            $grantText
        );

        $this->assertStringNotContainsString(
            'ALTER',
            $grantText
        );

        $this->assertStringNotContainsString(
            'DROP',
            $grantText
        );
    }

    public function testRuntimeDatabaseUserCannotTruncateAuditLog(): void
    {
        $this->service()->record(
            'organization.created',
            $this->userId
        );

        $this->expectException(
            DatabaseException::class
        );

        $this->db->query(
            'TRUNCATE TABLE `audit_logs`'
        );
    }

    private function service(): AuditService
    {
        $context = (new TenantContext())
            ->set($this->tenantId);

        return new AuditService(
            $context,
            $this->db
        );
    }

    private function createFixtures(): void
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => '__audit_test_tenant__',
            'name' => 'Audit Test Tenant',
        ]);

        $this->tenantId =
            (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'uuid'         => $this->uuid(),
            'email'        => 'audit-test@invalid.example',
            'display_name' => 'Audit Test User',
        ]);

        $this->userId =
            (int) $this->db->insertID();

        $this->db->table('tenant_users')->insert([
            'tenant_id' => $this->tenantId,
            'user_id'   => $this->userId,
            'status'    => 'active',
        ]);
    }

    private function cleanupFixtures(): void
    {
        /*
         * Test database only.
         *
         * TRUNCATE does not execute DELETE triggers and is necessary
         * to clean an append-only table between isolated tests.
         */
        $cleanupDb =
            $this->privilegedCleanupConnection();

        $cleanupDb->query(
            'TRUNCATE TABLE `audit_logs`'
        );

        $cleanupDb->close();

        $this->db->query(
            "DELETE FROM tenants
             WHERE slug IN (
                '__audit_test_tenant__',
                '__audit_foreign_tenant__'
             )"
        );

        $this->db->query(
            "DELETE FROM users
             WHERE email IN (
                'audit-test@invalid.example',
                'audit-foreign@invalid.example'
             )"
        );
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
            throw new \RuntimeException(
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
