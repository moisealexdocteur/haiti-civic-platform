<?php

namespace Tests\Unit;

use App\Services\TenantContext;
use App\Services\TenantSecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantSecretCipherTest extends TestCase
{
    private const APP_KEY = 'test-key-test-key-test-key-test-key-test-key-test-key';

    public function testSecretRoundTripIsEncryptedAndRandomized(): void
    {
        $cipher = new TenantSecretCipher((new TenantContext())->set(41), self::APP_KEY);
        $first = $cipher->encrypt('smtp_password', 'not-a-real-provider-secret');
        $second = $cipher->encrypt('smtp_password', 'not-a-real-provider-secret');

        $this->assertStringStartsWith('v1.', $first);
        $this->assertStringNotContainsString('not-a-real-provider-secret', $first);
        $this->assertNotSame($first, $second);
        $this->assertSame(
            'not-a-real-provider-secret',
            $cipher->decrypt('smtp_password', $first)
        );
    }

    public function testAnotherTenantCannotDecryptTheSecret(): void
    {
        $tenantOne = new TenantSecretCipher((new TenantContext())->set(41), self::APP_KEY);
        $tenantTwo = new TenantSecretCipher((new TenantContext())->set(42), self::APP_KEY);
        $encrypted = $tenantOne->encrypt('twilio_auth_token', 'not-a-real-provider-secret');

        $this->expectException(RuntimeException::class);
        $tenantTwo->decrypt('twilio_auth_token', $encrypted);
    }
}
