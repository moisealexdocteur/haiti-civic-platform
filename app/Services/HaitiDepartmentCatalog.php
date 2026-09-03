<?php

namespace App\Services;

use InvalidArgumentException;

final class HaitiDepartmentCatalog
{
    private const DEPARTMENTS = [
        'HT-AR' => ['fr' => 'Artibonite', 'ht' => 'Latibonit', 'lat' => 19.445, 'lng' => -72.688],
        'HT-CE' => ['fr' => 'Centre', 'ht' => 'Sant', 'lat' => 19.150, 'lng' => -72.016],
        'HT-GA' => ['fr' => 'Grand’Anse', 'ht' => 'Grandans', 'lat' => 18.650, 'lng' => -74.116],
        'HT-NI' => ['fr' => 'Nippes', 'ht' => 'Nip', 'lat' => 18.442, 'lng' => -73.088],
        'HT-ND' => ['fr' => 'Nord', 'ht' => 'Nò', 'lat' => 19.759, 'lng' => -72.200],
        'HT-NE' => ['fr' => 'Nord-Est', 'ht' => 'Nòdès', 'lat' => 19.662, 'lng' => -71.837],
        'HT-NO' => ['fr' => 'Nord-Ouest', 'ht' => 'Nòdwès', 'lat' => 19.939, 'lng' => -72.832],
        'HT-OU' => ['fr' => 'Ouest', 'ht' => 'Lwès', 'lat' => 18.594, 'lng' => -72.307],
        'HT-SD' => ['fr' => 'Sud', 'ht' => 'Sid', 'lat' => 18.200, 'lng' => -73.750],
        'HT-SE' => ['fr' => 'Sud-Est', 'ht' => 'Sidès', 'lat' => 18.234, 'lng' => -72.535],
    ];

    public function normalize(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = strtoupper(trim($code));

        if (! array_key_exists($code, self::DEPARTMENTS)) {
            throw new InvalidArgumentException(
                'Unknown Haiti department code.'
            );
        }

        return $code;
    }

    public function options(string $locale): array
    {
        $language = $locale === 'ht' ? 'ht' : 'fr';
        $options = [];

        foreach (self::DEPARTMENTS as $code => $department) {
            $options[$code] = (string) $department[$language];
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    public function mapPoints(string $locale): array
    {
        $language = $locale === 'ht' ? 'ht' : 'fr';
        $points = [];

        foreach (self::DEPARTMENTS as $code => $department) {
            $points[$code] = [
                'code' => $code,
                'name' => (string) $department[$language],
                'lat' => (float) $department['lat'],
                'lng' => (float) $department['lng'],
            ];
        }

        return $points;
    }
}
