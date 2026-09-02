<?php

namespace Tests\Database;

use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpChannelRouter;
use App\Services\Otp\OtpDeliveryRequest;
use App\Services\Otp\OtpDeliveryResult;
use App\Services\Otp\OtpTransportInterface;
use App\Services\Otp\PublicPhoneOtpFlowService;
use App\Services\Otp\PublicPhoneOtpProofService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Throwable;

final class PublicPhoneOtpFlowServiceTest extends CIUnitTestCase
{
    private const TENANT_A_SLUG = '__otp_public_flow_a__';
    private const TENANT_B_SLUG = '__otp_public_flow_b__';
    private const PHONE = '00000000';

    private int $tenantA;
    private int $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        service('session')->remove('public_phone_otp_proof');
        $this->cleanupFixtures();

        $this->tenantA = $this->insertTenant(
            self::TENANT_A_SLUG,
            'OTP Public Flow A'
        );
        $this->tenantB = $this->insertTenant(
            self::TENANT_B_SLUG,
            'OTP Public Flow B'
        );
    }

    protected function tearDown(): void
    {
        service('session')->remove('public_phone_otp_proof');
        $this->cleanupFixtures();
        parent::tearDown();
    }

    public function testAcceptedDeliveryCreatesVerifiedTenantScopedProof(): void
    {
        $transport = new CapturingOtpTransport(
            OtpChannel::WHATSAPP,
            true,
            'wa-message-1'
        );

        $flow = $this->flow(
            $this->tenantA,
            new OtpChannelRouter([$transport])
        );

        $requested = $flow->request(self::PHONE);

        $this->assertSame(
            'whatsapp',
            $requested['delivered_channel']
        );
        $this->assertArrayNotHasKey('code', $requested);
        $this->assertArrayNotHasKey('normalized_phone', $requested);
        $this->assertMatchesRegularExpression(
            '/^[0-9]{6}$/',
            $transport->lastCode
        );

        $row = $this->challenge(
            $this->tenantA,
            $requested['challenge_uuid']
        );

        $this->assertSame('whatsapp', $row['delivered_channel']);
        $this->assertSame('wa-message-1', $row['provider_message_ref']);
        $this->assertNull($row['consumed_at']);
        $this->assertNull($row['invalidated_at']);

        $proofBefore = $flow->proofService()->snapshot();
        $this->assertNotNull($proofBefore);
        $this->assertNull($proofBefore['verified_at']);

        $serialized = json_encode($proofBefore, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            '+509' . self::PHONE,
            $serialized
        );
        $this->assertStringNotContainsString(
            $transport->lastCode,
            $serialized
        );

        $verified = $flow->verify(
            $requested['challenge_uuid'],
            $transport->lastCode
        );

        $this->assertTrue($verified['accepted']);
        $this->assertTrue(
            $flow->proofService()->hasVerifiedPhone(self::PHONE)
        );

        $tenantBProof = new PublicPhoneOtpProofService(
            (new TenantContext())->set($this->tenantB),
            service('session')
        );

        $this->assertFalse(
            $tenantBProof->hasVerifiedPhone(self::PHONE)
        );
        $this->assertFalse(
            $flow->proofService()->hasVerifiedPhone('00000001')
        );

        $flow->proofService()->consumeVerifiedPhone(self::PHONE);

        $this->assertFalse(
            $flow->proofService()->hasVerifiedPhone(self::PHONE)
        );

        $auditRows = $this->db
            ->table('audit_logs')
            ->select('event, context_json')
            ->where('tenant_id', $this->tenantA)
            ->where('entity_type', 'otp_challenge')
            ->where('entity_id', $requested['challenge_uuid'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertSame(
            [
                'otp.challenge_created',
                'otp.challenge_delivered',
                'otp.challenge_verified',
            ],
            array_column($auditRows, 'event')
        );

        foreach ($auditRows as $auditRow) {
            $context = (string) $auditRow['context_json'];
            $this->assertStringNotContainsString(
                '+509' . self::PHONE,
                $context
            );
            $this->assertStringNotContainsString(
                $transport->lastCode,
                $context
            );
        }
    }

    public function testSmsFallbackIsPersistedAsDeliveredChannel(): void
    {
        $calls = [];
        $whatsApp = new CapturingOtpTransport(
            OtpChannel::WHATSAPP,
            false,
            null,
            $calls
        );
        $sms = new CapturingOtpTransport(
            OtpChannel::SMS,
            true,
            'sms-message-1',
            $calls
        );

        $flow = $this->flow(
            $this->tenantA,
            new OtpChannelRouter([$whatsApp, $sms])
        );

        $requested = $flow->request(self::PHONE);

        $this->assertSame('sms', $requested['delivered_channel']);
        $this->assertSame(['whatsapp', 'sms'], $calls);

        $row = $this->challenge(
            $this->tenantA,
            $requested['challenge_uuid']
        );

        $this->assertSame('sms', $row['delivered_channel']);
        $this->assertSame('sms-message-1', $row['provider_message_ref']);
    }

    public function testNoTransportInvalidatesChallengeAndCreatesNoProof(): void
    {
        $flow = $this->flow(
            $this->tenantA,
            new OtpChannelRouter([])
        );

        $exception = $this->captureException(
            fn () => $flow->request(self::PHONE)
        );

        $this->assertInstanceOf(RuntimeException::class, $exception);

        $row = $this->db
            ->table('otp_challenges')
            ->where('tenant_id', $this->tenantA)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        $this->assertNotNull($row);
        $this->assertNotNull($row['invalidated_at']);
        $this->assertNull($row['delivered_channel']);
        $this->assertNull($flow->proofService()->snapshot());

        $events = $this->db
            ->table('audit_logs')
            ->select('event')
            ->where('tenant_id', $this->tenantA)
            ->where('entity_type', 'otp_challenge')
            ->where('entity_id', (string) $row['uuid'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $this->assertSame(
            [
                'otp.challenge_created',
                'otp.challenge_delivery_failed',
            ],
            array_column($events, 'event')
        );
    }

    private function flow(
        int $tenantId,
        OtpChannelRouter $router
    ): PublicPhoneOtpFlowService {
        return new PublicPhoneOtpFlowService(
            (new TenantContext())->set($tenantId),
            $router
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
                'Synthetic OTP public flow challenge is missing.'
            );
        }

        return $row;
    }

    private function insertTenant(string $slug, string $name): int
    {
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

final class CapturingOtpTransport implements OtpTransportInterface
{
    public string $lastCode = '';

    /** @var array<int, string>|null */
    private ?array $calls = null;

    public function __construct(
        private readonly OtpChannel $channelValue,
        private readonly bool $acceptedValue,
        private readonly ?string $providerMessageId = null,
        ?array &$calls = null
    ) {
        if ($calls !== null) {
            $this->calls =& $calls;
        }
    }

    public function channel(): OtpChannel
    {
        return $this->channelValue;
    }

    public function deliver(
        OtpDeliveryRequest $request
    ): OtpDeliveryResult {
        $this->lastCode = $request->code;

        if ($this->calls !== null) {
            $this->calls[] = $this->channelValue->value;
        }

        if ($this->acceptedValue) {
            return OtpDeliveryResult::accepted(
                $this->channelValue,
                $this->providerMessageId
            );
        }

        return OtpDeliveryResult::rejected(
            $this->channelValue,
            'synthetic_rejection'
        );
    }
}
