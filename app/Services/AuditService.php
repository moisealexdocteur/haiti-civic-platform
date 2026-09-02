<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AuditService
{
    private const LOCK_TIMEOUT_SECONDS = 5;

    private TenantContext $tenantContext;
    private BaseConnection $db;

    public function __construct(
        TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->tenantContext = $tenantContext;
        $this->db = $db ?? Database::connect();
    }

    public function record(
        string $event,
        ?int $actorUserId = null,
        string $actorType = 'user',
        ?string $entityType = null,
        int|string|null $entityId = null,
        ?string $requestId = null,
        ?string $ipHash = null,
        array $context = []
    ): int {
        $tenantId = $this->tenantContext->id();

        $event = trim($event);
        $actorType = trim($actorType);

        if ($event === '' || mb_strlen($event) > 120) {
            throw new InvalidArgumentException(
                'Audit event must contain 1 to 120 characters.'
            );
        }

        if (
            $actorType === ''
            || mb_strlen($actorType) > 30
        ) {
            throw new InvalidArgumentException(
                'Actor type must contain 1 to 30 characters.'
            );
        }

        if (
            $actorType === 'user'
            && $actorUserId === null
        ) {
            throw new InvalidArgumentException(
                'A user actor requires an actor user ID.'
            );
        }

        if ($actorUserId !== null && $actorUserId <= 0) {
            throw new InvalidArgumentException(
                'Actor user ID must be positive when provided.'
            );
        }

        if ($actorUserId !== null) {
            $this->assertActorBelongsToTenant(
                $tenantId,
                $actorUserId
            );
        }

        $entityType = $this->normalizeNullableString(
            $entityType,
            100,
            'Entity type'
        );

        $entityId = $entityId === null
            ? null
            : $this->normalizeNullableString(
                (string) $entityId,
                100,
                'Entity ID'
            );

        $requestId ??= $this->uuid();

        if (! preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-'
            . '[0-9a-f]{4}-[0-9a-f]{4}-'
            . '[0-9a-f]{12}$/i',
            $requestId
        )) {
            throw new InvalidArgumentException(
                'Request ID must be a UUID.'
            );
        }

        if (
            $ipHash !== null
            && ! preg_match('/^[0-9a-f]{64}$/i', $ipHash)
        ) {
            throw new InvalidArgumentException(
                'IP hash must be a SHA-256 hexadecimal value.'
            );
        }

        $ipHash = $ipHash === null
            ? null
            : strtolower($ipHash);

        $contextJson = $this->encodeCanonical($context);
        $occurredAt = gmdate('Y-m-d H:i:s');

        $lockName = 'civic_audit_tenant_' . $tenantId;

        $this->acquireLock($lockName);

        try {
            $this->db->transBegin();

            $previous = $this->db
                ->table('audit_logs')
                ->select('entry_hash')
                ->where('tenant_id', $tenantId)
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getFirstRow('array');

            $prevHash = $previous['entry_hash'] ?? null;

            if (
                $prevHash !== null
                && ! preg_match(
                    '/^[0-9a-f]{64}$/',
                    $prevHash
                )
            ) {
                throw new RuntimeException(
                    'Cannot append to an invalid audit chain.'
                );
            }

            $row = [
                'tenant_id'     => $tenantId,
                'actor_user_id' => $actorUserId,
                'actor_type'    => $actorType,
                'event'         => $event,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'request_id'    => strtolower($requestId),
                'ip_hash'       => $ipHash,
                'context_json'  => $contextJson,
                'prev_hash'     => $prevHash,
                'occurred_at'   => $occurredAt,
            ];

            $row['entry_hash'] = $this->hashRow($row);

            $inserted = $this->db
                ->table('audit_logs')
                ->insert($row);

            if (! $inserted) {
                throw new RuntimeException(
                    'Audit log insert failed.'
                );
            }

            $id = (int) $this->db->insertID();

            if (! $this->db->transStatus()) {
                throw new RuntimeException(
                    'Audit transaction failed.'
                );
            }

            $this->db->transCommit();

            return $id;
        } catch (Throwable $exception) {
            $this->db->transRollback();

            throw $exception;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function verifyCurrentTenantChain(): array
    {
        $tenantId = $this->tenantContext->id();

        $rows = $this->db
            ->table('audit_logs')
            ->where('tenant_id', $tenantId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $expectedPrevHash = null;

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $storedPrevHash =
                $row['prev_hash'] === null
                    ? null
                    : (string) $row['prev_hash'];

            if ($storedPrevHash !== $expectedPrevHash) {
                return [
                    'valid'     => false,
                    'count'     => count($rows),
                    'broken_id' => $id,
                    'reason'    => 'prev_hash_mismatch',
                ];
            }

            $storedEntryHash =
                $row['entry_hash'] === null
                    ? null
                    : (string) $row['entry_hash'];

            if (
                $storedEntryHash === null
                || ! preg_match(
                    '/^[0-9a-f]{64}$/',
                    $storedEntryHash
                )
            ) {
                return [
                    'valid'     => false,
                    'count'     => count($rows),
                    'broken_id' => $id,
                    'reason'    => 'missing_or_invalid_entry_hash',
                ];
            }

            $expectedEntryHash = $this->hashRow([
                'tenant_id'     => (int) $row['tenant_id'],
                'actor_user_id' => $row['actor_user_id'] === null
                    ? null
                    : (int) $row['actor_user_id'],
                'actor_type'    => (string) $row['actor_type'],
                'event'         => (string) $row['event'],
                'entity_type'   => $row['entity_type'] === null
                    ? null
                    : (string) $row['entity_type'],
                'entity_id'     => $row['entity_id'] === null
                    ? null
                    : (string) $row['entity_id'],
                'request_id'    => $row['request_id'] === null
                    ? null
                    : (string) $row['request_id'],
                'ip_hash'       => $row['ip_hash'] === null
                    ? null
                    : (string) $row['ip_hash'],
                'context_json'  => $row['context_json'] === null
                    ? ''
                    : (string) $row['context_json'],
                'prev_hash'     => $storedPrevHash,
                'occurred_at'   => (string) $row['occurred_at'],
            ]);

            if (! hash_equals(
                $expectedEntryHash,
                $storedEntryHash
            )) {
                return [
                    'valid'     => false,
                    'count'     => count($rows),
                    'broken_id' => $id,
                    'reason'    => 'entry_hash_mismatch',
                ];
            }

            $expectedPrevHash = $storedEntryHash;
        }

        return [
            'valid'     => true,
            'count'     => count($rows),
            'broken_id' => null,
            'reason'    => null,
        ];
    }

    private function hashRow(array $row): string
    {
        return hash(
            'sha256',
            $this->encodeCanonical([
                'tenant_id'     => $row['tenant_id'],
                'actor_user_id' => $row['actor_user_id'],
                'actor_type'    => $row['actor_type'],
                'event'         => $row['event'],
                'entity_type'   => $row['entity_type'],
                'entity_id'     => $row['entity_id'],
                'request_id'    => $row['request_id'],
                'ip_hash'       => $row['ip_hash'],
                'context_json'  => $row['context_json'],
                'prev_hash'     => $row['prev_hash'],
                'occurred_at'   => $row['occurred_at'],
            ])
        );
    }

    private function encodeCanonical(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException(
                    'Audit context must be JSON serializable.'
                );
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn ($item) => $this->canonicalize($item),
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function assertActorBelongsToTenant(
        int $tenantId,
        int $actorUserId
    ): void {
        $row = $this->db
            ->table('tenant_users tu')
            ->select('tu.user_id')
            ->join(
                'users u',
                'u.id = tu.user_id'
            )
            ->where('tu.tenant_id', $tenantId)
            ->where('tu.user_id', $actorUserId)
            ->where('tu.status', 'active')
            ->where('u.status', 'active')
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            throw new InvalidArgumentException(
                'Actor user is not an active member '
                . 'of the current tenant.'
            );
        }
    }

    private function normalizeNullableString(
        ?string $value,
        int $maxLength,
        string $field
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                $field . ' exceeds its maximum length.'
            );
        }

        return $value;
    }

    private function acquireLock(string $lockName): void
    {
        $row = $this->db
            ->query(
                'SELECT GET_LOCK(?, ?) AS acquired',
                [
                    $lockName,
                    self::LOCK_TIMEOUT_SECONDS,
                ]
            )
            ->getFirstRow('array');

        if ((int) ($row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException(
                'Could not acquire the audit chain lock.'
            );
        }
    }

    private function releaseLock(string $lockName): void
    {
        $this->db->query(
            'SELECT RELEASE_LOCK(?)',
            [$lockName]
        );
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f) | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f) | 0x80
        );

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
