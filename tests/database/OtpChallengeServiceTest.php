<?php

namespace Tests\Database;

use App\Services\Otp\OtpChallengeService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Throwable;

final class OtpChallengeServiceTest extends CIUnitTestCase
{
    private const TENANT_A_SLUG = '__otp_challenge_a__';
    private const TENANT_B_SLUG = '__otp_challenge_b__';
    private const PHONE = '00000000';

    private int $tenantA;
    private int $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $this->cleanupFixtures();

        $this->tenantA = $this->insertTenant(
            self::TENANT_A_SLUG,
            'OTP Challenge A'
        );
        $this->tenantB = $this->insertTenant(
            self::TENANT_B_SLUG,
            'OTP Challenge B'
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testIssueStoresOnlyDerivativesAndCodeIsSingleUse(): void
    {
        $issued = $this->service($this->tenantA)
            ->issue(self::PHONE);

        $this->assertMatchesRegularExpression(
            '/^[0-9]{6}$/',
            $issued['code']
        );
        $this->assertSame(
            '+509' . self::PHONE,
            $issued['normalized_phone']
        );
        $this->assertSame(
            'whatsapp',
            $issued['requested_channel']
        );
        $this->assertSame(
            OtpChallengeService::TTL_SECONDS,
            $issued['ttl_seconds']
        );

        $row = $this->challenge(
            $this->tenantA,
            $issued['uuid']
        );

        $this->assertNotNull($row);
        $this->assertSame(
            64,
            strlen((string) $row['phone_fingerprint'])
        );
        $this->assertSame(
            64,
            strlen((string) $row['code_digest'])
        );
        $this->assertNotSame(
            $issued['code'],
            $row['code_digest']
        );
        $this->assertNotSame(
            $issued['normalized_phone'],
            $row['phone_fingerprint']
        );
        $this->assertSame(0, (int) $row['attempts_used']);
        $this->assertNull($row['consumed_at']);

        $verified = $this->service($this->tenantA)
            ->verify($issued['uuid'], $issued['code']);

        $this->assertTrue($verified['accepted']);
        $this->assertSame('accepted', $verified['reason']);

        $secondUse = $this->service($this->tenantA)
            ->verify($issued['uuid'], $issued['code']);

        $this->assertFalse($secondUse['accepted']);
        $this->assertSame('consumed', $secondUse['reason']);

        $stored = $this->challenge(
            $this->tenantA,
            $issued['uuid']
        );
        $this->assertNotNull($stored['consumed_at']);

        $auditRows = $this->db
            ->table('audit_logs')
            ->select('event, context_json')
            ->where('tenant_id', $this->tenantA)
            ->where('entity_type', 'otp_challenge')
            ->where('entity_id', $issued['uuid'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertSame(
            [
                'otp.challenge_created',
                'otp.challenge_verified',
            ],
            array_column($auditRows, 'event')
        );

        foreach ($auditRows as $auditRow) {
            $context = (string) $auditRow['context_json'];
            $this->assertStringNotContainsString(
                $issued['code'],
                $context
            );
            $this->assertStringNotContainsString(
                $issued['normalized_phone'],
                $context
            );
        }
    }

    public function testWrongCodesLockChallengeAtMaximumAttempts(): void
    {
        $issued = $this->service($this->tenantA)
            ->issue(self::PHONE);

        $wrong = $issued['code'] === '000000'
            ? '000001'
            : '000000';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = $this->service($this->tenantA)
                ->verify($issued['uuid'], $wrong);

            $this->assertFalse($result['accepted']);
            $this->assertSame('invalid_code', $result['reason']);
            $this->assertSame($attempt, $result['attempts_used']);
        }

        $locked = $this->service($this->tenantA)
            ->verify($issued['uuid'], $wrong);

        $this->assertFalse($locked['accepted']);
        $this->assertSame('locked', $locked['reason']);
        $this->assertSame(
            OtpChallengeService::MAX_ATTEMPTS,
            $locked['attempts_used']
        );

        $afterLock = $this->service($this->tenantA)
            ->verify($issued['uuid'], $issued['code']);

        $this->assertFalse($afterLock['accepted']);
        $this->assertSame('locked', $afterLock['reason']);

        $row = $this->challenge(
            $this->tenantA,
            $issued['uuid']
        );

        $this->assertSame(
            OtpChallengeService::MAX_ATTEMPTS,
            (int) $row['attempts_used']
        );
        $this->assertNotNull($row['locked_at']);
    }

    public function testCooldownReissueInvalidationAndExpiry(): void
    {
        $service = $this->service($this->tenantA);
        $first = $service->issue(self::PHONE);

        $cooldown = $this->captureException(
            fn () => $service->issue(self::PHONE)
        );

        $this->assertInstanceOf(RuntimeException::class, $cooldown);
        $this->assertSame(
            'OTP resend cooldown is active.',
            $cooldown->getMessage()
        );

        $this->db
            ->table('otp_challenges')
            ->where('tenant_id', $this->tenantA)
            ->where('uuid', $first['uuid'])
            ->update([
                'created_at' => gmdate(
                    'Y-m-d H:i:s',
                    time()
                    - OtpChallengeService::RESEND_COOLDOWN_SECONDS
                    - 1
                ),
            ]);

        $second = $service->issue(self::PHONE);

        $firstRow = $this->challenge(
            $this->tenantA,
            $first['uuid']
        );
        $this->assertNotNull($firstRow['invalidated_at']);

        $invalidated = $service->verify(
            $first['uuid'],
            $first['code']
        );
        $this->assertFalse($invalidated['accepted']);
        $this->assertSame('invalidated', $invalidated['reason']);

        $this->db
            ->table('otp_challenges')
            ->where('tenant_id', $this->tenantA)
            ->where('uuid', $second['uuid'])
            ->update([
                'expires_at' => gmdate(
                    'Y-m-d H:i:s',
                    time() - 1
                ),
            ]);

        $expired = $service->verify(
            $second['uuid'],
            $second['code']
        );

        $this->assertFalse($expired['accepted']);
        $this->assertSame('expired', $expired['reason']);
    }

    public function testPhoneRateLimitCapsIssuanceWithinOneHour(): void
    {
        $service = $this->service($this->tenantA);

        for (
            $index = 0;
            $index < OtpChallengeService::MAX_PER_PHONE_PER_HOUR;
            $index++
        ) {
            $issued = $service->issue(self::PHONE);

            $this->db
                ->table('otp_challenges')
                ->where('tenant_id', $this->tenantA)
                ->where('uuid', $issued['uuid'])
                ->update([
                    'created_at' => gmdate(
                        'Y-m-d H:i:s',
                        time()
                        - OtpChallengeService::RESEND_COOLDOWN_SECONDS
                        - 1
                    ),
                ]);
        }

        $limited = $this->captureException(
            fn () => $service->issue(self::PHONE)
        );

        $this->assertInstanceOf(RuntimeException::class, $limited);
        $this->assertSame(
            'OTP issue rate limit exceeded.',
            $limited->getMessage()
        );
        $this->assertSame(
            OtpChallengeService::MAX_PER_PHONE_PER_HOUR,
            $this->db
                ->table('otp_challenges')
                ->where('tenant_id', $this->tenantA)
                ->countAllResults()
        );
    }

    public function testSamePhoneIsTenantScopedAndCrossTenantUuidIsHidden(): void
    {
        $issuedA = $this->service($this->tenantA)
            ->issue(self::PHONE);
        $issuedB = $this->service($this->tenantB)
            ->issue(self::PHONE);

        $rowA = $this->challenge(
            $this->tenantA,
            $issuedA['uuid']
        );
        $rowB = $this->challenge(
            $this->tenantB,
            $issuedB['uuid']
        );

        $this->assertNotSame(
            $rowA['phone_fingerprint'],
            $rowB['phone_fingerprint']
        );

        $crossTenant = $this->service($this->tenantA)
            ->verify($issuedB['uuid'], $issuedB['code']);

        $this->assertFalse($crossTenant['accepted']);
        $this->assertSame('not_found', $crossTenant['reason']);

        $validB = $this->service($this->tenantB)
            ->verify($issuedB['uuid'], $issuedB['code']);

        $this->assertTrue($validB['accepted']);
    }

    private function service(int $tenantId): OtpChallengeService
    {
        return new OtpChallengeService(
            (new TenantContext())->set($tenantId),
            $this->db
        );
    }

    private function challenge(int $tenantId, string $uuid): array
    {
        $row = $this->db
            ->table('otp_challenges')
            ->where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new RuntimeException(
                'Synthetic OTP challenge fixture is missing.'
            );
        }

        return $row;
    }

    private function insertTenant(
        string $slug,
        string $name
    ): int {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'default_locale' => 'fr',
            'timezone' => 'America/Port-au-Prince',
        ]);

        return (int) $this->db->insertID();
    }

    private function cleanupFixtures(): void
    {
        $cleanupDb = $this->privilegedCleanupConnection();
        $cleanupDb->query('TRUNCATE TABLE `audit_logs`');
        $cleanupDb->close();

        $this->db->query(
            'DELETE c FROM otp_challenges c '
            . 'INNER JOIN tenants t ON t.id = c.tenant_id '
            . "WHERE t.slug IN ('"
            . self::TENANT_A_SLUG
            . "', '"
            . self::TENANT_B_SLUG
            . "')"
        );

        $this->db->table('tenants')->whereIn('slug', [
            self::TENANT_A_SLUG,
            self::TENANT_B_SLUG,
        ])->delete();
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
