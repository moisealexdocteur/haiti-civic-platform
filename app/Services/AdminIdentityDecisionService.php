<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

final class AdminIdentityDecisionService
{
    private const MANAGE_PERMISSION = 'identity.manage';

    private TenantContext $tenantContext;
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private CitizenIdentityWriteService $identityWrite;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?AuthorizationService $authorization = null,
        ?CitizenIdentityWriteService $identityWrite = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();
        $this->authorization = $authorization
            ?? new AuthorizationService($tenantContext, $this->db);
        $this->identityWrite = $identityWrite
            ?? new CitizenIdentityWriteService($tenantContext, $this->db);
    }

    public function transition(
        int $actorUserId,
        string $identityUuid,
        string $toStatus,
        ?string $reasonCode = null
    ): void {
        if (! $this->authorization->userHasPermission(
            $actorUserId,
            self::MANAGE_PERMISSION
        )) {
            throw new RuntimeException(
                'Permission denied: ' . self::MANAGE_PERMISSION
            );
        }

        $identityUuid = $this->normalizeUuid($identityUuid);
        $row = $this->db
            ->table('citizen_identities')
            ->select('id')
            ->where('tenant_id', $this->tenantContext->id())
            ->where('uuid', $identityUuid)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Citizen identity does not exist in the current tenant.'
            );
        }

        $this->identityWrite->transitionVerificationStatus(
            $actorUserId,
            (int) $row['id'],
            strtolower(trim($toStatus)),
            $reasonCode === null ? null : trim($reasonCode)
        );
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException('UUID is invalid.');
        }

        return $uuid;
    }
}
