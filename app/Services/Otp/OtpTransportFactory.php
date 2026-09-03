<?php

namespace App\Services\Otp;

use RuntimeException;

final class OtpTransportFactory
{
    public static function fromEnvironment(): OtpChannelRouter
    {
        $transports = [];

        $whatsAppProvider = strtolower(self::env('WHATSAPP_PROVIDER'));

        if ($whatsAppProvider !== '') {
            if ($whatsAppProvider !== 'meta') {
                throw new RuntimeException(
                    'Unsupported WhatsApp OTP provider configuration.'
                );
            }

            $transports[] = new MetaWhatsAppOtpTransport(
                self::required('WHATSAPP_GRAPH_VERSION'),
                self::required('WHATSAPP_PHONE_NUMBER_ID'),
                self::required('WHATSAPP_ACCESS_TOKEN'),
                self::required('WHATSAPP_OTP_TEMPLATE'),
                self::required('WHATSAPP_OTP_TEMPLATE_LANG')
            );
        }

        $smsProvider = strtolower(self::env('SMS_PROVIDER'));

        if ($smsProvider !== '') {
            if ($smsProvider !== 'twilio') {
                throw new RuntimeException(
                    'Unsupported SMS OTP provider configuration.'
                );
            }

            $from = self::nullable('TWILIO_FROM');
            $messagingServiceSid = self::nullable(
                'TWILIO_MESSAGING_SERVICE_SID'
            );

            $transports[] = new TwilioSmsOtpTransport(
                self::required('TWILIO_ACCOUNT_SID'),
                self::required('TWILIO_AUTH_TOKEN'),
                $from,
                $messagingServiceSid
            );
        }

        $emailProvider = strtolower(self::env('EMAIL_PROVIDER'));

        if ($emailProvider !== '') {
            if ($emailProvider !== 'smtp') {
                throw new RuntimeException(
                    'Unsupported email OTP provider configuration.'
                );
            }

            $port = filter_var(
                self::required('EMAIL_SMTP_PORT'),
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => 65535,
                    ],
                ]
            );

            if ($port === false) {
                throw new RuntimeException(
                    'OTP email SMTP port is invalid.'
                );
            }

            $transports[] = new SmtpEmailOtpTransport(
                self::required('EMAIL_SMTP_HOST'),
                (int) $port,
                strtolower(self::env('EMAIL_SMTP_CRYPTO')),
                self::required('EMAIL_SMTP_USER'),
                self::required('EMAIL_SMTP_PASSWORD'),
                self::required('EMAIL_FROM_ADDRESS'),
                self::required('EMAIL_FROM_NAME')
            );
        }

        return new OtpChannelRouter($transports);
    }

    private static function required(string $name): string
    {
        $value = self::env($name);

        if ($value === '') {
            throw new RuntimeException(
                'OTP provider configuration is incomplete.'
            );
        }

        return $value;
    }

    private static function nullable(string $name): ?string
    {
        $value = self::env($name);
        return $value === '' ? null : $value;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        return $value === false ? '' : trim($value);
    }
}
