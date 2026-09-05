<?php

namespace Tests\Unit;

use App\Services\MetaWhatsAppMessageSender;
use PHPUnit\Framework\TestCase;

final class MetaWhatsAppMessageSenderTest extends TestCase
{
    public function testApprovedTemplatePayloadIsUsed(): void
    {
        $captured = [];
        $sender = new MetaWhatsAppMessageSender(
            'v26.0',
            '1234567890',
            'not-a-real-meta-access-token-123456789',
            'civic_notification',
            'ht',
            static function (string $url, array $headers, string $body) use (&$captured): array {
                $captured = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                return ['status' => 200, 'body' => '{"messages":[{"id":"wamid.test"}]}'];
            }
        );

        $result = $sender->send('+50935123456', 'Dosye DOS-TEST resevwa.');
        $this->assertTrue($result['accepted']);
        $this->assertSame('wamid.test', $result['messageId']);
        $this->assertSame('template', $captured['type']);
        $this->assertSame('civic_notification', $captured['template']['name']);
        $this->assertSame('50935123456', $captured['to']);
    }

    public function testProviderErrorIsSanitized(): void
    {
        $sender = new MetaWhatsAppMessageSender(
            'v26.0',
            '1234567890',
            'not-a-real-meta-access-token-123456789',
            'civic_notification',
            'fr',
            static fn (): array => [
                'status' => 401,
                'body' => '{"error":{"message":"token=very-secret-value invalid"}}',
            ]
        );

        $result = $sender->send('+50935123456', 'Message');
        $this->assertFalse($result['accepted']);
        $this->assertSame('meta_http_4xx', $result['failureCode']);
        $this->assertStringNotContainsString('very-secret-value', (string) $result['providerDetail']);
    }
}
