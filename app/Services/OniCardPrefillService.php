<?php
namespace App\Services;

/** Suggestions only: no database lookup or approval of identity. */
final class OniCardPrefillService
{
    public function fromEvidence(array $evidence): array
    {
        $result = ['ninu' => '', 'firstName' => '', 'lastName' => ''];
        if (is_array($evidence) && ($evidence['card_detected'] ?? false) === true) {
            // Reuse the number confidence policy; this does NOT approve a dossier.
            $evidence['portrait_detected'] = true;
            $policy = new \App\Services\OniDocumentConformityService();
            foreach (($evidence['numbers'] ?? []) as $number) {
                $value = $number['value'] ?? '';
                if (is_string($value) && preg_match('/^[0-9]{10}$/D', $value)
                    && $policy->assessEvidence($evidence, $value)['status'] === 'conformant') {
                    $result['ninu'] = $value;
                    break;
                }
            }
            foreach (['firstName', 'lastName'] as $field) {
                $value = $evidence['fields'][$field] ?? '';
                if (is_string($value) && mb_strlen($value) <= 100
                    && preg_match("/^[\\p{L}][\\p{L}\\p{M} .’'-]+$/u", $value)) {
                    $result[$field] = $value;
                }
            }
        }
        return $result;
    }
}
