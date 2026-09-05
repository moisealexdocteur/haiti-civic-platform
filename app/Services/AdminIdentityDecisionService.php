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
        ?string $reasonCode = null,
        bool $manualContactReviewed = false
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
        $toStatus = strtolower(trim($toStatus));
        $row = $this->db
            ->table('citizen_identities')
            ->select('id, contact_verification_status')
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

        if (
            $toStatus === IdentityVerificationStateMachine::VERIFIED
            && (string) $row['contact_verification_status']
                === ContactVerificationStatus::MANUAL_REVIEW
            && ! $manualContactReviewed
        ) {
            throw new InvalidArgumentException(
                'Manual contact review must be confirmed.'
            );
        }

        if (
            $toStatus === IdentityVerificationStateMachine::VERIFIED
            && ! $this->hasConfirmedAuthorityCheck((int) $row['id'])
        ) {
            throw new InvalidArgumentException(
                'A confirmed identity authority check is required.'
            );
        }

        $this->identityWrite->transitionVerificationStatus(
            $actorUserId,
            (int) $row['id'],
            $toStatus,
            $reasonCode === null ? null : trim($reasonCode),
            additionalContext: [
                'manual_contact_reviewed' =>
                    (string) $row['contact_verification_status']
                        === ContactVerificationStatus::MANUAL_REVIEW
                    && $manualContactReviewed,
            ]
        );

        try {
            (new NotificationOrchestrator($this->tenantContext, $this->db))
                ->identityDecision(
                    (int) $row['id'],
                    $toStatus,
                    $reasonCode === null ? null : trim($reasonCode)
                );
        } catch (\Throwable $exception) {
            log_message('error', 'Decision notifications could not be queued: {type}', [
                'type' => $exception::class,
            ]);
        }
    }

    private function hasConfirmedAuthorityCheck(int $identityId): bool
    {
        $row = $this->db->table('identity_verification_events')
            ->select('context_json')
            ->where('tenant_id', $this->tenantContext->id())
            ->where('citizen_identity_id', $identityId)
            ->where('event_type', 'identity.authority_checked')
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        if ($row === null) {
            return false;
        }

        $context = json_decode((string) $row['context_json'], true);

        return is_array($context)
            && ($context['provider'] ?? null) === 'oni_delidoc'
            && ($context['outcome'] ?? null) === 'confirmed'
            && ($context['automated'] ?? null) === false;
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
