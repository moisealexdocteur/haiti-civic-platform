<?php

namespace Tests\Unit;

use App\Services\TwilioSmsMessageSender;
use CodeIgniter\Test\CIUnitTestCase;

final class TwilioSmsMessageSenderTest extends CIUnitTestCase
{
    public function testConfirmationPayloadUsesConfiguredSender(): void
    {
        $captured = [];
        $sender = new TwilioSmsMessageSender(
            'AC' . str_repeat('a', 32),
            str_repeat('b', 32),
            '+15551234567',
            null,
            static function (
                string $url,
                string $username,
                string $password,
                string $body
            ) use (&$captured): array {
                parse_str($body, $captured);

                return [
                    'status' => 201,
                    'body' => json_encode(
                        ['sid' => 'SM' . str_repeat('c', 32)],
                        JSON_THROW_ON_ERROR
                    ),
                ];
            }
        );

        $result = $sender->send(
            '+50900000000',
            'Dosye ou / Votre dossier: DOS-7K4M-9P2R-X8CW'
        );

        $this->assertTrue($result['accepted']);
        $this->assertSame('+50900000000', $captured['To']);
        $this->assertSame('+15551234567', $captured['From']);
        $this->assertStringContainsString('DOS-7K4M-9P2R-X8CW', $captured['Body']);
    }

    public function testProviderFailureDoesNotReturnCredentials(): void
    {
        $secret = str_repeat('s', 32);
        $sender = new TwilioSmsMessageSender(
            'AC' . str_repeat('a', 32),
            $secret,
            null,
            'MG' . str_repeat('d', 32),
            static fn (): array => [
                'status' => 401,
                'body' => '{"message":"Authentication failed"}',
            ]
        );

        $result = $sender->send('+50900000000', 'Message de test');

        $this->assertFalse($result['accepted']);
        $this->assertSame('twilio_http_4xx', $result['failureCode']);
        $this->assertStringNotContainsString($secret, json_encode($result));
    }
}
