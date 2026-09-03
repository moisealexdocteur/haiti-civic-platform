<?php

namespace Tests\Unit;

use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpDeliveryRequest;
use App\Services\Otp\SmtpEmailOtpTransport;
use CodeIgniter\Test\CIUnitTestCase;

final class SmtpEmailOtpTransportTest extends CIUnitTestCase
{
    public function testEmailOtpUsesRecipientSubjectAndCode(): void
    {
        $captured = [];

        $transport = new SmtpEmailOtpTransport(
            'smtp.example.test',
            587,
            'tls',
            'smtp-user@example.test',
            'synthetic-password',
            'no-reply@example.test',
            'Portail citoyen',
            static function (
                string $recipient,
                string $subject,
                string $body
            ) use (&$captured): bool {
                $captured = compact('recipient', 'subject', 'body');
                return true;
            }
        );

        $result = $transport->deliver(
            new OtpDeliveryRequest(
                '+50900000000',
                '123456',
                300,
                'citizen@example.test'
            )
        );

        $this->assertTrue($result->accepted);
        $this->assertSame(OtpChannel::EMAIL, $result->channel);
        $this->assertNull($result->providerMessageId);
        $this->assertSame(
            'citizen@example.test',
            $captured['recipient']
        );
        $this->assertStringContainsString(
            '123456',
            $captured['body']
        );
        $this->assertStringContainsString(
            '5 minutes',
            $captured['body']
        );
    }

    public function testMissingEmailRecipientIsRejectedWithoutSending(): void
    {
        $called = false;

        $transport = new SmtpEmailOtpTransport(
            'smtp.example.test',
            587,
            'tls',
            'smtp-user@example.test',
            'synthetic-password',
            'no-reply@example.test',
            'Portail citoyen',
            static function () use (&$called): bool {
                $called = true;
                return true;
            }
        );

        $result = $transport->deliver(
            new OtpDeliveryRequest(
                '+50900000000',
                '123456',
                300
            )
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('email_recipient_missing', $result->failureCode);
        $this->assertFalse($called);
    }

    public function testSmtpFailureIsCoarseAndLeaksNoCode(): void
    {
        $transport = new SmtpEmailOtpTransport(
            'smtp.example.test',
            587,
            'tls',
            'smtp-user@example.test',
            'synthetic-password',
            'no-reply@example.test',
            'Portail citoyen',
            static fn (): bool => false
        );

        $result = $transport->deliver(
            new OtpDeliveryRequest(
                '+50900000000',
                '123456',
                300,
                'citizen@example.test'
            )
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('smtp_send_failed', $result->failureCode);
        $this->assertStringNotContainsString(
            '123456',
            (string) $result->failureCode
        );
    }
}
