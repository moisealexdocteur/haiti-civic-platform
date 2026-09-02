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
