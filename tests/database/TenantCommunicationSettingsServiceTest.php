<?php

namespace Tests\Database;

use App\Services\AdminBootstrapService;
use App\Services\TenantCommunicationSettingsService;
use App\Services\TenantContext;
use App\Services\TenantSecretCipher;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

final class TenantCommunicationSettingsServiceTest extends CIUnitTestCase
{
    private const TENANT_A = '__communication_settings_a__';
    private const TENANT_B = '__communication_settings_b__';
    private const EMAIL_A = '__communication_settings_a__@example.test';
    private const PASSWORD = 'Synthetic-Communication-Password-2026!';
    private const APP_KEY = 'test-key-test-key-test-key-test-key-test-key-test-key';

    private int $tenantA;
    private int $tenantB;
    private int $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = db_connect();
        $this->cleanupFixtures();
        $this->tenantA = $this->insertTenant(self::TENANT_A, 'Communication A');
        $this->tenantB = $this->insertTenant(self::TENANT_B, 'Communication B');
        $admin = (new AdminBootstrapService($this->db))->bootstrapFirstAdministrator(
            self::TENANT_A,
            self::EMAIL_A,
            'Administratè Kominikasyon',
            self::PASSWORD
        );
        $this->adminA = (int) $admin['user_id'];
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testProviderSecretsAreEncryptedAndTenantScoped(): void
    {
        $context = (new TenantContext())->set($this->tenantA);
        $service = new TenantCommunicationSettingsService(
            $context,
            $this->db,
            new TenantSecretCipher($context, self::APP_KEY)
        );

        $public = $service->saveForActor($this->adminA, [
            'whatsapp_enabled' => '1',
            'whatsapp_graph_version' => 'v26.0',
            'whatsapp_phone_number_id' => '123456789012345',
            'whatsapp_access_token' => 'synthetic-whatsapp-token-2026',
            'whatsapp_template_name' => 'otp_code',
            'whatsapp_template_language' => 'ht',
            'sms_enabled' => '1',
            'twilio_account_sid' => 'AC' . str_repeat('1', 32),
            'twilio_auth_token' => 'synthetic-twilio-token-2026',
            'twilio_from_number' => '+15005550006',
            'email_enabled' => '1',
            'smtp_host' => 'mail.example.test',
            'smtp_port' => '587',
            'smtp_crypto' => 'tls',
            'smtp_user' => 'mailer@example.test',
            'smtp_password' => 'synthetic-smtp-password-2026',
            'email_from_address' => 'noreply@example.test',
            'email_from_name' => 'Portail de test',
        ]);

        $this->assertTrue($public['whatsapp_secret_set']);
        $this->assertTrue($public['twilio_secret_set']);
        $this->assertTrue($public['smtp_secret_set']);
        $this->assertArrayNotHasKey('whatsapp_access_token', $public);
        $this->assertArrayNotHasKey('smtp_password', $public);

        $stored = $this->db->table('tenant_communication_settings')
            ->where('tenant_id', $this->tenantA)
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($stored);
        $this->assertStringStartsWith('v1.', (string) $stored['whatsapp_access_token_encrypted']);
        $this->assertStringNotContainsString(
            'synthetic-whatsapp-token-2026',
            (string) $stored['whatsapp_access_token_encrypted']
        );
        $this->assertSame(
            'synthetic-smtp-password-2026',
            $service->smtpConfiguration()['password'] ?? null
        );

        $foreignContext = (new TenantContext())->set($this->tenantB);
        $foreignService = new TenantCommunicationSettingsService(
            $foreignContext,
            $this->db,
            new TenantSecretCipher($foreignContext, self::APP_KEY)
        );

        $this->expectException(RuntimeException::class);
        $foreignService->readForActor($this->adminA);
    }

    private function insertTenant(string $slug, string $name): int
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'default_locale' => 'ht',
            'timezone' => 'America/Port-au-Prince',
        ]);

        return (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        $cleanupDb = $this->privilegedCleanupConnection();
        $cleanupDb->query('TRUNCATE TABLE `audit_logs`');
        $cleanupDb->close();

        $this->db->table('tenants')->whereIn('slug', [self::TENANT_A, self::TENANT_B])->delete();
        $this->db->table('users')->where('email', self::EMAIL_A)->delete();
    }

    private function privilegedCleanupConnection(): \mysqli
    {
        $username = getenv('MIGRATION_DB_USERNAME');
        $password = getenv('MIGRATION_DB_PASSWORD');
        $database = getenv('TEST_DATABASE');

        if (! is_string($username) || $username === ''
            || ! is_string($password) || $password === ''
            || ! is_string($database) || $database === '') {
            throw new RuntimeException('Privileged test DB credentials are missing.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return new \mysqli('db', $username, $password, $database, 3306);
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
