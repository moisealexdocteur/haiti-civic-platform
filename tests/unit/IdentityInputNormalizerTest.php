<?php

namespace Tests\Unit;

use App\Services\IdentityInputNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdentityInputNormalizerTest
    extends TestCase
{
    private IdentityInputNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer =
            new IdentityInputNormalizer();
    }

    public function testPlainNinuIsPreserved(): void
    {
        $this->assertSame(
            '0123456789',
            $this->normalizer
                ->normalizeNinu(
                    '0123456789'
                )
        );
    }

    public function testNinuPresentationSeparatorsAreRemoved(): void
    {
        $this->assertSame(
            '01234567890123456',
            $this->normalizer
                ->normalizeNinu(
                    '01-23-45-6789-01-23456'
                )
        );

        $this->assertSame(
            '01234567890123456',
            $this->normalizer
                ->normalizeNinu(
                    ' 01 23 45 6789 01 23456 '
                )
        );
    }

    public function testEquivalentNinuInputsNormalizeIdentically(): void
    {
        $a =
            $this->normalizer
                ->normalizeNinu(
                    '0123456789'
                );

        $b =
            $this->normalizer
                ->normalizeNinu(
                    '012-345-6789'
                );

        $c =
            $this->normalizer
                ->normalizeNinu(
                    '012 345 6789'
                );

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function testNinuLettersAreRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->normalizer
            ->normalizeNinu(
                '01234ABC89'
            );
    }

    public function testEmptyNinuIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->normalizer
            ->normalizeNinu('   ');
    }

    public function testExcessivelyLongNinuIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->normalizer
            ->normalizeNinu(
                str_repeat('1', 65)
            );
    }

    public function testNationalPhoneBecomesE164(): void
    {
        $this->assertSame(
            '+50912345678',
            $this->normalizer
                ->normalizeHaitiPhone(
                    '12345678'
                )
        );
    }

    public function testCountryCodePhoneBecomesE164(): void
    {
        $this->assertSame(
            '+50912345678',
            $this->normalizer
                ->normalizeHaitiPhone(
                    '50912345678'
                )
        );
    }

    public function testInternationalPhoneIsPreserved(): void
    {
        $this->assertSame(
            '+50912345678',
            $this->normalizer
                ->normalizeHaitiPhone(
                    '+509 12 34 56 78'
                )
        );

        $this->assertSame(
            '+50912345678',
            $this->normalizer
                ->normalizeHaitiPhone(
                    '00509 12 34 56 78'
                )
        );
    }

    public function testNullAndEmptyPhoneBecomeNull(): void
    {
        $this->assertNull(
            $this->normalizer
                ->normalizeHaitiPhone(null)
        );

        $this->assertNull(
            $this->normalizer
                ->normalizeHaitiPhone('  ')
        );
    }

    public function testWrongCountryIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->normalizer
            ->normalizeHaitiPhone(
                '+15145551234'
            );
    }

    public function testWrongHaitiLengthIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->normalizer
            ->normalizeHaitiPhone(
                '+5091234567'
            );
    }

    public function testLettersInPhoneAreRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->normalizer
            ->normalizeHaitiPhone(
                '+50912AB5678'
            );
    }
}
