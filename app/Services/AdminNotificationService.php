<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

final class AdminNotificationService
{
    private BaseConnection $db;
    private AuthorizationService $authorization;
    private AuditService $audit;

    public function __construct(private readonly TenantContext $tenantContext, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->authorization = new AuthorizationService($tenantContext, $this->db);
        $this->audit = new AuditService($tenantContext, $this->db);
    }

    public function page(
        int $actorUserId,
        string $status = 'all',
        string $audience = 'all',
        string $channel = 'all',
        int $page = 1,
        int $perPage = 50
    ): array {
        $this->require($actorUserId, 'notifications.view');
        $statuses = ['all', 'queued', 'processing', 'retry', 'sent', 'failed', 'cancelled'];
        $audiences = ['all', 'citizen', 'administrator', 'field', 'system'];
        $channels = ['all', 'auto', 'whatsapp', 'sms', 'email'];
        $status = in_array($status, $statuses, true) ? $status : 'all';
        $audience = in_array($audience, $audiences, true) ? $audience : 'all';
        $channel = in_array($channel, $channels, true) ? $channel : 'all';
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;

        $base = $this->db->table('notification_messages')
            ->where('tenant_id', $this->tenantContext->id());
        if ($status !== 'all') {
            $base->where('status', $status);
        }
        if ($audience !== 'all') {
            $base->where('audience', $audience);
        }
        if ($channel !== 'all') {
            $channel === 'auto'
                ? $base->where('requested_channel', 'auto')
                : $base->groupStart()->where('delivered_channel', $channel)
                    ->orWhere('requested_channel', $channel)->groupEnd();
        }

        $total = $base->countAllResults(false);
        $rows = $base->select(
            'id, uuid, event_key, audience, locale, requested_channel, delivered_channel, '
            . 'recipient_masked, status, attempt_count, max_attempts, available_at, '
            . 'last_error_code, sent_at, created_at'
        )->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        $counts = array_fill_keys(['queued', 'processing', 'retry', 'sent', 'failed', 'cancelled'], 0);
        foreach ($this->db->table('notification_messages')->select('status, COUNT(*) AS total')
            ->where('tenant_id', $this->tenantContext->id())->groupBy('status')->get()->getResultArray() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)), 'status' => $status,
            'audience' => $audience, 'channel' => $channel, 'counts' => $counts,
        ];
    }

    public function detail(int $actorUserId, string $uuid): ?array
    {
        $this->require($actorUserId, 'notifications.view');
        $uuid = $this->uuid($uuid);
        $row = $this->db->table('notification_messages')
            ->where('tenant_id', $this->tenantContext->id())->where('uuid', $uuid)
            ->limit(1)->get()->getFirstRow('array');
        if ($row === null) {
            return null;
        }
        $cipher = new TenantSecretCipher($this->tenantContext);
        $row['subject'] = $cipher->decrypt('notification.subject.' . $uuid, (string) $row['subject_ciphertext']);
        $row['body'] = (int) ($row['content_sensitive'] ?? 0) === 1
            ? lang('Admin.notificationSensitiveHidden')
            : $cipher->decrypt('notification.body.' . $uuid, (string) $row['body_ciphertext']);
        unset($row['recipient_ciphertext'], $row['subject_ciphertext'], $row['body_ciphertext'], $row['idempotency_key']);
        $row['attempts'] = $this->db->table('notification_attempts')
            ->select('attempt_number, channel, status, provider_message_id, error_code, error_detail, attempted_at')
            ->where('tenant_id', $this->tenantContext->id())
            ->where('notification_message_id', (int) $row['id'])
            ->orderBy('id', 'ASC')->get()->getResultArray();
        return $row;
    }

    public function retry(int $actorUserId, string $uuid): void
    {
        $this->require($actorUserId, 'notifications.manage');
        $uuid = $this->uuid($uuid);
        $row = $this->message($uuid);
        if (! in_array((string) $row['status'], ['failed', 'cancelled', 'retry'], true)) {
            throw new InvalidArgumentException('Only failed, cancelled or delayed messages can be retried.');
        }
        $maxAttempts = min(20, max((int) $row['max_attempts'], (int) $row['attempt_count'] + 5));
        $this->db->table('notification_messages')->where('id', (int) $row['id'])->update([
            'status' => 'queued', 'max_attempts' => $maxAttempts,
            'available_at' => gmdate('Y-m-d H:i:s'), 'locked_at' => null, 'lock_token' => null,
        ]);
        $this->audit->record('notification.retried', $actorUserId, 'user', 'notification_message', (int) $row['id']);
    }

    public function cancel(int $actorUserId, string $uuid): void
    {
        $this->require($actorUserId, 'notifications.manage');
        $uuid = $this->uuid($uuid);
        $row = $this->message($uuid);
        if (! in_array((string) $row['status'], ['queued', 'retry'], true)) {
            throw new InvalidArgumentException('Only queued messages can be cancelled.');
        }
        $this->db->table('notification_messages')->where('id', (int) $row['id'])->update([
            'status' => 'cancelled', 'locked_at' => null, 'lock_token' => null,
        ]);
        $this->audit->record('notification.cancelled', $actorUserId, 'user', 'notification_message', (int) $row['id']);
    }

    private function message(string $uuid): array
    {
        $row = $this->db->table('notification_messages')->select('id, status, attempt_count, max_attempts')
            ->where('tenant_id', $this->tenantContext->id())->where('uuid', $uuid)
            ->limit(1)->get()->getFirstRow('array');
        if ($row === null) {
            throw new InvalidArgumentException('Notification was not found.');
        }
        return $row;
    }

    private function require(int $actorUserId, string $permission): void
    {
        if (! $this->authorization->userHasPermission($actorUserId, $permission)) {
            throw new RuntimeException('Permission denied: ' . $permission);
        }
    }

    private function uuid(string $uuid): string
    {
        $uuid = strtolower(trim(rawurldecode($uuid)));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uuid) !== 1) {
            throw new InvalidArgumentException('Notification UUID is invalid.');
        }
        return $uuid;
    }
}
