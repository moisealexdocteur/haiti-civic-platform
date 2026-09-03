<?php

namespace App\Services;

final class PublicPoliticalStructureDirectory
{
    public const CATALOG_KEY = 'cep-2026-08-14';
    public const SOURCE_URL = 'https://cephaiti.ht/wp-content/uploads/2026/08/Liste-definitive-des-structures-politiques-agreees-cep-1.pdf';
    public const SOURCE_REFERENCE = 'CEP/DC/045-2526';
    public const APPROVAL_DATE = '2026-08-14';

    public function all(): array
    {
        return db_connect()
            ->table('political_structures')
            ->select([
                'cep_list_position',
                'structure_type',
                'name',
                'acronym',
                'approval_date',
            ])
            ->where('catalog_key', self::CATALOG_KEY)
            ->where('status', 'approved')
            ->orderBy('cep_list_position', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function counts(): array
    {
        $rows = db_connect()
            ->table('political_structures')
            ->select('structure_type, COUNT(*) AS total', false)
            ->where('catalog_key', self::CATALOG_KEY)
            ->where('status', 'approved')
            ->groupBy('structure_type')
            ->get()
            ->getResultArray();

        $counts = [
            'parti' => 0,
            'groupement' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $type = (string) ($row['structure_type'] ?? '');
            $total = (int) ($row['total'] ?? 0);

            if (array_key_exists($type, $counts)) {
                $counts[$type] = $total;
            }
        }

        $counts['total'] = $counts['parti'] + $counts['groupement'];

        return $counts;
    }
}
