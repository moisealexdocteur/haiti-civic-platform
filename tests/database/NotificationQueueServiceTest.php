<?php

namespace Tests\Database;

use App\Services\NotificationQueueService;
use App\Services\TenantContext;
use App\Services\TenantSecretCipher;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class NotificationQueueServiceTest extends CIUnitTestCase
{
    private const SLUG = '__notification_queue__';
    private const FOREIGN_SLUG = '__notification_queue_foreign__';
    private const FOREIGN_EMAIL = '__notification_queue_foreign__@example.test';
    private int $tenantId;
    private int $foreignUserId;
    private int $foreignIdentityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = db_connect();
        $this->cleanupFixtures();
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(), 'slug' => self::SLUG,
            'name' => 'Notification Test', 'status' => 'active',
            'default_locale' => 'ht', 'timezone' => 'America/Port-au-Prince',
        ]);
        $this->tenantId = (int) $this->db->insertID();

        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(), 'slug' => self::FOREIGN_SLUG,
            'name' => 'Foreign Notification Test', 'status' => 'active',
            'default_locale' => 'fr', 'timezone' => 'America/Port-au-Prince',
        ]);
        $foreignTenantId = (int) $this->db->insertID();

        $this->db->table('users')->insert([
            'uuid' => $this->uuid(), 'email' => self::FOREIGN_EMAIL,
            'display_name' => 'Foreign Recipient', 'status' => 'active', 'locale' => 'fr',
        ]);
        $this->foreignUserId = (int) $this->db->insertID();
        $this->db->table('tenant_users')->insert([
            'tenant_id' => $foreignTenantId, 'user_id' => $this->foreignUserId,
            'status' => 'active', 'is_owner' => 0,
        ]);

        $this->db->table('citizen_identities')->insert([
            'uuid' => $this->uuid(), 'tenant_id' => $foreignTenantId,
            'ninu_ciphertext' => 'v1.TEST-CIPHERTEXT',
            'ninu_fingerprint' => str_repeat('f', 64),
            'verification_status' => 'pending', 'consent_version' => 'test-v1',
            'consented_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->foreignIdentityId = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testMessageIsEncryptedAndIdempotent(): void
    {
        $context = (new TenantContext())->set($this->tenantId);
        $queue = new NotificationQueueService($context, $this->db);
        $first = $queue->enqueue(
            'identity.submitted.citizen', 'submissionCitizen', 'citizen', 'fr',
            ['email' => 'citoyen@example.test', 'phone' => '+50935123456'],
            ['Marie', 'DOS-TEST-1234', 'https://portal.example.test/swiv/DOS-TEST-1234'],
            'identity:42:submission:citizen'
        );
        $second = $queue->enqueue(
            'identity.submitted.citizen', 'submissionCitizen', 'citizen', 'fr',
            ['email' => 'citoyen@example.test', 'phone' => '+50935123456'],
            ['Marie', 'DOS-TEST-1234', 'https://portal.example.test/swiv/DOS-TEST-1234'],
            'identity:42:submission:citizen'
        );

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $this->db->table('notification_messages')
            ->where('tenant_id', $this->tenantId)->countAllResults());
        $row = $this->db->table('notification_messages')->where('id', $first['id'])
            ->get()->getFirstRow('array');
        $this->assertIsArray($row);
        $stored = implode('|', [
            (string) $row['recipient_ciphertext'], (string) $row['subject_ciphertext'],
            (string) $row['body_ciphertext'],
        ]);
        $this->assertStringNotContainsString('citoyen@example.test', $stored);
        $this->assertStringNotContainsString('DOS-TEST-1234', $stored);

        $cipher = new TenantSecretCipher($context);
        $body = $cipher->decrypt('notification.body.' . $first['uuid'], (string) $row['body_ciphertext']);
        $this->assertStringContainsString('DOS-TEST-1234', $body);
        $this->assertSame('ci***@example.test', $row['recipient_masked']);
    }

    public function testMessageRequiresAtLeastOneDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NotificationQueueService((new TenantContext())->set($this->tenantId), $this->db))
            ->enqueue('test.event', 'confirmation', 'system', 'ht', [], ['DOS', 'https://example.test'], 'empty');
    }

    public function testRecipientUserCannotCrossTenantBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NotificationQueueService((new TenantContext())->set($this->tenantId), $this->db))
            ->enqueue(
                'test.event', 'confirmation', 'administrator', 'fr',
                ['email' => self::FOREIGN_EMAIL], ['DOS-TEST', 'https://example.test'],
                'foreign-user', recipientUserId: $this->foreignUserId
            );
    }

    public function testCitizenDossierCannotCrossTenantBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NotificationQueueService((new TenantContext())->set($this->tenantId), $this->db))
            ->enqueue(
                'test.event', 'confirmation', 'citizen', 'fr',
                ['email' => 'citizen@example.test'], ['DOS-TEST', 'https://example.test'],
                'foreign-dossier', citizenIdentityId: $this->foreignIdentityId
            );
    }

    private function cleanupFixtures(): void
    {
        $tenantIds = array_map(
            'intval',
            array_column(
                $this->db->table('tenants')->select('id')
                    ->whereIn('slug', [self::SLUG, self::FOREIGN_SLUG])
                    ->get()->getResultArray(),
                'id'
            )
        );

        if ($tenantIds !== []) {
            $this->db->table('notification_messages')->whereIn('tenant_id', $tenantIds)->delete();
            $this->db->table('citizen_identities')->whereIn('tenant_id', $tenantIds)->delete();
            $this->db->table('tenant_users')->whereIn('tenant_id', $tenantIds)->delete();
        }

        $this->db->table('tenants')->whereIn('slug', [self::SLUG, self::FOREIGN_SLUG])->delete();
        $this->db->table('users')->where('email', self::FOREIGN_EMAIL)->delete();
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
