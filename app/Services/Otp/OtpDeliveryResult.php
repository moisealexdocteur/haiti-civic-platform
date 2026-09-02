<?php

namespace App\Services\Otp;

final readonly class OtpDeliveryResult
{
    private function __construct(
        public bool $accepted,
        public OtpChannel $channel,
        public ?string $providerMessageId,
        public ?string $failureCode
    ) {
    }

    public static function accepted(
        OtpChannel $channel,
        ?string $providerMessageId = null
    ): self {
        return new self(
            true,
            $channel,
            $providerMessageId,
            null
        );
    }

    public static function rejected(
        OtpChannel $channel,
        string $failureCode
    ): self {
        return new self(
            false,
            $channel,
            null,
            $failureCode
        );
    }
}
