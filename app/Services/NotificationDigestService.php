<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

final class NotificationDigestService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{queued:int,skipped:int,failed:int} */
    public function queueForAllTenants(?string $date = null): array
    {
        $date = $date ?? gmdate('Y-m-d');
        $summary = ['queued' => 0, 'skipped' => 0, 'failed' => 0];
        $tenants = $this->db->table('tenants')->select('id')
            ->where('status', 'active')->where('deleted_at', null)->get()->getResultArray();

        foreach ($tenants as $tenant) {
            try {
                $this->queueTenant((int) $tenant['id'], $date, $summary);
            } catch (Throwable $exception) {
                $summary['failed']++;
                log_message('error', 'Daily notification digest failed for tenant {tenant}: {type}', [
                    'tenant' => (int) $tenant['id'], 'type' => $exception::class,
                ]);
            }
        }
        return $summary;
    }

    private function queueTenant(int $tenantId, string $date, array &$summary): void
    {
        $context = (new TenantContext())->set($tenantId);
        $queue = new NotificationQueueService($context, $this->db);
        $counts = $this->identityCounts($tenantId);
        $failed = $this->db->table('notification_messages')->where('tenant_id', $tenantId)
            ->where('status', 'failed')->countAllResults();

        foreach ($this->administrators($tenantId) as $user) {
            if ($this->digestExists($tenantId, (int) $user['id'], $date, 'administrator')) {
                $summary['skipped']++;
                continue;
            }
            $locale = (string) $user['locale'] === 'fr' ? 'fr' : 'ht';
            $message = $queue->enqueue(
                'report.daily.administrator', 'adminDigest', 'administrator', $locale,
                ['email' => (string) $user['email'], 'phone' => $this->phone($context, $user)],
                [$date, $counts['pending'], $counts['verified'], $counts['rejected'], $failed, $this->url('admin')],
                'digest:' . $date . ':administrator:' . (int) $user['id'], $this->channel($user),
                recipientUserId: (int) $user['id'], entityType: 'daily_report', priority: 30
            );
            $this->recordDigest($tenantId, (int) $user['id'], $date, 'administrator', (int) $message['id']);
            $summary['queued']++;
        }

        foreach ($this->fieldUsers($tenantId) as $user) {
            if ($this->digestExists($tenantId, (int) $user['id'], $date, 'field')) {
                $summary['skipped']++;
                continue;
            }
            $locale = (string) $user['locale'] === 'fr' ? 'fr' : 'ht';
            $department = $user['field_department_code'] ?? null;
            $fieldCounts = $this->fieldCounts($tenantId, $department, $date);
            $departmentName = $department === null
                ? lang('Notifications.allDepartments', [], $locale)
                : ((new HaitiDepartmentCatalog())->options($locale)[(string) $department] ?? (string) $department);
            $message = $queue->enqueue(
                'report.daily.field', 'fieldDigest', 'field', $locale,
                ['email' => (string) $user['email'], 'phone' => $this->phone($context, $user)],
                [$date, $departmentName, $fieldCounts['pending'], $fieldCounts['new'], $this->url('admin/identites?status=pending')],
                'digest:' . $date . ':field:' . (int) $user['id'], $this->channel($user),
                recipientUserId: (int) $user['id'], entityType: 'daily_report', priority: 30
            );
            $this->recordDigest($tenantId, (int) $user['id'], $date, 'field', (int) $message['id']);
            $summary['queued']++;
        }
    }

    private function identityCounts(int $tenantId): array
    {
        $counts = ['pending' => 0, 'verified' => 0, 'rejected' => 0];
        foreach ($this->db->table('citizen_identities')->select('verification_status, COUNT(*) AS total')
            ->where('tenant_id', $tenantId)->groupBy('verification_status')->get()->getResultArray() as $row) {
            $counts[(string) $row['verification_status']] = (int) $row['total'];
        }
        return $counts;
    }

    private function fieldCounts(int $tenantId, ?string $department, string $date): array
    {
        $pending = $this->db->table('citizen_identities')->where('tenant_id', $tenantId)
            ->where('verification_status', 'pending');
        $new = $this->db->table('citizen_identities')->where('tenant_id', $tenantId)
            ->where('created_at >=', $date . ' 00:00:00')->where('created_at <=', $date . ' 23:59:59');
        if ($department !== null) {
            $pending->where('department_code', $department);
            $new->where('department_code', $department);
        }
        return ['pending' => $pending->countAllResults(), 'new' => $new->countAllResults()];
    }

    private function administrators(int $tenantId): array
    {
        return $this->recipients($tenantId)->where('p.code', 'notifications.view')->get()->getResultArray();
    }

    private function fieldUsers(int $tenantId): array
    {
        return $this->recipients($tenantId)->where('tu.field_mode_enabled', 1)
            ->where('p.code', 'identity.view')->get()->getResultArray();
    }

    private function recipients(int $tenantId)
    {
        return $this->db->table('tenant_users tu')->distinct()
            ->select('u.id, u.email, u.locale, tu.field_department_code, '
                . 'tu.notification_phone_ciphertext, tu.preferred_notification_channel')
            ->join('users u', 'u.id = tu.user_id')
            ->join('user_roles ur', 'ur.tenant_id = tu.tenant_id AND ur.user_id = tu.user_id')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('tu.tenant_id', $tenantId)->where('tu.status', 'active')
            ->where('u.status', 'active')->where('u.deleted_at', null);
    }

    private function digestExists(int $tenantId, int $userId, string $date, string $audience): bool
    {
        return $this->db->table('notification_digest_runs')->where([
            'tenant_id' => $tenantId, 'recipient_user_id' => $userId,
            'digest_date' => $date, 'audience' => $audience,
        ])->countAllResults() > 0;
    }

    private function recordDigest(int $tenantId, int $userId, string $date, string $audience, int $messageId): void
    {
        $this->db->table('notification_digest_runs')->insert([
            'tenant_id' => $tenantId, 'recipient_user_id' => $userId,
            'digest_date' => $date, 'audience' => $audience,
            'notification_message_id' => $messageId,
        ]);
    }

    private function phone(TenantContext $context, array $user): ?string
    {
        $payload = $user['notification_phone_ciphertext'] ?? null;

        return is_string($payload) && $payload !== ''
            ? (new TenantSecretCipher($context))->decrypt('tenant_user.phone.' . (int) $user['id'], $payload)
            : null;
    }

    private function channel(array $user): string
    {
        $channel = (string) ($user['preferred_notification_channel'] ?? 'email');
        return in_array($channel, ['auto', 'whatsapp', 'sms', 'email'], true) ? $channel : 'email';
    }

    private function url(string $path): string
    {
        return rtrim((string) config('App')->baseURL, '/') . '/' . ltrim($path, '/');
    }
}
