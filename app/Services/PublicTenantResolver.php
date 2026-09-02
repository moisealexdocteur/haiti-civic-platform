<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class PublicTenantResolver
{
    private BaseConnection $db;

    public function __construct(
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
    }

    public function bySlug(string $slug): ?array
    {
        $slug = trim($slug);

        if (
            $slug === ''
            || mb_strlen($slug) > 80
        ) {
            return null;
        }

        return $this->db
            ->table('tenants')
            ->select([
                'id',
                'uuid',
                'slug',
                'name',
                'default_locale',
                'timezone',
            ])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }
}
