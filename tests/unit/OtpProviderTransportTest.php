<?php

namespace Tests\Unit;

use App\Services\Otp\MetaWhatsAppOtpTransport;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpDeliveryRequest;
use App\Services\Otp\TwilioSmsOtpTransport;
use CodeIgniter\Test\CIUnitTestCase;

final class OtpProviderTransportTest extends CIUnitTestCase
{
    public function testMetaAuthenticationTemplatePayloadAndMessageId(): void
    {
        $captured = [];

        $transport = new MetaWhatsAppOtpTransport(
            'v26.0',
            '123456789012345',
            str_repeat('A', 40),
            'otp_copy_code',
            'fr',
            static function (
                string $url,
                array $headers,
                string $body
            ) use (&$captured): array {
                $captured = [
                    'url' => $url,
                    'headers' => $headers,
                    'body' => json_decode($body, true, 32, JSON_THROW_ON_ERROR),
                ];

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'messages' => [
                            ['id' => 'wamid.synthetic-message'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        );

        $result = $transport->deliver($this->request());

        $this->assertTrue($result->accepted);
        $this->assertSame(OtpChannel::WHATSAPP, $result->channel);
        $this->assertSame(
            'wamid.synthetic-message',
            $result->providerMessageId
        );
        $this->assertSame(
            'https://graph.facebook.com/v26.0/123456789012345/messages',
            $captured['url']
        );
        $this->assertSame(
            '50900000000',
            $captured['body']['to']
        );
        $this->assertSame(
            'otp_copy_code',
            $captured['body']['template']['name']
        );
        $this->assertSame(
            '123456',
            $captured['body']['template']['components'][0]
                ['parameters'][0]['text']
        );
        $this->assertSame(
            '123456',
            $captured['body']['template']['components'][1]
                ['parameters'][0]['text']
        );
        $this->assertStringContainsString(
            'Bearer ',
            implode("\n", $captured['headers'])
        );
    }

    public function testMetaProviderFailureIsCoarseAndContainsNoOtp(): void
    {
        $transport = new MetaWhatsAppOtpTransport(
            'v26.0',
            '123456789012345',
            str_repeat('B', 40),
            'otp_copy_code',
            'fr',
            static fn (): array => [
                'status' => 401,
                'body' => '{"error":{"message":"synthetic"}}',
            ]
        );

        $result = $transport->deliver($this->request());

        $this->assertFalse($result->accepted);
        $this->assertSame('meta_http_4xx', $result->failureCode);
        $this->assertStringNotContainsString(
            '123456',
            (string) $result->failureCode
        );
    }

    public function testTwilioSmsPayloadUsesSingleAsciiMessage(): void
    {
        $captured = [];

        $transport = new TwilioSmsOtpTransport(
            'AC' . str_repeat('a', 32),
            str_repeat('c', 32),
            '+15551234567',
            null,
            static function (
                string $url,
                string $username,
                string $password,
                string $body
            ) use (&$captured): array {
                parse_str($body, $fields);

                $captured = [
                    'url' => $url,
                    'username' => $username,
                    'password' => $password,
                    'fields' => $fields,
                ];

                return [
                    'status' => 201,
                    'body' => json_encode([
                        'sid' => 'SM' . str_repeat('d', 32),
                        'status' => 'queued',
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        );

        $result = $transport->deliver($this->request());

        $this->assertTrue($result->accepted);
        $this->assertSame(OtpChannel::SMS, $result->channel);
        $this->assertSame(
            '+50900000000',
            $captured['fields']['To']
        );
        $this->assertSame(
            '+15551234567',
            $captured['fields']['From']
        );
        $this->assertSame(
            'Code de verification: 123456. Expire dans 5 minutes.',
            $captured['fields']['Body']
        );
        $this->assertStringNotContainsString(
            'é',
            $captured['fields']['Body']
        );
    }

    public function testTwilioMessagingServiceCanReplaceFromNumber(): void
    {
        $captured = [];

        $transport = new TwilioSmsOtpTransport(
            'AC' . str_repeat('a', 32),
            str_repeat('c', 32),
            null,
            'MG' . str_repeat('e', 32),
            static function (
                string $url,
                string $username,
                string $password,
                string $body
            ) use (&$captured): array {
                parse_str($body, $captured);

                return [
                    'status' => 201,
                    'body' => json_encode([
                        'sid' => 'SM' . str_repeat('f', 32),
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        );

        $result = $transport->deliver($this->request());

        $this->assertTrue($result->accepted);
        $this->assertArrayNotHasKey('From', $captured);
        $this->assertSame(
            'MG' . str_repeat('e', 32),
            $captured['MessagingServiceSid']
        );
    }

    private function request(): OtpDeliveryRequest
    {
        return new OtpDeliveryRequest(
            '+50900000000',
            '123456',
            300
        );
    }
}
