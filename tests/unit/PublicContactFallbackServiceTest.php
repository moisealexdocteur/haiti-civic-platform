<?php

namespace Tests\Unit;

use App\Services\Otp\OtpChallengeCrypto;
use App\Services\Otp\PublicContactFallbackService;
use App\Services\TenantContext;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class PublicContactFallbackServiceTest extends CIUnitTestCase
{
    private const PHONE = '00000000';

    protected function setUp(): void
    {
        parent::setUp();
        service('session')->remove('public_contact_fallback');
    }

    protected function tearDown(): void
    {
        service('session')->remove('public_contact_fallback');
        parent::tearDown();
    }

    public function testOfferAndAcceptanceAreBoundToTenantAndPhone(): void
    {
        $tenantA = (new TenantContext())->set(101);
        $tenantB = (new TenantContext())->set(202);
        $serviceA = $this->service($tenantA);

        $serviceA->offer(self::PHONE);

        $snapshot = $serviceA->snapshot();
        $this->assertNotNull($snapshot);
        $this->assertNull($snapshot['accepted_at']);
        $this->assertStringNotContainsString(
            '+509' . self::PHONE,
            json_encode($snapshot, JSON_THROW_ON_ERROR)
        );

        $serviceA->accept(self::PHONE);

        $this->assertTrue($serviceA->hasAccepted(self::PHONE));
        $this->assertFalse($serviceA->hasAccepted('00000001'));
        $this->assertFalse(
            $this->service($tenantB)->hasAccepted(self::PHONE)
        );
    }

    public function testAcceptanceRequiresARecordedProviderFailure(): void
    {
        $service = $this->service((new TenantContext())->set(303));

        $this->expectException(InvalidArgumentException::class);
        $service->accept(self::PHONE);
    }

    private function service(
        TenantContext $tenantContext
    ): PublicContactFallbackService {
        return new PublicContactFallbackService(
            $tenantContext,
            service('session'),
            null,
            new OtpChallengeCrypto(
                $tenantContext,
                str_repeat('a', 32)
            )
        );
    }
}
