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

        $phone = (string) ($identity['phone'] ?? '');

        if ($phone === '') {
            throw new RuntimeException('Identity record has no phone number.');
        }

        $config = (new TenantCommunicationSettingsService(
            $this->tenantContext,
            $this->db
        ))->smsConfiguration();

        if ($config === null) {
            throw new RuntimeException('No validated SMS channel is available.');
        }

        $reference = (string) $identity['public_reference'];
        $trackingUrl = rtrim($baseUrl, '/') . '/swiv/' . rawurlencode($reference);
        $message = 'Dosye ou / Votre dossier: ' . $reference
            . '. Swiv / Suivi: ' . $trackingUrl;
        $sender = new TwilioSmsMessageSender(
            $config['account_sid'],
            $config['auth_token'],
            $config['from_number'],
            $config['messaging_service_sid']
        );
        $result = $sender->send($phone, $message);

        $context = [
            'channel' => 'sms',
            'accepted' => $result['accepted'],
            'failure_code' => $result['failureCode'],
        ];

        $this->audit->record(
            event: $result['accepted']
                ? 'citizen_identity.confirmation_resent'
                : 'citizen_identity.confirmation_resend_failed',
            actorUserId: $actorUserId,
            entityType: 'citizen_identity',
            entityId: (int) $identity['record_id'],
            context: $context
        );

        if (! $result['accepted']) {
            throw new RuntimeException(
                'Confirmation delivery failed: ' . ($result['failureCode'] ?? 'unknown')
            );
        }

        return [
            'identity' => $identity,
            'channel' => 'sms',
            'messageId' => (string) $result['messageId'],
        ];
    }
}
