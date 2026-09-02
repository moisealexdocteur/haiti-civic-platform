<?php

namespace App\Services\Otp;

use App\Services\TenantContext;
use InvalidArgumentException;
use RuntimeException;

final class OtpChallengeCrypto
{
    private const MIN_SECRET_LENGTH = 32;

    private const PHONE_KEY_INFO =
        'civic.otp.phone-fingerprint.v1';

    private const CODE_KEY_INFO =
        'civic.otp.code-digest.v1';

    private string $appKey;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?string $appKey = null
    ) {
        $this->appKey = $this->resolveSecret(
            'APP_KEY',
            $appKey
        );
    }

    public function phoneFingerprint(
        string $normalizedPhone
    ): string {
        if (
            preg_match(
                '/^\+509[0-9]{8}$/D',
                $normalizedPhone
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Normalized Haiti phone number is invalid.'
            );
        }

        $key = $this->deriveTenantKey(
            self::PHONE_KEY_INFO
        );

        try {
            return hash_hmac(
                'sha256',
                "v1\0" . $normalizedPhone,
                $key
            );
        } finally {
            $this->forget($key);
        }
    }

    public function codeDigest(
        string $challengeUuid,
        string $code
    ): string {
        $challengeUuid = $this->normalizeUuid(
            $challengeUuid
        );

        if (
            preg_match('/^[0-9]{6}$/D', $code)
            !== 1
        ) {
            throw new InvalidArgumentException(
                'OTP code must contain exactly 6 digits.'
            );
        }

        $key = $this->deriveTenantKey(
            self::CODE_KEY_INFO
            . '|challenge:'
            . $challengeUuid
        );

        try {
            return hash_hmac(
                'sha256',
                "v1\0" . $code,
                $key
            );
        } finally {
            $this->forget($key);
        }
    }

    private function deriveTenantKey(
        string $purpose
    ): string {
        $key = hash_hkdf(
            'sha256',
            $this->appKey,
            32,
            $purpose
            . '|tenant:'
            . $this->tenantContext->id()
        );

        if (strlen($key) !== 32) {
            throw new RuntimeException(
                'Could not derive OTP cryptographic key.'
            );
        }

        return $key;
    }

    private function normalizeUuid(
        string $uuid
    ): string {
        $uuid = strtolower(trim($uuid));

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[0-9a-f]{4}-[0-9a-f]{4}-'
                . '[0-9a-f]{12}$/D',
                $uuid
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'OTP challenge UUID is invalid.'
            );
        }

        return $uuid;
    }

    private function resolveSecret(
        string $name,
        ?string $provided
    ): string {
        $value = $provided;

        if ($value === null) {
            $environmentValue = getenv($name);
            $value = $environmentValue === false
                ? ''
                : $environmentValue;
        }

        if (
            $value === ''
            || strlen($value) < self::MIN_SECRET_LENGTH
            || str_contains($value, 'CHANGE_ME')
        ) {
            throw new RuntimeException(
                $name . ' is missing or invalid.'
            );
        }

        return $value;
    }

    private function forget(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
            return;
        }

        $value = '';
    }
}
