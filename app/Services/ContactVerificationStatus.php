<?php

namespace App\Services;

use InvalidArgumentException;

final class ContactVerificationStatus
{
    public const OTP_VERIFIED = 'otp_verified';
    public const MANUAL_REVIEW = 'manual_review';

    private const ALLOWED = [
        self::OTP_VERIFIED,
        self::MANUAL_REVIEW,
    ];

    public static function normalize(string $status): string
    {
        $status = strtolower(trim($status));

        if (! in_array($status, self::ALLOWED, true)) {
            throw new InvalidArgumentException(
                'Unknown contact verification status.'
            );
        }

        return $status;
    }
}
