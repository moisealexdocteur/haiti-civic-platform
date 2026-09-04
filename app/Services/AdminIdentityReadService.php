<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

final class AdminIdentityReadService
{
    private const VIEW_PERMISSION = 'identity.view';

    private const STATUSES = [
        'all',
        IdentityVerificationStateMachine::PENDING,
        IdentityVerificationStateMachine::VERIFIED,
        IdentityVerificationStateMachine::REJECTED,
    ];

    private TenantContext $tenantContext;
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private IdentityCryptoService $crypto;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?AuthorizationService $authorization = null,
        ?IdentityCryptoService $crypto = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();
        $this->authorization = $authorization
            ?? new AuthorizationService($tenantContext, $this->db);
        $this->crypto = $crypto
            ?? new IdentityCryptoService($tenantContext);
    }

    public function listForActor(
        int $actorUserId,
        string $status = IdentityVerificationStateMachine::PENDING
    ): array {
        return $this->exportForActor($actorUserId, $status);
    }

    /**
     * Return one bounded page for the administration queue.
     *
     * @return array{rows:array,total:int,page:int,perPage:int,pages:int,status:string,department:?string,sort:string,direction:string}
     */
    public function pageForActor(
        int $actorUserId,
        string $status = IdentityVerificationStateMachine::PENDING,
        ?string $department = null,
        string $sort = 'submitted',
        string $direction = 'asc',
        int $page = 1,
        int $perPage = 50
    ): array {
        $this->requireView($actorUserId);
        [$status, $department, $sort, $direction] = $this->normalizeListOptions(
            $status,
            $department,
            $sort,
            $direction
        );

        if (! in_array($perPage, [25, 50, 100], true)) {
            throw new InvalidArgumentException('Unsupported page size.');
        }

        $page = max(1, $page);
        $count = $this->db->table('citizen_identities ci')
            ->where('ci.tenant_id', $this->tenantContext->id());

        $this->applyListFilters($count, $status, $department);
        $total = (int) $count->countAllResults();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        $rows = $this->listQuery($status, $department)
            ->orderBy($this->sortColumn($sort), strtoupper($direction))
            ->orderBy('ci.id', strtoupper($direction))
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return compact(
            'rows',
            'total',
            'page',
            'perPage',
            'pages',
            'status',
            'department',
            'sort',
            'direction'
        );
    }

    /**
     * Return the complete filtered set used by privacy-safe exports.
     */
    public function exportForActor(
        int $actorUserId,
        string $status = 'all',
        ?string $department = null,
        string $sort = 'submitted',
        string $direction = 'asc'
    ): array {
        $this->requireView($actorUserId);
        [$status, $department, $sort, $direction] = $this->normalizeListOptions(
            $status,
            $department,
            $sort,
            $direction
        );

        return $this->listQuery($status, $department)
            ->orderBy($this->sortColumn($sort), strtoupper($direction))
            ->orderBy('ci.id', strtoupper($direction))
            ->get()
            ->getResultArray();
    }

    private function listQuery(string $status, ?string $department)
    {
        $query = $this->db
            ->table('citizen_identities ci')
            ->select([
                'ci.uuid',
                'ci.public_reference',
                'ci.verification_status',
                'ci.contact_verification_status',
                'ci.department_code',
                'ci.consented_at',
                'ci.verified_at',
                'ci.created_at',
                'ci.updated_at',
            ])
            ->selectCount('vd.id', 'document_count')
            ->join(
                'verification_documents vd',
                "vd.tenant_id = ci.tenant_id"
                . " AND vd.citizen_identity_id = ci.id"
                . " AND vd.status = 'active'",
                'left'
            )
            ->where('ci.tenant_id', $this->tenantContext->id());

        $this->applyListFilters($query, $status, $department);

        return $query
            ->groupBy([
                'ci.id',
                'ci.uuid',
                'ci.public_reference',
                'ci.verification_status',
                'ci.contact_verification_status',
                'ci.department_code',
                'ci.consented_at',
                'ci.verified_at',
                'ci.created_at',
                'ci.updated_at',
            ]);
    }

    private function applyListFilters($query, string $status, ?string $department): void
    {
        if ($status !== 'all') {
            $query->where('ci.verification_status', $status);
        }

        if ($department !== null) {
            $query->where('ci.department_code', $department);
        }
    }

    private function normalizeListOptions(
        string $status,
        ?string $department,
        string $sort,
        string $direction
    ): array {
        $status = strtolower(trim($status));

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'Unknown identity verification status.'
            );
        }

        $department = (new HaitiDepartmentCatalog())->normalize($department);
        $sort = strtolower(trim($sort));
        $direction = strtolower(trim($direction));

        if (! in_array($sort, ['reference', 'department', 'submitted'], true)) {
            throw new InvalidArgumentException('Unsupported identity list sort.');
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported identity list direction.');
        }

        return [$status, $department, $sort, $direction];
    }

    private function sortColumn(string $sort): string
    {
        return match ($sort) {
            'reference' => 'ci.public_reference',
            'department' => 'ci.department_code',
            default => 'ci.created_at',
        };
    }

    public function detailForActorByUuid(
        int $actorUserId,
        string $identityUuid
    ): ?array {
        $this->requireView($actorUserId);
        $identityUuid = $this->normalizeUuid($identityUuid);
        $tenantId = $this->tenantContext->id();

        $identity = $this->db
            ->table('citizen_identities ci')
            ->select([
                'ci.id',
                'ci.uuid',
                'ci.public_reference',
                'ci.ninu_ciphertext',
                'ci.phone_ciphertext',
                'ci.contact_verification_status',
                'ci.department_code',
                'ci.verification_status',
                'ci.consent_version',
                'ci.consented_at',
                'ci.verified_at',
                'ci.created_at',
                'ci.updated_at',
            ])
            ->where('ci.tenant_id', $tenantId)
            ->where('ci.uuid', $identityUuid)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($identity === null) {
            return null;
        }

        $identityId = (int) $identity['id'];
        $uuid = (string) $identity['uuid'];

        $identity['ninu'] = $this->crypto->decryptNinu(
            (string) $identity['ninu_ciphertext'],
            $uuid
        );

        $identity['phone'] = $identity['phone_ciphertext'] === null
            ? null
            : $this->crypto->decryptPhone(
                (string) $identity['phone_ciphertext'],
                $uuid
            );

        $identity['record_id'] = $identityId;

        unset(
            $identity['id'],
            $identity['ninu_ciphertext'],
            $identity['phone_ciphertext']
        );

        $identity['documents'] = $this->db
            ->table('verification_documents vd')
            ->select([
                'vd.uuid',
                'vd.document_type',
                'vd.revision_no',
                'vd.content_type',
                'vd.size_bytes',
                'vd.status',
                'vd.captured_at',
                'vd.created_at',
            ])
            ->where('vd.tenant_id', $tenantId)
            ->where('vd.citizen_identity_id', $identityId)
            ->orderBy('vd.document_type', 'ASC')
            ->orderBy('vd.revision_no', 'DESC')
            ->get()
            ->getResultArray();

        $identity['events'] = $this->db
            ->table('identity_verification_events ive')
            ->select([
                'ive.event_type',
                'ive.from_status',
                'ive.to_status',
                'ive.reason_code',
                'ive.occurred_at',
                'u.display_name AS actor_display_name',
            ])
            ->join('users u', 'u.id = ive.actor_user_id', 'left')
            ->where('ive.tenant_id', $tenantId)
            ->where('ive.citizen_identity_id', $identityId)
            ->orderBy('ive.occurred_at', 'DESC')
            ->orderBy('ive.id', 'DESC')
            ->get()
            ->getResultArray();

        $identity['audit'] = $this->db
            ->table('audit_logs al')
            ->select([
                'al.event',
                'al.actor_type',
                'al.actor_user_id',
                'al.request_id',
                'al.occurred_at',
            ])
            ->where('al.tenant_id', $tenantId)
            ->where('al.entity_type', 'citizen_identity')
            ->where('al.entity_id', (string) $identityId)
            ->orderBy('al.occurred_at', 'DESC')
            ->orderBy('al.id', 'DESC')
            ->get()
            ->getResultArray();

        return $identity;
    }

    public function documentForActor(
        int $actorUserId,
        string $identityUuid,
        string $documentUuid
    ): ?array {
        $this->requireView($actorUserId);
        $identityUuid = $this->normalizeUuid($identityUuid);
        $documentUuid = $this->normalizeUuid($documentUuid);

        $row = $this->db
            ->table('verification_documents vd')
            ->select([
                'vd.uuid AS document_uuid',
                'vd.document_type',
                'vd.storage_ref',
                'vd.content_type',
                'vd.size_bytes',
                'vd.sha256',
                'ci.uuid AS citizen_uuid',
                't.uuid AS tenant_uuid',
            ])
            ->join(
                'citizen_identities ci',
                'ci.id = vd.citizen_identity_id'
                . ' AND ci.tenant_id = vd.tenant_id'
            )
            ->join('tenants t', 't.id = vd.tenant_id')
            ->where('vd.tenant_id', $this->tenantContext->id())
            ->where('ci.uuid', $identityUuid)
            ->where('vd.uuid', $documentUuid)
            ->where('vd.status', 'active')
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            return null;
        }

        $resolved = $this->resolveLocalDocument($row);
        $row['absolute_path'] = $resolved['path'];
        $row['download_name'] = $resolved['name'];

        unset($row['storage_ref'], $row['sha256']);

        return $row;
    }

    private function resolveLocalDocument(array $row): array
    {
        $storageRef = (string) ($row['storage_ref'] ?? '');

        if (preg_match('/^local:\/\/([0-9a-f]{64})$/D', $storageRef, $match) !== 1) {
            throw new RuntimeException('Unsupported verification document storage reference.');
        }

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
        ];

        $contentType = (string) ($row['content_type'] ?? '');
        $extension = $extensions[$contentType] ?? null;

        if ($extension === null) {
            throw new RuntimeException('Unsupported stored verification document MIME type.');
        }

        $tenantUuid = $this->normalizeUuid((string) $row['tenant_uuid']);
        $citizenUuid = $this->normalizeUuid((string) $row['citizen_uuid']);
        $root = WRITEPATH . 'uploads/identity/' . $tenantUuid . '/' . $citizenUuid;
        $path = $root . '/' . $match[1] . '.' . $extension;
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if (
            $realRoot === false
            || $realPath === false
            || ! is_file($realPath)
            || ! str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Stored verification document is unavailable.');
        }

        $expectedSize = $row['size_bytes'] === null
            ? null
            : (int) $row['size_bytes'];
        $actualSize = filesize($realPath);

        if ($actualSize === false || ($expectedSize !== null && $actualSize !== $expectedSize)) {
            throw new RuntimeException('Stored verification document size check failed.');
        }

        $expectedHash = $row['sha256'] === null ? null : (string) $row['sha256'];

        if ($expectedHash !== null) {
            $actualHash = hash_file('sha256', $realPath);

            if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException('Stored verification document integrity check failed.');
            }
        }

        return [
            'path' => $realPath,
            'name' => (string) $row['document_type'] . '.' . $extension,
        ];
    }

    private function requireView(int $actorUserId): void
    {
        if (! $this->authorization->userHasPermission($actorUserId, self::VIEW_PERMISSION)) {
            throw new RuntimeException('Permission denied: ' . self::VIEW_PERMISSION);
        }
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
