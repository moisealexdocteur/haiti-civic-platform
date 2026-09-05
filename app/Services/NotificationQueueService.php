<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NotificationQueueService
{
    private BaseConnection $db;
    private TenantSecretCipher $cipher;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?TenantSecretCipher $cipher = null,
        private readonly ?NotificationTemplateCatalog $templates = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->cipher = $cipher ?? new TenantSecretCipher($tenantContext);
    }

    /**
     * @param array{email?:?string,phone?:?string} $destinations
     * @return array{id:int,uuid:string,status:string}
     */
    public function enqueue(
        string $eventKey,
        string $templateKey,
        string $audience,
        string $locale,
        array $destinations,
        array $templateArguments,
        string $idempotencySource,
        string $requestedChannel = 'auto',
        ?int $recipientUserId = null,
        ?int $citizenIdentityId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        int $priority = 50,
        bool $contentSensitive = false
    ): array {
        $tenantId = $this->tenantContext->id();
        $eventKey = $this->key($eventKey, 'event');
        $templateKey = $this->key($templateKey, 'template');
        $audience = strtolower(trim($audience));
        $locale = in_array($locale, ['fr', 'ht'], true) ? $locale : 'ht';
        $requestedChannel = strtolower(trim($requestedChannel));

        if (! in_array($audience, ['citizen', 'administrator', 'field', 'system'], true)) {
            throw new InvalidArgumentException('Notification audience is invalid.');
        }

        if (! in_array($requestedChannel, ['auto', 'whatsapp', 'sms', 'email'], true)) {
            throw new InvalidArgumentException('Notification channel is invalid.');
        }

        $destinations = $this->destinations($destinations);

        if ($destinations['email'] === null && $destinations['phone'] === null) {
            throw new InvalidArgumentException('Notification has no deliverable destination.');
        }

        $this->assertRecipientScope($tenantId, $recipientUserId, $citizenIdentityId);

        $priority = max(1, min(100, $priority));
        $idempotencySource = trim($idempotencySource);

        if ($idempotencySource === '' || strlen($idempotencySource) > 500) {
            throw new InvalidArgumentException('Notification idempotency source is invalid.');
        }

        $idempotencyKey = hash('sha256', 'v1|' . $tenantId . '|' . $idempotencySource);
        $existing = $this->existing($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $rendered = ($this->templates ?? new NotificationTemplateCatalog())
            ->render($templateKey, $locale, $templateArguments);
        $uuid = $this->uuid();
        $recipientJson = json_encode($destinations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = gmdate('Y-m-d H:i:s');

        try {
            $ok = $this->db->table('notification_messages')->insert([
                'uuid' => $uuid,
                'tenant_id' => $tenantId,
                'event_key' => $eventKey,
                'template_key' => $templateKey,
                'audience' => $audience,
                'recipient_user_id' => $recipientUserId,
                'citizen_identity_id' => $citizenIdentityId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'locale' => $locale,
                'requested_channel' => $requestedChannel,
                'recipient_ciphertext' => $this->cipher->encrypt('notification.recipient.' . $uuid, $recipientJson),
                'recipient_masked' => $this->masked($destinations),
                'subject_ciphertext' => $this->cipher->encrypt('notification.subject.' . $uuid, $rendered['subject']),
                'body_ciphertext' => $this->cipher->encrypt('notification.body.' . $uuid, $rendered['body']),
                'content_sensitive' => $contentSensitive ? 1 : 0,
                'idempotency_key' => $idempotencyKey,
                'status' => 'queued',
                'priority' => $priority,
                'attempt_count' => 0,
                'max_attempts' => 5,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $exception) {
            $existing = $this->existing($idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }

        if (! $ok) {
            $existing = $this->existing($idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            throw new RuntimeException('Could not queue notification.');
        }

        return ['id' => (int) $this->db->insertID(), 'uuid' => $uuid, 'status' => 'queued'];
    }

    private function existing(string $idempotencyKey): ?array
    {
        $row = $this->db->table('notification_messages')
            ->select('id, uuid, status')
            ->where('tenant_id', $this->tenantContext->id())
            ->where('idempotency_key', $idempotencyKey)
            ->limit(1)->get()->getFirstRow('array');

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'status' => (string) $row['status'],
        ];
    }

    private function assertRecipientScope(
        int $tenantId,
        ?int $recipientUserId,
        ?int $citizenIdentityId
    ): void {
        if ($recipientUserId !== null) {
            $belongsToTenant = $recipientUserId > 0
                && $this->db->table('tenant_users')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $recipientUserId)
                    ->countAllResults() === 1;

            if (! $belongsToTenant) {
                throw new InvalidArgumentException('Notification recipient does not belong to the current tenant.');
            }
        }

        if ($citizenIdentityId !== null) {
            $belongsToTenant = $citizenIdentityId > 0
                && $this->db->table('citizen_identities')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $citizenIdentityId)
                    ->countAllResults() === 1;

            if (! $belongsToTenant) {
                throw new InvalidArgumentException('Notification dossier does not belong to the current tenant.');
            }
        }
    }

    /** @return array{email:?string,phone:?string} */
    private function destinations(array $values): array
    {
        $email = strtolower(trim((string) ($values['email'] ?? '')));
        $phone = trim((string) ($values['phone'] ?? ''));

        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254)) {
            throw new InvalidArgumentException('Notification email is invalid.');
        }

        if ($phone !== '' && preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Notification phone is invalid.');
        }

        return ['email' => $email === '' ? null : $email, 'phone' => $phone === '' ? null : $phone];
    }

    private function masked(array $destinations): string
    {
        if ($destinations['email'] !== null) {
            [$local, $domain] = explode('@', $destinations['email'], 2);
            return mb_substr($local, 0, 2) . '***@' . $domain;
        }

        $phone = (string) $destinations['phone'];
        return substr($phone, 0, 4) . str_repeat('*', max(2, strlen($phone) - 7)) . substr($phone, -3);
    }

    private function key(string $value, string $label): string
    {
        $value = trim($value);
        $pattern = $label === 'template'
            ? '/^[a-z][A-Za-z0-9._-]{1,99}$/D'
            : '/^[a-z][a-z0-9._-]{1,99}$/D';

        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('Notification ' . $label . ' key is invalid.');
        }

        return $value;
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
