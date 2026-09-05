<?php

namespace App\Services;

use Closure;
use Config\Services;
use InvalidArgumentException;
use Throwable;

final class SmtpMessageSender
{
    private Closure $sendMail;

    public function __construct(
        private readonly array $configuration,
        ?Closure $sendMail = null
    ) {
        $this->validate();
        $this->sendMail = $sendMail ?? Closure::fromCallable([$this, 'sendViaCodeIgniter']);
    }

    /** @return array{accepted:bool,messageId:?string,failureCode:?string,providerDetail:?string} */
    public function send(string $recipient, string $subject, string $body): array
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || strlen($recipient) > 254) {
            throw new InvalidArgumentException('SMTP recipient is invalid.');
        }

        try {
            $response = ($this->sendMail)($recipient, $subject, $body);
            $accepted = is_array($response) ? (bool) ($response['accepted'] ?? false) : (bool) $response;
            $detail = is_array($response) ? (string) ($response['detail'] ?? '') : '';
        } catch (Throwable $exception) {
            return $this->failed('smtp_transport_error', $exception->getMessage());
        }

        return $accepted
            ? ['accepted' => true, 'messageId' => null, 'failureCode' => null, 'providerDetail' => null]
            : $this->failed('smtp_send_failed', $detail);
    }

    private function validate(): void
    {
        foreach (['host', 'user', 'password', 'from_address', 'from_name'] as $key) {
            if (trim((string) ($this->configuration[$key] ?? '')) === '') {
                throw new InvalidArgumentException('SMTP configuration is incomplete.');
            }
        }
    }

    private function sendViaCodeIgniter(string $recipient, string $subject, string $body): array
    {
        $email = Services::email(null, false);
        $email->initialize([
            'protocol' => 'smtp',
            'SMTPHost' => $this->configuration['host'],
            'SMTPPort' => $this->configuration['port'],
            'SMTPCrypto' => $this->configuration['crypto'],
            'SMTPUser' => $this->configuration['user'],
            'SMTPPass' => $this->configuration['password'],
            'SMTPTimeout' => 10,
            'SMTPKeepAlive' => false,
            'mailType' => 'text',
            'charset' => 'UTF-8',
            'newline' => "\r\n",
            'CRLF' => "\r\n",
        ]);
        $email->setFrom((string) $this->configuration['from_address'], (string) $this->configuration['from_name']);
        $email->setTo($recipient);
        $email->setSubject(mb_substr(trim($subject), 0, 250));
        $email->setMessage(mb_substr(trim($body), 0, 10000));
        $accepted = $email->send(false);

        return ['accepted' => $accepted, 'detail' => $accepted ? '' : $email->printDebugger([])];
    }

    private function failed(string $code, ?string $detail): array
    {
        return [
            'accepted' => false,
            'messageId' => null,
            'failureCode' => $code,
            'providerDetail' => NotificationDeliveryService::sanitizeProviderDetail($detail),
        ];
    }
}
