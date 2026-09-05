<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

final class NotificationDeliveryService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{processed:int,sent:int,retry:int,failed:int} */
    public function dispatchBatch(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $this->recoverStale();
        $summary = ['processed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0];

        while ($summary['processed'] < $limit) {
            $row = $this->claim();
            if ($row === null) {
                break;
            }

            $status = $this->deliver($row);
            $summary['processed']++;
            $summary[$status]++;
        }

        return $summary;
    }

    public function dispatchMessage(int $messageId): string
    {
        $row = $this->claim($messageId);
        return $row === null ? 'unavailable' : $this->deliver($row);
    }

    public static function sanitizeProviderDetail(?string $detail): ?string
    {
        $detail = trim(strip_tags((string) $detail));
        $detail = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $detail) ?? '';
        $detail = preg_replace('/\s+/', ' ', $detail) ?? '';
        $detail = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i', '$1 [secret masked]', $detail) ?? '';
        $detail = preg_replace('/\b(password|token|secret)\s*[:=]\s*\S+/i', '$1: [secret masked]', $detail) ?? '';
        $detail = preg_replace(
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            '[email masked]',
            $detail
        ) ?? '';
        $detail = preg_replace('/(?<![0-9])\+?[1-9][0-9]{7,14}(?![0-9])/', '[phone masked]', $detail) ?? '';
        return $detail === '' ? null : mb_substr($detail, 0, 500);
    }

    private function claim(?int $messageId = null): ?array
    {
        if (! $this->db->transBegin()) {
            throw new RuntimeException('Could not start notification claim transaction.');
        }

        $params = [gmdate('Y-m-d H:i:s')];
        $where = "`status` IN ('queued', 'retry') AND `available_at` <= ?";
        if ($messageId !== null) {
            $where .= ' AND `id` = ?';
            $params[] = $messageId;
        }

        $row = $this->db->query(
            'SELECT * FROM `notification_messages` WHERE ' . $where
            . ' ORDER BY `priority` DESC, `id` ASC LIMIT 1 FOR UPDATE SKIP LOCKED',
            $params
        )->getFirstRow('array');

        if ($row === null) {
            $this->db->transCommit();
            return null;
        }

        $token = $this->uuid();
        $this->db->table('notification_messages')->where('id', (int) $row['id'])->update([
            'status' => 'processing',
            'locked_at' => gmdate('Y-m-d H:i:s'),
            'lock_token' => $token,
        ]);

        if (! $this->db->transStatus() || ! $this->db->transCommit()) {
            throw new RuntimeException('Could not claim notification.');
        }

        $row['lock_token'] = $token;
        return $row;
    }

    private function deliver(array $row): string
    {
        $tenantContext = (new TenantContext())->set((int) $row['tenant_id']);
        $cipher = new TenantSecretCipher($tenantContext);
        $uuid = (string) $row['uuid'];

        try {
            $recipient = json_decode(
                $cipher->decrypt('notification.recipient.' . $uuid, (string) $row['recipient_ciphertext']),
                true,
                8,
                JSON_THROW_ON_ERROR
            );
            $subject = $cipher->decrypt('notification.subject.' . $uuid, (string) $row['subject_ciphertext']);
            $body = $cipher->decrypt('notification.body.' . $uuid, (string) $row['body_ciphertext']);
            $configuration = (new TenantCommunicationSettingsService($tenantContext, $this->db))
                ->notificationConfiguration();
            $attempt = ((int) $row['attempt_count']) + 1;

            foreach ($this->channels((string) $row['requested_channel']) as $channel) {
                try {
                    $result = $this->send(
                        $channel,
                        $recipient,
                        $subject,
                        $body,
                        (string) $row['locale'],
                        $configuration
                    );
                } catch (Throwable $channelException) {
                    $result = [
                        'accepted' => false,
                        'messageId' => null,
                        'failureCode' => $channel . '_configuration_error',
                        'providerDetail' => $channelException->getMessage(),
                    ];
                }
                $this->recordAttempt((int) $row['id'], (int) $row['tenant_id'], $attempt, $channel, $result);

                if ($result['accepted']) {
                    $this->db->table('notification_messages')
                        ->where('id', (int) $row['id'])
                        ->where('lock_token', (string) $row['lock_token'])
                        ->update([
                            'status' => 'sent',
                            'delivered_channel' => $channel,
                            'attempt_count' => $attempt,
                            'provider_message_id' => $result['messageId'],
                            'last_error_code' => null,
                            'last_error_detail' => null,
                            'sent_at' => gmdate('Y-m-d H:i:s'),
                            'locked_at' => null,
                            'lock_token' => null,
                        ]);
                    return 'sent';
                }

                $lastResult = $result;
                if (! ($result['skipped'] ?? false)) {
                    $lastFailure = $result;
                }
            }

            return $this->reschedule($row, $attempt, $lastFailure ?? $lastResult ?? [
                'failureCode' => 'no_validated_channel', 'providerDetail' => null,
            ]);
        } catch (Throwable $exception) {
            $attempt = ((int) $row['attempt_count']) + 1;
            return $this->reschedule($row, $attempt, [
                'failureCode' => 'notification_processing_error',
                'providerDetail' => $exception->getMessage(),
            ]);
        }
    }

    private function send(
        string $channel,
        array $recipient,
        string $subject,
        string $body,
        string $locale,
        array $configuration
    ): array {
        $email = is_string($recipient['email'] ?? null) ? $recipient['email'] : null;
        $phone = is_string($recipient['phone'] ?? null) ? $recipient['phone'] : null;

        if ($channel === 'email') {
            if ($email === null || $configuration['email'] === null) {
                return $this->unavailable('email_unavailable');
            }
            return (new SmtpMessageSender($configuration['email']))->send($email, $subject, $body);
        }

        if ($channel === 'sms') {
            if ($phone === null || $configuration['sms'] === null) {
                return $this->unavailable('sms_unavailable');
            }
            $value = $configuration['sms'];
            return (new TwilioSmsMessageSender(
                $value['account_sid'], $value['auth_token'], $value['from_number'], $value['messaging_service_sid']
            ))->send($phone, mb_substr($body, 0, 600));
        }

        $value = $configuration['whatsapp'];
        if ($phone === null || $value === null || $value['notification_template_name'] === null) {
            return $this->unavailable('whatsapp_notification_template_unavailable');
        }

        $language = $locale === 'fr' ? 'fr' : (string) $value['notification_template_language'];
        return (new MetaWhatsAppMessageSender(
            $value['graph_version'], $value['phone_number_id'], $value['access_token'],
            $value['notification_template_name'], $language
        ))->send($phone, $body);
    }

    private function recordAttempt(int $messageId, int $tenantId, int $attempt, string $channel, array $result): void
    {
        $this->db->table('notification_attempts')->insert([
            'tenant_id' => $tenantId,
            'notification_message_id' => $messageId,
            'attempt_number' => $attempt,
            'channel' => $channel,
            'status' => $result['accepted'] ? 'sent' : (($result['skipped'] ?? false) ? 'skipped' : 'failed'),
            'provider_message_id' => $result['messageId'] ?? null,
            'error_code' => $result['failureCode'] ?? null,
            'error_detail' => self::sanitizeProviderDetail($result['providerDetail'] ?? null),
            'attempted_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function reschedule(array $row, int $attempt, array $result): string
    {
        $failed = $attempt >= (int) $row['max_attempts'];
        $delay = [60, 300, 1800, 7200, 21600][$attempt - 1] ?? 21600;
        $status = $failed ? 'failed' : 'retry';
        $this->db->table('notification_messages')
            ->where('id', (int) $row['id'])
            ->where('lock_token', (string) $row['lock_token'])
            ->update([
                'status' => $status,
                'attempt_count' => $attempt,
                'available_at' => gmdate('Y-m-d H:i:s', time() + $delay),
                'last_error_code' => (string) ($result['failureCode'] ?? 'delivery_failed'),
                'last_error_detail' => self::sanitizeProviderDetail($result['providerDetail'] ?? null),
                'locked_at' => null,
                'lock_token' => null,
            ]);
        return $status;
    }

    private function recoverStale(): void
    {
        $this->db->table('notification_messages')
            ->where('status', 'processing')
            ->where('locked_at <', gmdate('Y-m-d H:i:s', time() - 900))
            ->update([
                'status' => 'retry', 'lock_token' => null, 'locked_at' => null,
                'available_at' => gmdate('Y-m-d H:i:s'), 'last_error_code' => 'worker_lock_expired',
            ]);
    }

    private function channels(string $requested): array
    {
        return match ($requested) {
            'email' => ['email', 'sms', 'whatsapp'],
            'sms' => ['sms', 'email', 'whatsapp'],
            'whatsapp' => ['whatsapp', 'sms', 'email'],
            default => ['whatsapp', 'sms', 'email'],
        };
    }

    private function unavailable(string $code): array
    {
        return ['accepted' => false, 'messageId' => null, 'failureCode' => $code, 'providerDetail' => null, 'skipped' => true];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
