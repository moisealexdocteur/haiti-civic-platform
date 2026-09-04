<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ManualIdentityAuthorityCheckService
{
    private const MANAGE_PERMISSION = 'identity.manage';
    private const PROVIDER = 'oni_delidoc';
    private const OUTCOMES = ['confirmed', 'not_confirmed', 'unavailable'];

    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->authorization = new AuthorizationService(
            $tenantContext,
            $this->db
        );
        $this->audit = new AuditService($tenantContext, $this->db);
    }

    public function record(
        int $actorUserId,
        string $identityUuid,
        string $outcome,
        ?string $evidenceReference = null,
        ?string $note = null
    ): array {
        if (! $this->authorization->userHasPermission(
            $actorUserId,
            self::MANAGE_PERMISSION
        )) {
            throw new RuntimeException(
                'Permission denied: ' . self::MANAGE_PERMISSION
            );
        }

        $identityUuid = $this->normalizeUuid($identityUuid);
        $outcome = strtolower(trim($outcome));

        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException(
                'Identity authority outcome is invalid.'
            );
        }

        $evidenceReference = $this->optionalText(
            $evidenceReference,
            120,
            'Evidence reference'
        );
        $note = $this->optionalText($note, 500, 'Internal note');
        $tenantId = $this->tenantContext->id();
        $identity = $this->db->table('citizen_identities')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('uuid', $identityUuid)
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($identity === null) {
            throw new InvalidArgumentException(
                'Citizen identity does not exist in the current tenant.'
            );
        }

        $identityId = (int) $identity['id'];
        $occurredAt = gmdate('Y-m-d H:i:s');
        $context = [
            'provider' => self::PROVIDER,
            'outcome' => $outcome,
            'evidence_reference' => $evidenceReference,
            'note' => $note,
            'automated' => false,
        ];
        $inserted = $this->db->table('identity_verification_events')->insert([
            'uuid' => $this->uuid(),
            'tenant_id' => $tenantId,
            'citizen_identity_id' => $identityId,
            'event_type' => 'identity.authority_checked',
            'actor_user_id' => $actorUserId,
            'context_json' => json_encode(
                $context,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ),
            'occurred_at' => $occurredAt,
        ]);

        if (! $inserted) {
            throw new RuntimeException(
                'Identity authority check could not be recorded.'
            );
        }

        $eventId = (int) $this->db->insertID();

        try {
            $this->audit->record(
                event: 'citizen_identity.authority_checked',
                actorUserId: $actorUserId,
                entityType: 'citizen_identity',
                entityId: $identityId,
                context: [
                    'provider' => self::PROVIDER,
                    'outcome' => $outcome,
                    'evidence_reference_present' =>
                        $evidenceReference !== null,
                    'automated' => false,
                ]
            );
        } catch (Throwable $exception) {
            $this->db->table('identity_verification_events')
                ->where('id', $eventId)
                ->where('tenant_id', $tenantId)
                ->delete();

            throw $exception;
        }

        return $context + ['occurred_at' => $occurredAt];
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));

        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-'
            . '[0-9a-f]{4}-[0-9a-f]{12}$/D',
            $uuid
        ) !== 1) {
            throw new InvalidArgumentException('UUID is invalid.');
        }

        return $uuid;
    }

    private function optionalText(
        ?string $value,
        int $maximum,
        string $label
    ): ?string {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException(
                $label . ' exceeds its maximum length.'
            );
        }

        return $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
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
