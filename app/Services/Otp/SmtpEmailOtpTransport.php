<?php

namespace App\Services\Otp;

use Closure;
use Config\Services;
use InvalidArgumentException;
use Throwable;

final class SmtpEmailOtpTransport implements OtpTransportInterface
{
    private Closure $sendMail;

    public function __construct(
        private readonly string $smtpHost,
        private readonly int $smtpPort,
        private readonly string $smtpCrypto,
        private readonly string $smtpUser,
        private readonly string $smtpPassword,
        private readonly string $fromEmail,
        private readonly string $fromName,
        ?Closure $sendMail = null
    ) {
        $this->validateConfiguration();
        $this->sendMail = $sendMail ?? Closure::fromCallable(
            [$this, 'sendViaCodeIgniter']
        );
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::EMAIL;
    }

    public function deliver(
        OtpDeliveryRequest $request
    ): OtpDeliveryResult {
        if ($request->normalizedEmail === null) {
            return OtpDeliveryResult::rejected(
                OtpChannel::EMAIL,
                'email_recipient_missing'
            );
        }

        $minutes = max(1, (int) ceil($request->ttlSeconds / 60));
        $subject = 'Votre code de vérification';
        $body = sprintf(
            "Votre code de vérification est : %s\n\n"
            . "Il expire dans %d minutes.\n"
            . "Si vous n’avez pas demandé ce code, ignorez ce message.",
            $request->code,
            $minutes
        );

        try {
            $response = ($this->sendMail)(
                $request->normalizedEmail,
                $subject,
                $body
            );
            $accepted = is_array($response)
                ? (bool) ($response['accepted'] ?? false)
                : (bool) $response;
            $detail = is_array($response)
                ? (string) ($response['detail'] ?? '')
                : '';
        } catch (Throwable $exception) {
            return OtpDeliveryResult::rejected(
                OtpChannel::EMAIL,
                'smtp_transport_error',
                $exception->getMessage()
            );
        }

        if (! $accepted) {
            return OtpDeliveryResult::rejected(
                OtpChannel::EMAIL,
                'smtp_send_failed',
                $detail
            );
        }

        return OtpDeliveryResult::accepted(OtpChannel::EMAIL);
    }

    private function validateConfiguration(): void
    {
        if (
            $this->smtpHost === ''
            || strlen($this->smtpHost) > 253
            || preg_match('/\s/', $this->smtpHost) === 1
        ) {
            throw new InvalidArgumentException(
                'SMTP host is invalid.'
            );
        }

        if ($this->smtpPort < 1 || $this->smtpPort > 65535) {
            throw new InvalidArgumentException(
                'SMTP port is invalid.'
            );
        }

        if (! in_array($this->smtpCrypto, ['', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException(
                'SMTP crypto mode is invalid.'
            );
        }

        if ($this->smtpUser === '' || strlen($this->smtpUser) > 320) {
            throw new InvalidArgumentException(
                'SMTP username is invalid.'
            );
        }

        if (
            $this->smtpPassword === ''
            || strlen($this->smtpPassword) > 4096
        ) {
            throw new InvalidArgumentException(
                'SMTP password is invalid.'
            );
        }

        if (
            filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) === false
            || strlen($this->fromEmail) > 254
        ) {
            throw new InvalidArgumentException(
                'SMTP sender email is invalid.'
            );
        }

        if ($this->fromName === '' || mb_strlen($this->fromName) > 160) {
            throw new InvalidArgumentException(
                'SMTP sender name is invalid.'
            );
        }
    }

    private function sendViaCodeIgniter(
        string $recipient,
        string $subject,
        string $body
    ): array {
        $email = Services::email(null, false);
        $email->initialize([
            'protocol' => 'smtp',
            'SMTPHost' => $this->smtpHost,
            'SMTPPort' => $this->smtpPort,
            'SMTPCrypto' => $this->smtpCrypto,
            'SMTPUser' => $this->smtpUser,
            'SMTPPass' => $this->smtpPassword,
            'SMTPTimeout' => 10,
            'SMTPKeepAlive' => false,
            'mailType' => 'text',
            'charset' => 'UTF-8',
            'newline' => "\r\n",
            'CRLF' => "\r\n",
        ]);

        $email->setFrom($this->fromEmail, $this->fromName);
        $email->setTo($recipient);
        $email->setSubject($subject);
        $email->setMessage($body);

        $accepted = $email->send(false);

        return [
            'accepted' => $accepted,
            'detail' => $accepted ? '' : $email->printDebugger([]),
        ];
    }
}
