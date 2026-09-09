<?php

namespace App\Services;

use Throwable;

/** Application acceptance policy. Does not authenticate against the ONI register. */
final class OniDocumentConformityService
{
    public function __construct(private ?OniOcrReader $reader = null)
    {
        $this->reader ??= new LocalOniOcrReader();
    }

    public function assess(string $path, string $ninu, ?string $firstName = null, ?string $lastName = null, ?string $portraitPath = null): array
    {
        if (getenv('ONI_OCR_ENABLED') === '0') {
            return $this->result(['ocr_disabled']);
        }
        try {
            // The supplied NINU is never given to the image reader or used to guide OCR.
            if ($portraitPath !== null && is_file($path) && is_file($portraitPath)
                && hash_file('sha256', $path) === hash_file('sha256', $portraitPath)) {
                return $this->result(['portrait_is_card']);
            }
            $json = $this->reader->read($path, $portraitPath);
            if (strlen($json) > 262144) {
                return $this->result(['ocr_output_limit']);
            }
            $evidence = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            return $this->assessEvidence(is_array($evidence) ? $evidence : [], $ninu);
        } catch (Throwable) {
            // Errors and raw evidence may contain personal data: never log them.
            return $this->result(['analysis_unavailable']);
        }
    }

    public function assessEvidence(array $evidence, string $ninu): array
    {
        $ninu = (new IdentityInputNormalizer())->normalizeNinu($ninu);
        $reasons = [];
        if (($evidence['card_detected'] ?? null) !== true) {
            $reasons[] = 'card_not_recognized';
        }
        if (($evidence['portrait_detected'] ?? null) !== true) {
            $reasons[] = 'portrait_not_detected';
        }
        $numbers = [];
        $sources = [];
        foreach ((array) ($evidence['numbers'] ?? []) as $number) {
            if (! is_array($number) || ! is_string($number['value'] ?? null)
                || preg_match('/^[0-9]{10}$/D', $number['value']) !== 1
                || ! is_numeric($number['confidence'] ?? null)) {
                continue;
            }
            $confidence = (float) $number['confidence'];
            if ($confidence >= 65 && $confidence <= 100) {
                $value = $number['value'];
                $numbers[$value] = max($numbers[$value] ?? 0, $confidence);
                $source = $number['source'] ?? 'front';
                if (in_array($source, ['front', 'roi-6', 'roi-11', 'sharp'], true)) {
                    $sources[$value][$source] = true;
                }
            }
        }
        // Prefer a unique number confirmed by multiple preprocessing/segmentation passes.
        // Duplicate words in one pass do not count as independent readings.
        $repeated = array_filter($numbers, static fn ($value) => count($sources[$value] ?? []) >= 2, ARRAY_FILTER_USE_KEY);
        if (count($repeated) === 1) {
            $numbers = $repeated;
        } elseif ($repeated !== []) {
            $numbers = $repeated;
        } else {
            $numbers = array_filter($numbers, static fn ($confidence) => $confidence >= 80);
        }
        if (count($numbers) !== 1) {
            $reasons[] = count($numbers) > 1 ? 'ninu_ambiguous' : 'ninu_unreadable';
        } elseif ((string) array_key_first($numbers) !== $ninu) {
            $reasons[] = 'ninu_mismatch';
        }
        return $this->result($reasons);
    }

    private function result(array $reasons): array
    {
        return [
            'version' => 'oni-document-v2',
            'status' => $reasons === [] ? 'conformant' : 'manual_review',
            'scope' => 'card_ninu_portrait_presence',
            'authority_verified' => false,
            'reason_codes' => $reasons,
        ];
    }
}
