<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class TenantSecretCipher
{
    private const PREFIX = 'v1.';
    private const KEY_INFO = 'civic.tenant-settings.encryption.v1';

    private string $appKey;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?string $appKey = null
    ) {
        if (! function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('Libsodium is required to protect provider secrets.');
        }

        $resolved = $appKey ?? getenv('APP_KEY');

        if (! is_string($resolved) || strlen($resolved) < 32) {
            throw new RuntimeException('APP_KEY must contain at least 32 characters.');
        }

        $this->appKey = $resolved;
    }

    public function encrypt(string $name, string $plaintext): string
    {
        $name = $this->normalizeName($name);

        if ($plaintext === '') {
            throw new InvalidArgumentException('A provider secret cannot be empty.');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = $this->key();

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $this->aad($name),
                $nonce,
                $key
            );
        } finally {
            sodium_memzero($key);
        }

        return self::PREFIX . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    public function decrypt(string $name, string $payload): string
    {
        $name = $this->normalizeName($name);

        if (! str_starts_with($payload, self::PREFIX)) {
            throw new RuntimeException('Unsupported provider secret version.');
        }

        $encoded = substr($payload, strlen(self::PREFIX));
        $padding = strlen($encoded) % 4;

        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (! is_string($binary) || strlen($binary) <= $nonceLength) {
            throw new RuntimeException('Malformed provider secret.');
        }

        $key = $this->key();

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($binary, $nonceLength),
                $this->aad($name),
                substr($binary, 0, $nonceLength),
                $key
            );
        } finally {
            sodium_memzero($key);
        }

        if (! is_string($plaintext)) {
            throw new RuntimeException('Provider secret authentication failed.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        $key = hash_hkdf(
            'sha256',
            $this->appKey,
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            self::KEY_INFO . '|tenant:' . $this->tenantContext->id()
        );

        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Could not derive provider encryption key.');
        }

        return $key;
    }

    private function aad(string $name): string
    {
        return self::KEY_INFO . '|tenant:' . $this->tenantContext->id() . '|secret:' . $name;
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));

        if (preg_match('/^[a-z0-9_.-]{1,80}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Provider secret name is invalid.');
        }

        return $name;
    }
}
