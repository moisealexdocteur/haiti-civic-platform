<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class AdminIdentityMapService
{
    private const VIEW_PERMISSION = 'identity.view';

    private BaseConnection $db;
    private AuthorizationService $authorization;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?AuthorizationService $authorization = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->authorization = $authorization
            ?? new AuthorizationService($tenantContext, $this->db);
    }

    public function summaryForActor(
        int $actorUserId,
        string $locale
    ): array {
        if (! $this->authorization->userHasPermission(
            $actorUserId,
            self::VIEW_PERMISSION
        )) {
            throw new RuntimeException(
                'Permission denied: ' . self::VIEW_PERMISSION
            );
        }

        $stored = $this->db
            ->table('citizen_identities')
            ->select('department_code')
            ->selectCount('id', 'total')
            ->select(
                "SUM(CASE WHEN verification_status = 'pending' "
                . "THEN 1 ELSE 0 END) AS pending",
                false
            )
            ->select(
                "SUM(CASE WHEN verification_status = 'verified' "
                . "THEN 1 ELSE 0 END) AS verified",
                false
            )
            ->select(
                "SUM(CASE WHEN verification_status = 'rejected' "
                . "THEN 1 ELSE 0 END) AS rejected",
                false
            )
            ->where('tenant_id', $this->tenantContext->id())
            ->where('department_code IS NOT NULL', null, false)
            ->groupBy('department_code')
            ->get()
            ->getResultArray();

        $byCode = [];

        foreach ($stored as $row) {
            $byCode[(string) $row['department_code']] = $row;
        }

        $rows = [];

        foreach ((new HaitiDepartmentCatalog())->mapPoints($locale) as $code => $point) {
            $counts = $byCode[$code] ?? [];
            $rows[] = array_merge($point, [
                'total' => (int) ($counts['total'] ?? 0),
                'pending' => (int) ($counts['pending'] ?? 0),
                'verified' => (int) ($counts['verified'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ]);
        }

        return $rows;
    }
}
