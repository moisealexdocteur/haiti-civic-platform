<?php

namespace App\Services;

use Closure;
use Config\Services;
use Throwable;

final class AdminPasswordResetMailer
{
    private Closure $sendMail;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?Closure $sendMail = null
    ) {
        $this->sendMail = $sendMail ?? Closure::fromCallable([$this, 'sendViaCodeIgniter']);
    }

    public function send(
        string $recipient,
        string $displayName,
        string $locale,
        string $resetUrl
    ): bool {
        $settings = new TenantCommunicationSettingsService($this->tenantContext);
        $config = $settings->hasStoredSettings()
            ? $settings->smtpConfiguration()
            : $this->environmentConfiguration();

        if ($config === null) {
            return false;
        }

        $locale = in_array($locale, ['fr', 'ht'], true) ? $locale : 'ht';
        $subject = lang('Admin.resetEmailSubject', [], $locale);
        $body = lang('Admin.resetEmailBody', [$displayName, $resetUrl], $locale);

        try {
            return (bool) ($this->sendMail)($recipient, $subject, $body, $config);
        } catch (Throwable) {
            return false;
        }
    }

    private function sendViaCodeIgniter(
        string $recipient,
        string $subject,
        string $body,
        array $config
    ): bool {
        $email = Services::email(null, false);
        $email->initialize([
            'protocol' => 'smtp',
            'SMTPHost' => $config['host'],
            'SMTPPort' => $config['port'],
            'SMTPCrypto' => $config['crypto'],
            'SMTPUser' => $config['user'],
            'SMTPPass' => $config['password'],
            'SMTPTimeout' => 10,
            'SMTPKeepAlive' => false,
            'mailType' => 'text',
            'charset' => 'UTF-8',
            'newline' => "\r\n",
            'CRLF' => "\r\n",
        ]);
        $email->setFrom($config['from_address'], $config['from_name']);
        $email->setTo($recipient);
        $email->setSubject($subject);
        $email->setMessage($body);

        return $email->send(false);
    }

    private function environmentConfiguration(): ?array
    {
        if (strtolower($this->env('EMAIL_PROVIDER')) !== 'smtp') {
            return null;
        }

        $port = filter_var($this->env('EMAIL_SMTP_PORT'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        $config = [
            'host' => $this->env('EMAIL_SMTP_HOST'),
            'port' => $port === false ? 0 : (int) $port,
            'crypto' => strtolower($this->env('EMAIL_SMTP_CRYPTO')),
            'user' => $this->env('EMAIL_SMTP_USER'),
            'password' => $this->env('EMAIL_SMTP_PASSWORD'),
            'from_address' => $this->env('EMAIL_FROM_ADDRESS'),
            'from_name' => $this->env('EMAIL_FROM_NAME'),
        ];

        foreach (['host', 'user', 'password', 'from_address', 'from_name'] as $key) {
            if ($config[$key] === '') {
                return null;
            }
        }

        if ($config['port'] === 0 || ! in_array($config['crypto'], ['', 'tls', 'ssl'], true)) {
            return null;
        }

        return $config;
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : trim($value);
    }
}
