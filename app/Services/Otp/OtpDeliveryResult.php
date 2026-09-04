<?php

namespace App\Services\Otp;

final readonly class OtpDeliveryResult
{
    private function __construct(
        public bool $accepted,
        public OtpChannel $channel,
        public ?string $providerMessageId,
        public ?string $failureCode,
        public ?string $providerDetail
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
            null,
            null
        );
    }

    public static function rejected(
        OtpChannel $channel,
        string $failureCode,
        ?string $providerDetail = null
    ): self {
        return new self(
            false,
            $channel,
            null,
            $failureCode,
            self::cleanDetail($providerDetail)
        );
    }

    private static function cleanDetail(?string $detail): ?string
    {
        $detail = trim(strip_tags((string) $detail));
        $detail = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $detail) ?? '';
        $detail = preg_replace('/\s+/', ' ', $detail) ?? '';
        $detail = preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i',
            '$1 [secret masqué]',
            $detail
        ) ?? '';
        $detail = preg_replace(
            '/\b(password|token|secret)\s*[:=]\s*\S+/i',
            '$1: [secret masqué]',
            $detail
        ) ?? '';

        return $detail === '' ? null : mb_substr($detail, 0, 500);
    }
}
