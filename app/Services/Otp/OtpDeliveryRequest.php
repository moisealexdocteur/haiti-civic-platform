<?php

namespace App\Services\Otp;

use InvalidArgumentException;

final readonly class OtpDeliveryRequest
{
    public function __construct(
        public string $normalizedPhone,
        public string $code,
        public int $ttlSeconds
    ) {
        if (! preg_match('/^\+509[0-9]{8}$/', $normalizedPhone)) {
            throw new InvalidArgumentException(
                'OTP recipient must be a normalized Haitian phone number.'
            );
        }

        if ($code === '' || strlen($code) > 32) {
            throw new InvalidArgumentException(
                'OTP code has an invalid length.'
            );
        }

        if ($ttlSeconds < 30 || $ttlSeconds > 1800) {
            throw new InvalidArgumentException(
                'OTP TTL must be between 30 and 1800 seconds.'
            );
        }
    }
}
