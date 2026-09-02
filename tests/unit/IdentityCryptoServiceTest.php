<?php

namespace Tests\Unit;

use App\Services\IdentityCryptoService;
use App\Services\TenantContext;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IdentityCryptoServiceTest
    extends TestCase
{
    private const APP_KEY =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const HMAC_KEY =
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
        . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const UUID_A =
        '11111111-1111-4111-8111-111111111111';

    private const UUID_B =
        '22222222-2222-4222-8222-222222222222';

    private const FAKE_NINU =
        'TEST-NINU-NON-PLAUSIBLE-ALPHA';

    private const FAKE_PHONE =
        'TEST-PHONE-NON-PLAUSIBLE-ALPHA';

    public function testNinuRoundTrip(): void
    {
        $service = $this->service();

        $encrypted =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $this->assertStringStartsWith(
            'v1.',
            $encrypted
        );

        $this->assertStringNotContainsString(
            self::FAKE_NINU,
            $encrypted
        );

        $this->assertSame(
            self::FAKE_NINU,
            $service->decryptNinu(
                $encrypted,
                self::UUID_A
            )
        );
    }

    public function testPhoneRoundTrip(): void
    {
        $service = $this->service();

        $encrypted =
            $service->encryptPhone(
                self::FAKE_PHONE,
                self::UUID_A
            );

        $this->assertStringNotContainsString(
            self::FAKE_PHONE,
            $encrypted
        );

        $this->assertSame(
            self::FAKE_PHONE,
            $service->decryptPhone(
                $encrypted,
                self::UUID_A
            )
        );
    }

    public function testEncryptionIsRandomized(): void
    {
        $service = $this->service();

        $first =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $second =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $this->assertNotSame(
            $first,
            $second
        );

        $this->assertSame(
            self::FAKE_NINU,
            $service->decryptNinu(
                $first,
                self::UUID_A
            )
        );

        $this->assertSame(
            self::FAKE_NINU,
            $service->decryptNinu(
                $second,
                self::UUID_A
            )
        );
    }

    public function testNinuFingerprintIsDeterministic(): void
    {
        $service = $this->service();

        $first =
            $service->ninuFingerprint(
                self::FAKE_NINU
            );

        $second =
            $service->ninuFingerprint(
                self::FAKE_NINU
            );

        $this->assertSame(
            $first,
            $second
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $first
        );

        $this->assertStringNotContainsString(
            self::FAKE_NINU,
            $first
        );
    }

    public function testNinuFingerprintIsTenantScoped(): void
    {
        $tenantOne =
            $this->service(1);

        $tenantTwo =
            $this->service(2);

        $this->assertNotSame(
            $tenantOne->ninuFingerprint(
                self::FAKE_NINU
            ),
            $tenantTwo->ninuFingerprint(
                self::FAKE_NINU
            )
        );
    }

    public function testWrongTenantCannotDecrypt(): void
    {
        $tenantOne =
            $this->service(1);

        $tenantTwo =
            $this->service(2);

        $encrypted =
            $tenantOne->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'Identity ciphertext authentication failed.'
        );

        $tenantTwo->decryptNinu(
            $encrypted,
            self::UUID_A
        );
    }

    public function testWrongSubjectCannotDecrypt(): void
    {
        $service = $this->service();

        $encrypted =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $this->expectException(
            RuntimeException::class
        );

        $service->decryptNinu(
            $encrypted,
            self::UUID_B
        );
    }

    public function testFieldSubstitutionIsRejected(): void
    {
        $service = $this->service();

        $encrypted =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $this->expectException(
            RuntimeException::class
        );

        $service->decryptPhone(
            $encrypted,
            self::UUID_A
        );
    }

    public function testCiphertextTamperingIsRejected(): void
    {
        $service = $this->service();

        $encrypted =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $tampered =
            $this->tamperEnvelope(
                $encrypted
            );

        $this->expectException(
            RuntimeException::class
        );

        $service->decryptNinu(
            $tampered,
            self::UUID_A
        );
    }

    public function testMalformedEnvelopeIsRejected(): void
    {
        $service = $this->service();

        $this->expectException(
            RuntimeException::class
        );

        $service->decryptNinu(
            'v1.not*valid*base64url',
            self::UUID_A
        );
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $service = $this->service();

        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'Unsupported identity ciphertext version.'
        );

        $service->decryptNinu(
            'v2.abcdef',
            self::UUID_A
        );
    }

    public function testInvalidSubjectUuidIsRejected(): void
    {
        $service = $this->service();

        $this->expectException(
            InvalidArgumentException::class
        );

        $service->encryptNinu(
            self::FAKE_NINU,
            'not-a-uuid'
        );
    }

    public function testEmptySensitiveValueIsRejected(): void
    {
        $service = $this->service();

        $this->expectException(
            InvalidArgumentException::class
        );

        $service->encryptNinu(
            '',
            self::UUID_A
        );
    }

    public function testTenantContextIsRequired(): void
    {
        $service =
            new IdentityCryptoService(
                new TenantContext(),
                self::APP_KEY,
                self::HMAC_KEY
            );

        $this->expectException(
            LogicException::class
        );

        $service->ninuFingerprint(
            self::FAKE_NINU
        );
    }

    public function testMissingEncryptionSecretIsRejected(): void
    {
        $this->expectException(
            RuntimeException::class
        );

        new IdentityCryptoService(
            (new TenantContext())->set(1),
            '',
            self::HMAC_KEY
        );
    }

    public function testMissingHmacSecretIsRejected(): void
    {
        $this->expectException(
            RuntimeException::class
        );

        new IdentityCryptoService(
            (new TenantContext())->set(1),
            self::APP_KEY,
            ''
        );
    }

    public function testIdenticalSecretsAreRejected(): void
    {
        $this->expectException(
            RuntimeException::class
        );

        $this->expectExceptionMessage(
            'Identity encryption and HMAC secrets '
            . 'must be distinct.'
        );

        new IdentityCryptoService(
            (new TenantContext())->set(1),
            self::APP_KEY,
            self::APP_KEY
        );
    }

    public function testEnvironmentBackedConstructorWorks(): void
    {
        $service =
            new IdentityCryptoService(
                (new TenantContext())->set(7)
            );

        $encrypted =
            $service->encryptNinu(
                self::FAKE_NINU,
                self::UUID_A
            );

        $this->assertSame(
            self::FAKE_NINU,
            $service->decryptNinu(
                $encrypted,
                self::UUID_A
            )
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $service->ninuFingerprint(
                self::FAKE_NINU
            )
        );
    }

    private function service(
        int $tenantId = 1
    ): IdentityCryptoService {
        return new IdentityCryptoService(
            (new TenantContext())
                ->set($tenantId),
            self::APP_KEY,
            self::HMAC_KEY
        );
    }

    private function tamperEnvelope(
        string $payload
    ): string {
        [$prefix, $encoded] =
            explode(
                '.',
                $payload,
                2
            );

        $padding =
            (4 - strlen($encoded) % 4)
            % 4;

        $binary = base64_decode(
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

        if (
            $binary === false
            || $binary === ''
        ) {
            self::fail(
                'Fixture ciphertext could not be decoded.'
            );
        }

        $position =
            strlen($binary) - 1;

        $binary[$position] =
            chr(
                ord($binary[$position]) ^ 1
            );

        $tampered =
            rtrim(
                strtr(
                    base64_encode($binary),
                    '+/',
                    '-_'
                ),
                '='
            );

        return $prefix
            . '.'
            . $tampered;
    }
}
