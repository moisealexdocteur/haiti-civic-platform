<?php

namespace Tests\Database;

use App\Services\AdminAuthService;
use App\Services\AdminBootstrapService;
use App\Services\AdminPasswordResetService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

final class AdminPasswordResetServiceTest extends CIUnitTestCase
{
    private const TENANT_SLUG = '__admin_password_reset__';
    private const EMAIL = '__admin_password_reset__@example.test';
    private const OLD_PASSWORD = 'Synthetic-Old-Password-2026!';
    private const NEW_PASSWORD = 'Synthetic-New-Password-2026!';

    private int $tenantId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = db_connect();
        $this->cleanupFixtures();

        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => self::TENANT_SLUG,
            'name' => 'Password Reset Test',
            'status' => 'active',
            'default_locale' => 'ht',
            'timezone' => 'America/Port-au-Prince',
        ]);
        $this->tenantId = (int) $this->db->insertID();

        $admin = (new AdminBootstrapService($this->db))->bootstrapFirstAdministrator(
            self::TENANT_SLUG,
            self::EMAIL,
            'Administratè Tès',
            self::OLD_PASSWORD
        );
        $this->userId = (int) $admin['user_id'];
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testResetLinkIsDeliveredOnceAndRevokesExistingSessions(): void
    {
        $deliveredUrl = null;
        $service = new AdminPasswordResetService(
            $this->db,
            static function (array $membership, string $url) use (&$deliveredUrl): bool {
                if ($membership['email'] !== self::EMAIL) {
                    return false;
                }

                $deliveredUrl = $url;
                return true;
            }
        );

        $this->assertTrue($service->request(
            self::TENANT_SLUG,
            self::EMAIL,
            'https://portal.example.test/admin/password/reset'
        ));
        $this->assertIsString($deliveredUrl);

        parse_str((string) parse_url($deliveredUrl, PHP_URL_QUERY), $query);
        $requestUuid = (string) ($query['demande'] ?? '');
        $token = (string) ($query['jeton'] ?? '');

        $this->assertSame(self::TENANT_SLUG, $query['organisation'] ?? null);
        $this->assertTrue($service->isUsable(self::TENANT_SLUG, $requestUuid, $token));

        $auth = new AdminAuthService($this->db);
        $this->assertTrue($auth->sessionIsActive(
            $this->userId,
            $this->tenantId,
            self::TENANT_SLUG,
            1
        ));

        $this->assertTrue($service->reset(
            self::TENANT_SLUG,
            $requestUuid,
            $token,
            self::NEW_PASSWORD
        ));
        $this->assertFalse($service->isUsable(self::TENANT_SLUG, $requestUuid, $token));
        $this->assertFalse($service->reset(
            self::TENANT_SLUG,
            $requestUuid,
            $token,
            self::NEW_PASSWORD
        ));
        $this->assertFalse($auth->sessionIsActive(
            $this->userId,
            $this->tenantId,
            self::TENANT_SLUG,
            1
        ));
        $this->assertNull($auth->authenticate(
            self::TENANT_SLUG,
            self::EMAIL,
            self::OLD_PASSWORD
        ));
        $this->assertNotNull($auth->authenticate(
            self::TENANT_SLUG,
            self::EMAIL,
            self::NEW_PASSWORD
        ));

        $events = $this->db->table('audit_logs')
            ->select('event')
            ->where('tenant_id', $this->tenantId)
            ->whereIn('event', [
                'admin.password_reset_requested',
                'admin.password_reset_completed',
            ])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertSame(
            ['admin.password_reset_requested', 'admin.password_reset_completed'],
            array_column($events, 'event')
        );
    }

    private function cleanupFixtures(): void
    {
        $cleanupDb = $this->privilegedCleanupConnection();
        $cleanupDb->query('TRUNCATE TABLE `audit_logs`');
        $cleanupDb->close();

        $this->db->table('tenants')->where('slug', self::TENANT_SLUG)->delete();
        $this->db->table('users')->where('email', self::EMAIL)->delete();
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
