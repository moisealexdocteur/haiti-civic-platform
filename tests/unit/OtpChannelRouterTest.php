<?php

namespace Tests\Unit;

use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpChannelRouter;
use App\Services\Otp\OtpDeliveryRequest;
use App\Services\Otp\OtpDeliveryResult;
use App\Services\Otp\OtpTransportInterface;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

final class OtpChannelRouterTest extends CIUnitTestCase
{
    public function testWhatsAppIsPreferredWhenAccepted(): void
    {
        $calls = [];

        $router = new OtpChannelRouter([
            $this->transport(
                OtpChannel::WHATSAPP,
                true,
                $calls
            ),
            $this->transport(
                OtpChannel::SMS,
                true,
                $calls
            ),
        ]);

        $result = $router->deliver($this->request());

        $this->assertTrue($result->accepted);
        $this->assertSame(OtpChannel::WHATSAPP, $result->channel);
        $this->assertSame(['whatsapp'], $calls);
    }

    public function testSmsIsFallbackWhenWhatsAppRejects(): void
    {
        $calls = [];

        $router = new OtpChannelRouter([
            $this->transport(
                OtpChannel::WHATSAPP,
                false,
                $calls
            ),
            $this->transport(
                OtpChannel::SMS,
                true,
                $calls
            ),
        ]);

        $result = $router->deliver($this->request());

        $this->assertTrue($result->accepted);
        $this->assertSame(OtpChannel::SMS, $result->channel);
        $this->assertSame(['whatsapp', 'sms'], $calls);
    }

    public function testLastProviderFailureIsReturned(): void
    {
        $calls = [];

        $router = new OtpChannelRouter([
            $this->transport(
                OtpChannel::WHATSAPP,
                false,
                $calls
            ),
            $this->transport(
                OtpChannel::SMS,
                false,
                $calls
            ),
        ]);

        $result = $router->deliver($this->request());

        $this->assertFalse($result->accepted);
        $this->assertSame(OtpChannel::SMS, $result->channel);
        $this->assertSame('synthetic_rejection', $result->failureCode);
        $this->assertSame(['whatsapp', 'sms'], $calls);
    }

    public function testNoConfiguredTransportFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);

        (new OtpChannelRouter([]))
            ->deliver($this->request());
    }

    private function request(): OtpDeliveryRequest
    {
        return new OtpDeliveryRequest(
            '+50900000000',
            '123456',
            300
        );
    }

    private function transport(
        OtpChannel $channel,
        bool $accepted,
        array &$calls
    ): OtpTransportInterface {
        return new class(
            $channel,
            $accepted,
            $calls
        ) implements OtpTransportInterface {
            private array $calls;

            public function __construct(
                private OtpChannel $channelValue,
                private bool $acceptedValue,
                array &$calls
            ) {
                $this->calls =& $calls;
            }

            public function channel(): OtpChannel
            {
                return $this->channelValue;
            }

            public function deliver(
                OtpDeliveryRequest $request
            ): OtpDeliveryResult {
                $this->calls[] = $this->channelValue->value;

                if ($this->acceptedValue) {
                    return OtpDeliveryResult::accepted(
                        $this->channelValue,
                        'synthetic-message-id'
                    );
                }

                return OtpDeliveryResult::rejected(
                    $this->channelValue,
                    'synthetic_rejection'
                );
            }
        };
    }
}
