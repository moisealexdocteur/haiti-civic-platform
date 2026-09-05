<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

final class NotificationLedgerService
{
    private BaseConnection $db;

    public function __construct(private readonly TenantContext $tenantContext, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function recordDeliveredOtp(
        string $challengeUuid,
        string $purpose,
        string $channel,
        ?string $phone,
        ?string $email,
        ?string $providerMessageId,
        ?string $locale = null
    ): void {
        if (($phone === null || $phone === '') && ($email === null || $email === '')) {
            return;
        }

        $localeRow = $this->db->table('tenants')->select('default_locale')
            ->where('id', $this->tenantContext->id())->limit(1)->get()->getFirstRow('array');
        $locale = ($locale ?? (string) ($localeRow['default_locale'] ?? 'ht')) === 'fr'
            ? 'fr'
            : 'ht';
        $purposeKey = $purpose === 'citizen_identity_tracking'
            ? 'purposeCitizenTracking' : 'purposeCitizenPhone';

        try {
            if (! $this->db->transBegin()) {
                return;
            }
            $message = (new NotificationQueueService($this->tenantContext, $this->db))->enqueue(
                'otp.' . $purpose . '.delivered', 'otpRedacted', 'citizen', $locale,
                ['email' => $email, 'phone' => $phone],
                [lang('Notifications.' . $purposeKey, [], $locale)],
                'otp:' . $challengeUuid . ':delivered', $channel,
                entityType: 'otp_challenge', priority: 100
            );
            $this->db->table('notification_messages')->where('id', (int) $message['id'])->update([
                'status' => 'sent', 'delivered_channel' => $channel,
                'attempt_count' => 1, 'provider_message_id' => $providerMessageId,
                'sent_at' => gmdate('Y-m-d H:i:s'), 'last_error_code' => null,
                'last_error_detail' => null,
            ]);
            if ($this->db->table('notification_attempts')
                ->where('notification_message_id', (int) $message['id'])->countAllResults() === 0) {
                $this->db->table('notification_attempts')->insert([
                    'tenant_id' => $this->tenantContext->id(),
                    'notification_message_id' => (int) $message['id'],
                    'attempt_number' => 1, 'channel' => $channel, 'status' => 'sent',
                    'provider_message_id' => $providerMessageId,
                    'attempted_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $this->db->transCommit();
        } catch (Throwable $exception) {
            $this->db->transRollback();
            log_message('error', 'OTP notification ledger could not be written: {type}', [
                'type' => $exception::class,
            ]);
        }
    }
}
