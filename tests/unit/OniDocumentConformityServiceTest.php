<?php

namespace Tests\Unit;

use App\Services\OniDocumentConformityService;
use App\Services\OniOcrReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OniDocumentConformityServiceTest extends TestCase
{
    private function evidence(): array
    {
        return ['card_detected' => true, 'portrait_detected' => true,
            'numbers' => [['value' => '0123456789', 'confidence' => 95]]];
    }

    public function testMatchingCardAndPortraitPassWithoutClaimingOniVerification(): void
    {
        $result = (new OniDocumentConformityService())->assessEvidence($this->evidence(), '01-234-56789');
        self::assertSame('conformant', $result['status']);
        self::assertFalse($result['authority_verified']);
        self::assertStringNotContainsString('0123456789', json_encode($result));
    }

    public function testMissingCardOrPortraitCannotPass(): void
    {
        foreach (['card_detected', 'portrait_detected'] as $field) {
            $evidence = $this->evidence();
            $evidence[$field] = false;
            self::assertSame('manual_review', (new OniDocumentConformityService())->assessEvidence($evidence, '0123456789')['status']);
        }
    }

    public function testDifferentNinuRequiresReview(): void
    {
        $result = (new OniDocumentConformityService())->assessEvidence($this->evidence(), '9876543210');
        self::assertSame(['ninu_mismatch'], $result['reason_codes']);
    }

    public function testCompetingConfidentNumbersRequireReview(): void
    {
        $evidence = $this->evidence();
        $evidence['numbers'][] = ['value' => '9876543210', 'confidence' => 80];
        self::assertSame(['ninu_ambiguous'], (new OniDocumentConformityService())->assessEvidence($evidence, '0123456789')['reason_codes']);
    }

    public function testRepeatedReadResolvesOneOffOcrErrorWithoutUsingTheSubmittedNumber(): void
    {
        $evidence = $this->evidence();
        $evidence['numbers'] = [
            ['value' => '9876543210', 'confidence' => 85, 'source' => 'front'],
            ['value' => '0123456789', 'confidence' => 70, 'source' => 'roi-6'],
            ['value' => '0123456789', 'confidence' => 75, 'source' => 'roi-11'],
        ];
        self::assertSame('conformant', (new OniDocumentConformityService())->assessEvidence($evidence, '0123456789')['status']);
        self::assertSame(['ninu_mismatch'], (new OniDocumentConformityService())->assessEvidence($evidence, '9876543210')['reason_codes']);
        $evidence['numbers'][2]['source'] = 'roi-6';
        self::assertNotSame('conformant', (new OniDocumentConformityService())->assessEvidence($evidence, '0123456789')['status']);
    }

    public function testLettersCardSerialLowConfidenceAndMalformedNumbersCannotPass(): void
    {
        foreach ([['value' => 'H12345678', 'confidence' => 99], ['value' => 'O123456789', 'confidence' => 99],
            ['value' => '0123456789', 'confidence' => 64], ['value' => '0123456789', 'confidence' => 101],
            ['value' => 123456789, 'confidence' => 99], ['value' => '0123456789', 'confidence' => 'bad']] as $number) {
            $evidence = $this->evidence(); $evidence['numbers'] = [$number];
            self::assertSame(['ninu_unreadable'], (new OniDocumentConformityService())->assessEvidence($evidence, '0123456789')['reason_codes']);
        }
    }

    public function testEngineFailureFallsBackWithoutLeakingException(): void
    {
        $reader = new class implements OniOcrReader {
            public function read(string $path, ?string $portraitPath = null): string
            {
                throw new RuntimeException('sensitive image data');
            }
        };
        $result = (new OniDocumentConformityService($reader))->assess('/none', '0123456789');
        self::assertSame(['analysis_unavailable'], $result['reason_codes']);
        self::assertStringNotContainsString('sensitive', json_encode($result));
    }

    public function testDisabledEngineNeverRuns(): void
    {
        $old = getenv('ONI_OCR_ENABLED');
        $reader = new class implements OniOcrReader {
            public bool $called = false;
            public function read(string $path, ?string $portraitPath = null): string
            {
                $this->called = true;
                return '{}';
            }
        };
        try {
            putenv('ONI_OCR_ENABLED=0');
            self::assertSame(['ocr_disabled'], (new OniDocumentConformityService($reader))->assess('/none', '0123456789')['reason_codes']);
            self::assertFalse($reader->called);
        } finally {
            putenv($old === false ? 'ONI_OCR_ENABLED' : 'ONI_OCR_ENABLED=' . $old);
        }
    }

    public function testSameImageForCardAndPortraitRequiresReview(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'oni-test-');
        try {
            file_put_contents($path, 'synthetic image');
            $result = (new OniDocumentConformityService())->assess($path, '0123456789', portraitPath: $path);
            self::assertSame(['portrait_is_card'], $result['reason_codes']);
        } finally {
            unlink($path);
        }
    }
}
