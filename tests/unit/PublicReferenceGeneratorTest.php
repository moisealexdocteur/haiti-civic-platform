<?php

namespace Tests\Unit;

use App\Services\PublicReferenceGenerator;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class PublicReferenceGeneratorTest extends CIUnitTestCase
{
    public function testGeneratedReferencesAreReadableAndUnique(): void
    {
        $generator = new PublicReferenceGenerator();
        $references = [];

        for ($index = 0; $index < 100; $index++) {
            $reference = $generator->generate();
            $this->assertTrue($generator->isValid($reference));
            $this->assertStringNotContainsString('0', $reference);
            $this->assertStringNotContainsString('1', $reference);
            $references[] = $reference;
        }

        $this->assertCount(100, array_unique($references));
    }

    public function testNormalizeAcceptsSpacesAndLowercase(): void
    {
        $generator = new PublicReferenceGenerator();

        $this->assertSame(
            'DOS-7K4M-9P2R-X8CW',
            $generator->normalize('dos 7k4m 9p2r x8cw')
        );
    }

    public function testInvalidReferenceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PublicReferenceGenerator())->normalize('DOS-0000-0000-0000');
    }
}
