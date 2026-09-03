<?php

namespace Tests\Database;

use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpChannelRouter;
use App\Services\Otp\OtpDeliveryRequest;
use App\Services\Otp\OtpDeliveryResult;
use App\Services\Otp\OtpTransportInterface;
use App\Services\Otp\PublicPhoneOtpFlowService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Throwable;

final class PublicEmailOtpFallbackTest extends CIUnitTestCase
{
    private const TENANT_SLUG = '__otp_email_fallback__';
    private const PHONE = '00000000';
    private const EMAIL = 'citizen@example.test';

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        service('session')->remove('public_phone_otp_proof');
        $this->cleanupFixtures();
        $this->tenantId = $this->insertTenant();
    }

    protected function tearDown(): void
    {
        service('session')->remove('public_phone_otp_proof');
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testEmailOnlyDeliveryCreatesContactProof(): void
    {
        $transport = new CapturingEmailTransport();
        $flow = new PublicPhoneOtpFlowService(
            (new TenantContext())->set($this->tenantId),
            new OtpChannelRouter([$transport])
        );

        $requested = $flow->request(
            self::PHONE,
            null,
            self::EMAIL
        );

        $this->assertSame('email', $requested['delivered_channel']);
        $this->assertMatchesRegularExpression(
            '/^[0-9]{6}$/D',
            $transport->lastCode
        );
        $this->assertSame(self::EMAIL, $transport->lastEmail);

        $row = $this->db
            ->table('otp_challenges')
            ->where('tenant_id', $this->tenantId)
            ->where('uuid', $requested['challenge_uuid'])
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($row);
        $this->assertSame('email', $row['requested_channel']);
        $this->assertSame('email', $row['delivered_channel']);

        $verified = $flow->verify(
            $requested['challenge_uuid'],
            $transport->lastCode
        );

        $this->assertTrue($verified['accepted']);
        $this->assertTrue(
            $flow->proofService()->hasVerifiedContact(
                self::PHONE,
                self::EMAIL
            )
        );
        $this->assertFalse(
            $flow->proofService()->hasVerifiedContact(
                self::PHONE,
                'other@example.test'
            )
        );
        $this->assertFalse(
            $flow->proofService()->hasVerifiedPhone(self::PHONE)
        );

        $proof = $flow->proofService()->snapshot();
        $serialized = json_encode($proof, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::EMAIL, $serialized);
        $this->assertStringNotContainsString(
            '+509' . self::PHONE,
            $serialized
        );
        $this->assertStringNotContainsString(
            $transport->lastCode,
            $serialized
        );
    }

    public function testEmailOnlyFlowRequiresEmailBeforeIssuingChallenge(): void
    {
        $flow = new PublicPhoneOtpFlowService(
            (new TenantContext())->set($this->tenantId),
            new OtpChannelRouter([new CapturingEmailTransport()])
        );

        $exception = $this->captureException(
            fn () => $flow->request(self::PHONE)
        );

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'OTP email recipient is required.',
            $exception->getMessage()
        );
        $this->assertSame(
            0,
            $this->db
                ->table('otp_challenges')
                ->where('tenant_id', $this->tenantId)
                ->countAllResults()
        );
    }

    private function insertTenant(): int
    {
        $this->db->table('tenants')->insert([
            'uuid' => $this->uuid(),
            'slug' => self::TENANT_SLUG,
            'name' => 'OTP Email Fallback',
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
            . "WHERE t.slug = '" . self::TENANT_SLUG . "'"
        );

        $this->db
            ->table('tenants')
            ->where('slug', self::TENANT_SLUG)
            ->delete();
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

final class CapturingEmailTransport implements OtpTransportInterface
{
    public string $lastCode = '';
    public string $lastEmail = '';

    public function channel(): OtpChannel
    {
        return OtpChannel::EMAIL;
    }

    public function deliver(
        OtpDeliveryRequest $request
    ): OtpDeliveryResult {
        $this->lastCode = $request->code;
        $this->lastEmail = (string) $request->normalizedEmail;

        return OtpDeliveryResult::accepted(OtpChannel::EMAIL);
    }
}
