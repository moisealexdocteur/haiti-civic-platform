<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class IdentityCryptoService
{
    private const VERSION = 1;

    private const ENVELOPE_PREFIX = 'v1.';

    private const MIN_SECRET_LENGTH = 32;

    private const FIELD_NINU = 'ninu';

    private const FIELD_PHONE = 'phone';

    private const FIELD_EMAIL = 'email';

    private const FIELD_FIRST_NAME = 'first_name';

    private const FIELD_LAST_NAME = 'last_name';

    private const ENCRYPTION_KEY_INFO =
        'civic.identity.encryption.v1';

    private const HMAC_KEY_INFO =
        'civic.identity.ninu-hmac.v1';

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?string $appKey = null,
        ?string $ninuHmacKey = null
    ) {
        $this->assertCryptoCapabilities();

        $this->appKey = $this->resolveSecret(
            'APP_KEY',
            $appKey
        );

        $this->ninuHmacKey = $this->resolveSecret(
            'NINU_HMAC_KEY',
            $ninuHmacKey
        );

        if (
            hash_equals(
                $this->appKey,
                $this->ninuHmacKey
            )
        ) {
            throw new RuntimeException(
                'Identity encryption and HMAC secrets '
                . 'must be distinct.'
            );
        }
    }

    private string $appKey;

    private string $ninuHmacKey;

    public function encryptNinu(
        string $normalizedNinu,
        string $subjectUuid
    ): string {
        return $this->encryptField(
            self::FIELD_NINU,
            $normalizedNinu,
            $subjectUuid
        );
    }

    public function decryptNinu(
        string $payload,
        string $subjectUuid
    ): string {
        return $this->decryptField(
            self::FIELD_NINU,
            $payload,
            $subjectUuid
        );
    }

    public function encryptPhone(
        string $normalizedPhone,
        string $subjectUuid
    ): string {
        return $this->encryptField(
            self::FIELD_PHONE,
            $normalizedPhone,
            $subjectUuid
        );
    }

    public function decryptPhone(
        string $payload,
        string $subjectUuid
    ): string {
        return $this->decryptField(
            self::FIELD_PHONE,
            $payload,
            $subjectUuid
        );
    }

    public function encryptEmail(string $email, string $subjectUuid): string
    {
        return $this->encryptField(
            self::FIELD_EMAIL,
            $email,
            $subjectUuid
        );
    }

    public function decryptEmail(string $payload, string $subjectUuid): string
    {
        return $this->decryptField(
            self::FIELD_EMAIL,
            $payload,
            $subjectUuid
        );
    }

    public function encryptFirstName(
        string $firstName,
        string $subjectUuid
    ): string {
        return $this->encryptField(
            self::FIELD_FIRST_NAME,
            $firstName,
            $subjectUuid
        );
    }

    public function decryptFirstName(
        string $payload,
        string $subjectUuid
    ): string {
        return $this->decryptField(
            self::FIELD_FIRST_NAME,
            $payload,
            $subjectUuid
        );
    }

    public function encryptLastName(
        string $lastName,
        string $subjectUuid
    ): string {
        return $this->encryptField(
            self::FIELD_LAST_NAME,
            $lastName,
            $subjectUuid
        );
    }

    public function decryptLastName(
        string $payload,
        string $subjectUuid
    ): string {
        return $this->decryptField(
            self::FIELD_LAST_NAME,
            $payload,
            $subjectUuid
        );
    }

    public function ninuFingerprint(
        string $normalizedNinu
    ): string {
        $this->assertSensitiveValue(
            $normalizedNinu
        );

        $tenantId =
            $this->tenantContext->id();

        $key =
            $this->deriveTenantKey(
                $this->ninuHmacKey,
                self::HMAC_KEY_INFO,
                $tenantId
            );

        try {
            return hash_hmac(
                'sha256',
                "v1\0" . $normalizedNinu,
                $key
            );
        } finally {
            sodium_memzero($key);
        }
    }

    private function encryptField(
        string $field,
        string $plaintext,
        string $subjectUuid
    ): string {
        $this->assertSensitiveValue(
            $plaintext
        );

        $subjectUuid =
            $this->normalizeSubjectUuid(
                $subjectUuid
            );

        $tenantId =
            $this->tenantContext->id();

        $key =
            $this->deriveTenantKey(
                $this->appKey,
                self::ENCRYPTION_KEY_INFO,
                $tenantId
            );

        $nonce = random_bytes(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        );

        $aad = $this->buildAad(
            $tenantId,
            $subjectUuid,
            $field,
            self::VERSION
        );

        try {
            $ciphertext =
                sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                    $plaintext,
                    $aad,
                    $nonce,
                    $key
                );
        } finally {
            sodium_memzero($key);
        }

        return self::ENVELOPE_PREFIX
            . $this->base64UrlEncode(
                $nonce . $ciphertext
            );
    }

    private function decryptField(
        string $field,
        string $payload,
        string $subjectUuid
    ): string {
        $subjectUuid =
            $this->normalizeSubjectUuid(
                $subjectUuid
            );

        if (
            ! str_starts_with(
                $payload,
                self::ENVELOPE_PREFIX
            )
        ) {
            throw new RuntimeException(
                'Unsupported identity ciphertext version.'
            );
        }

        $encoded = substr(
            $payload,
            strlen(self::ENVELOPE_PREFIX)
        );

        $binary =
            $this->base64UrlDecode(
                $encoded
            );

        $nonceLength =
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        $minimumCiphertextLength =
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
            + 1;

        if (
            strlen($binary)
            <
            $nonceLength
            + $minimumCiphertextLength
        ) {
            throw new RuntimeException(
                'Malformed identity ciphertext.'
            );
        }

        $nonce = substr(
            $binary,
            0,
            $nonceLength
        );

        $ciphertext = substr(
            $binary,
            $nonceLength
        );

        $tenantId =
            $this->tenantContext->id();

        $aad = $this->buildAad(
            $tenantId,
            $subjectUuid,
            $field,
            self::VERSION
        );

        $key =
            $this->deriveTenantKey(
                $this->appKey,
                self::ENCRYPTION_KEY_INFO,
                $tenantId
            );

        try {
            $plaintext =
                sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $ciphertext,
                    $aad,
                    $nonce,
                    $key
                );
        } finally {
            sodium_memzero($key);
        }

        if ($plaintext === false) {
            throw new RuntimeException(
                'Identity ciphertext authentication failed.'
            );
        }

        return $plaintext;
    }

    private function deriveTenantKey(
        string $masterSecret,
        string $purpose,
        int $tenantId
    ): string {
        $derived = hash_hkdf(
            'sha256',
            $masterSecret,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $purpose . '|tenant:' . $tenantId
        );

        if (
            strlen($derived)
            !==
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
        ) {
            throw new RuntimeException(
                'Could not derive identity cryptographic key.'
            );
        }

        return $derived;
    }

    private function buildAad(
        int $tenantId,
        string $subjectUuid,
        string $field,
        int $version
    ): string {
        return sprintf(
            'civic.identity|tenant:%d|subject:%s|field:%s|v:%d',
            $tenantId,
            $subjectUuid,
            $field,
            $version
        );
    }

    private function normalizeSubjectUuid(
        string $subjectUuid
    ): string {
        $subjectUuid =
            strtolower(
                trim($subjectUuid)
            );

        if (
            preg_match(
                '/^[0-9a-f]{8}-'
                . '[0-9a-f]{4}-'
                . '[0-9a-f]{4}-'
                . '[0-9a-f]{4}-'
                . '[0-9a-f]{12}$/D',
                $subjectUuid
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Subject UUID is invalid.'
            );
        }

        return $subjectUuid;
    }

    private function assertSensitiveValue(
        string $value
    ): void {
        if ($value === '') {
            throw new InvalidArgumentException(
                'Sensitive identity value cannot be empty.'
            );
        }
    }

    private function resolveSecret(
        string $name,
        ?string $provided
    ): string {
        $value = $provided;

        if ($value === null) {
            $environmentValue =
                getenv($name);

            $value =
                $environmentValue === false
                    ? ''
                    : $environmentValue;
        }

        if (
            $value === ''
            || strlen($value)
                < self::MIN_SECRET_LENGTH
            || str_contains(
                $value,
                'CHANGE_ME'
            )
        ) {
            throw new RuntimeException(
                $name
                . ' is missing or invalid.'
            );
        }

        return $value;
    }

    private function assertCryptoCapabilities(): void
    {
        $required = [
            'hash_hkdf',
            'hash_hmac',
            'random_bytes',
            'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt',
            'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt',
            'sodium_memzero',
        ];

        foreach ($required as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException(
                    'Required identity cryptographic '
                    . 'capability is unavailable.'
                );
            }
        }
    }

    private function base64UrlEncode(
        string $binary
    ): string {
        return rtrim(
            strtr(
                base64_encode($binary),
                '+/',
                '-_'
            ),
            '='
        );
    }

    private function base64UrlDecode(
        string $encoded
    ): string {
        if (
            $encoded === ''
            || preg_match(
                '/^[A-Za-z0-9_-]+$/D',
                $encoded
            ) !== 1
        ) {
            throw new RuntimeException(
                'Malformed identity ciphertext.'
            );
        }

        $padding =
            (4 - strlen($encoded) % 4)
            % 4;

        $decoded = base64_decode(
            strtr(
                $encoded,
                '-_',
                '+/'
            )
            . str_repeat(
                '=',
                $padding
            ),
            true
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'Malformed identity ciphertext.'
            );
        }

        return $decoded;
    }
}
