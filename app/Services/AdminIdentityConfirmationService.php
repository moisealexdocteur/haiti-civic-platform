<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

final class AdminIdentityConfirmationService
{
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->authorization = new AuthorizationService($tenantContext, $this->db);
        $this->audit = new AuditService($tenantContext, $this->db);
    }

    /** @return array{identity:array,channel:string,messageId:string} */
    public function resend(int $actorUserId, string $identityUuid, string $baseUrl): array
    {
        if (! $this->authorization->userHasPermission($actorUserId, 'identity.manage')) {
            throw new RuntimeException('Permission denied: identity.manage');
        }
        $identity = (new AdminIdentityReadService(
            $this->tenantContext,
            $this->db,
            $this->authorization
        ))->detailForActorByUuid($actorUserId, $identityUuid);

        if ($identity === null) {
            throw new RuntimeException('Identity record was not found.');
        }

        $notification = (new NotificationOrchestrator($this->tenantContext, $this->db))
            ->confirmationRequested(
                (int) $identity['record_id'],
                gmdate('YmdHis') . ':' . bin2hex(random_bytes(6))
            );
        $deliveryStatus = (new NotificationDeliveryService($this->db))
            ->dispatchMessage((int) $notification['id']);
        $message = $this->db->table('notification_messages')
            ->select('delivered_channel, provider_message_id, status, last_error_code')
            ->where('id', (int) $notification['id'])
            ->limit(1)->get()->getFirstRow('array');

        $context = [
            'channel' => $message['delivered_channel'] ?? 'queued',
            'delivery_status' => $deliveryStatus,
            'failure_code' => $message['last_error_code'] ?? null,
        ];

        $this->audit->record(
            event: ($message['status'] ?? null) === 'sent'
                ? 'citizen_identity.confirmation_resent'
                : 'citizen_identity.confirmation_queued',
            actorUserId: $actorUserId,
            entityType: 'citizen_identity',
            entityId: (int) $identity['record_id'],
            context: $context
        );

        return [
            'identity' => $identity,
            'channel' => (string) ($message['delivered_channel'] ?? 'queued'),
            'messageId' => (string) ($message['provider_message_id'] ?? $notification['uuid']),
        ];
    }
}
