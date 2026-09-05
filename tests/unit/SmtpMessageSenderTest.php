<?php

namespace Tests\Unit;

use App\Services\SmtpMessageSender;
use PHPUnit\Framework\TestCase;

final class SmtpMessageSenderTest extends TestCase
{
    private const CONFIG = [
        'host' => 'smtp.example.test',
        'port' => 587,
        'crypto' => 'tls',
        'user' => 'mailer@example.test',
        'password' => 'not-a-real-password',
        'from_address' => 'mailer@example.test',
        'from_name' => 'Portail test',
    ];

    public function testAcceptedMessageReturnsProviderAcceptance(): void
    {
        $sender = new SmtpMessageSender(self::CONFIG, static function (
            string $recipient,
            string $subject,
            string $body
        ): array {
            self::assertSame('citizen@example.test', $recipient);
            self::assertSame('Objet', $subject);
            self::assertSame('Message', $body);
            return ['accepted' => true, 'detail' => ''];
        });

        $result = $sender->send('citizen@example.test', 'Objet', 'Message');
        $this->assertTrue($result['accepted']);
        $this->assertNull($result['failureCode']);
    }

    public function testFailureDoesNotExposeProviderPassword(): void
    {
        $sender = new SmtpMessageSender(self::CONFIG, static fn (): array => [
            'accepted' => false,
            'detail' => 'password=not-a-real-password authentication failed',
        ]);

        $result = $sender->send('citizen@example.test', 'Objet', 'Message');
        $this->assertFalse($result['accepted']);
        $this->assertSame('smtp_send_failed', $result['failureCode']);
        $this->assertStringNotContainsString('not-a-real-password', (string) $result['providerDetail']);
    }

    public function testFailureDoesNotExposeRecipientCoordinates(): void
    {
        $sender = new SmtpMessageSender(self::CONFIG, static fn (): array => [
            'accepted' => false,
            'detail' => 'Mailbox citizen@example.test linked to +50935123456 was rejected',
        ]);

        $result = $sender->send('citizen@example.test', 'Objet', 'Message');
        $this->assertFalse($result['accepted']);
        $this->assertStringNotContainsString('citizen@example.test', (string) $result['providerDetail']);
        $this->assertStringNotContainsString('+50935123456', (string) $result['providerDetail']);
    }
}
