<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;

final class NotificationOrchestrator
{
    private BaseConnection $db;
    private NotificationQueueService $queue;
    private IdentityCryptoService $identityCrypto;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?BaseConnection $db = null,
        ?NotificationQueueService $queue = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->queue = $queue ?? new NotificationQueueService($tenantContext, $this->db);
        $this->identityCrypto = new IdentityCryptoService($tenantContext);
    }

    public function identitySubmitted(int $identityId): void
    {
        $identity = $this->identity($identityId);
        $reference = (string) $identity['public_reference'];
        $trackingUrl = $this->url('swiv/' . rawurlencode($reference));
        $contact = (string) $identity['contact_verification_status'];

        $this->enqueueCitizen(
            $identity,
            'identity.submitted.citizen',
            'submissionCitizen',
            [$reference, $trackingUrl],
            'identity:' . $identityId . ':submission:citizen',
            80
        );

        $adminTemplate = $contact === ContactVerificationStatus::MANUAL_REVIEW
            ? 'manualReviewAdmin'
            : 'newSubmissionAdmin';

        foreach ($this->administrators('identity.manage') as $user) {
            $locale = $this->locale((string) $user['locale']);
            $args = $adminTemplate === 'manualReviewAdmin'
                ? [$reference, $this->url('admin/identites/' . rawurlencode((string) $identity['uuid']))]
                : [
                    $reference,
                    $this->department((string) ($identity['department_code'] ?? ''), $locale),
                    lang('Notifications.' . ($contact === ContactVerificationStatus::OTP_VERIFIED
                        ? 'contactOtpVerified' : 'contactManualReview'), [], $locale),
                    $this->url('admin/identites?status=pending'),
                ];
            $this->enqueueUser(
                $user,
                'identity.submitted.administrator',
                $adminTemplate,
                $args,
                'identity:' . $identityId . ':submission:admin:' . (int) $user['id'],
                'administrator',
                $identityId,
                $contact === ContactVerificationStatus::MANUAL_REVIEW ? 95 : 70
            );
        }

        foreach ($this->fieldUsers((string) ($identity['department_code'] ?? '')) as $user) {
            $locale = $this->locale((string) $user['locale']);
            $this->enqueueUser(
                $user,
                'identity.submitted.field',
                'newSubmissionField',
                [
                    $reference,
                    $this->department((string) ($identity['department_code'] ?? ''), $locale),
                    $this->url('admin/identites?status=pending&department=' . rawurlencode((string) ($identity['department_code'] ?? ''))),
                ],
                'identity:' . $identityId . ':submission:field:' . (int) $user['id'],
                'field',
                $identityId,
                75
            );
        }
    }

    public function identityDecision(int $identityId, string $status, ?string $reasonCode = null): void
    {
        $identity = $this->identity($identityId);
        $reference = (string) $identity['public_reference'];
        $trackingUrl = $this->url('swiv/' . rawurlencode($reference));
        $eventId = (int) ($this->db->table('identity_verification_events')
            ->selectMax('id')->where('tenant_id', $this->tenantContext->id())
            ->where('citizen_identity_id', $identityId)->get()->getFirstRow('array')['id'] ?? 0);

        if ($status === IdentityVerificationStateMachine::VERIFIED) {
            $this->enqueueCitizen($identity, 'identity.verified.citizen', 'decisionVerifiedCitizen',
                [$reference, $trackingUrl], 'identity:' . $identityId . ':decision:' . $eventId . ':verified', 95);
        } elseif ($status === IdentityVerificationStateMachine::REJECTED) {
            $locale = $this->locale((string) ($identity['preferred_locale'] ?? 'ht'));
            $reason = lang('Notifications.' . $this->reasonKey($reasonCode), [], $locale);
            $this->enqueueCitizen($identity, 'identity.rejected.citizen', 'decisionRejectedCitizen',
                [$reference, $reason, $trackingUrl], 'identity:' . $identityId . ':decision:' . $eventId . ':rejected', 95);
        } elseif ($status === IdentityVerificationStateMachine::PENDING) {
            $this->enqueueCitizen($identity, 'identity.pending.citizen', 'decisionPendingCitizen',
                [$reference, $trackingUrl], 'identity:' . $identityId . ':decision:' . $eventId . ':pending', 85);
        } else {
            throw new InvalidArgumentException('Unknown notification decision status.');
        }

        foreach ($this->fieldUsers((string) ($identity['department_code'] ?? '')) as $user) {
            $locale = $this->locale((string) $user['locale']);
            $department = $this->department((string) ($identity['department_code'] ?? ''), $locale);
            $dossierUrl = $this->url('admin/identites/' . rawurlencode((string) $identity['uuid']));
            $isPending = $status === IdentityVerificationStateMachine::PENDING;
            $arguments = $isPending
                ? [$reference, $department, $dossierUrl]
                : [
                    $reference,
                    $department,
                    lang('Notifications.' . ($status === IdentityVerificationStateMachine::VERIFIED
                        ? 'decisionStatusVerified' : 'decisionStatusRejected'), [], $locale),
                    $dossierUrl,
                ];
            $this->enqueueUser(
                $user,
                'identity.' . $status . '.field',
                $isPending ? 'fieldFollowUp' : 'decisionField',
                $arguments,
                'identity:' . $identityId . ':decision:' . $eventId . ':field:' . (int) $user['id'],
                'field',
                $identityId,
                $isPending ? 85 : 75
            );
        }
    }

    public function confirmationRequested(int $identityId, string $requestKey): array
    {
        $identity = $this->identity($identityId);
        $reference = (string) $identity['public_reference'];
        return $this->enqueueCitizen(
            $identity,
            'identity.confirmation.citizen',
            'confirmation',
            [$reference, $this->url('swiv/' . rawurlencode($reference))],
            'identity:' . $identityId . ':confirmation:' . $requestKey,
            95
        );
    }

    public function userCreated(int $userId): void
    {
        $user = $this->user($userId);
        $this->enqueueUser($user, 'admin.user.created', 'userCreated', [
            (string) $user['display_name'], (string) $user['tenant_name'], $this->url('admin/login'),
        ], 'user:' . $userId . ':created', 'administrator', null, 90);
    }

    public function userStatusChanged(int $userId, string $status, string $changeKey): void
    {
        $user = $this->user($userId);
        $locale = $this->locale((string) $user['locale']);
        $this->enqueueUser($user, 'admin.user.status_changed', 'userStatus', [
            (string) $user['display_name'], (string) $user['tenant_name'],
            lang('Notifications.' . ($status === 'active' ? 'statusActive' : 'statusInactive'), [], $locale),
        ], 'user:' . $userId . ':status:' . $changeKey, 'administrator', null, 95);
    }

    public function userRoleChanged(int $userId, string $roleName, string $change, string $changeKey): void
    {
        $templates = ['assigned' => 'roleAssigned', 'removed' => 'roleRemoved', 'updated' => 'roleUpdated'];
        if (! isset($templates[$change])) {
            throw new InvalidArgumentException('Unknown role notification change.');
        }
        $user = $this->user($userId);
        $this->enqueueUser($user, 'admin.role.' . $change, $templates[$change], [
            (string) $user['display_name'], (string) $user['tenant_name'], $roleName,
        ], 'user:' . $userId . ':role:' . $change . ':' . $changeKey, 'administrator', null, 95);
    }

    public function ownershipChanged(int $userId, bool $isOwner, string $changeKey): void
    {
        $user = $this->user($userId);
        $locale = $this->locale((string) $user['locale']);
        $this->enqueueUser($user, 'admin.ownership.changed', 'ownershipChanged', [
            (string) $user['display_name'], (string) $user['tenant_name'],
            lang('Notifications.' . ($isOwner ? 'ownerEnabled' : 'ownerDisabled'), [], $locale),
        ], 'user:' . $userId . ':ownership:' . $changeKey, 'administrator', null, 95);
    }

    public function passwordReset(int $userId, string $resetUrl, string $requestUuid): array
    {
        $user = $this->user($userId);
        return $this->queue->enqueue(
            eventKey: 'admin.password.reset_requested',
            templateKey: 'passwordReset',
            audience: 'administrator',
            locale: $this->locale((string) $user['locale']),
            destinations: [
                'email' => (string) $user['email'],
                'phone' => $this->userPhone($user),
            ],
            templateArguments: [(string) $user['display_name'], $resetUrl],
            idempotencySource: 'user:' . $userId . ':password-reset:' . $requestUuid,
            requestedChannel: $this->userChannel($user),
            recipientUserId: $userId,
            entityType: 'user',
            entityId: $userId,
            priority: 100,
            contentSensitive: true
        );
    }

    public function passwordChanged(int $userId, string $changeKey): void
    {
        $user = $this->user($userId);
        $this->enqueueUser($user, 'admin.password.changed', 'passwordChanged', [
            (string) $user['display_name'], (string) $user['tenant_name'],
        ], 'user:' . $userId . ':password-changed:' . $changeKey, 'administrator', null, 100);
    }

    public function fieldModeChanged(int $userId, bool $enabled, ?string $department, string $changeKey): void
    {
        $user = $this->user($userId);
        $locale = $this->locale((string) $user['locale']);
        $this->enqueueUser($user, 'admin.field_mode.changed', 'fieldMode', [
            (string) $user['display_name'],
            lang('Notifications.' . ($enabled ? 'fieldEnabled' : 'fieldDisabled'), [], $locale),
            $department === null
                ? lang('Notifications.allDepartments', [], $locale)
                : $this->department($department, $locale),
        ], 'user:' . $userId . ':field-mode:' . $changeKey, 'field', null, 90);
    }

    private function enqueueCitizen(
        array $identity,
        string $event,
        string $template,
        array $argumentsWithoutName,
        string $idempotency,
        int $priority
    ): array {
        $locale = $this->locale((string) ($identity['preferred_locale'] ?? 'ht'));
        $name = trim((string) ($identity['first_name'] ?? ''));
        $name = $name === '' ? ($locale === 'fr' ? 'citoyen(ne)' : 'sitwayen') : $name;
        $arguments = $template === 'confirmation'
            ? $argumentsWithoutName
            : array_merge([$name], $argumentsWithoutName);

        return $this->queue->enqueue(
            eventKey: $event,
            templateKey: $template,
            audience: 'citizen',
            locale: $locale,
            destinations: ['email' => $identity['email'], 'phone' => $identity['phone']],
            templateArguments: $arguments,
            idempotencySource: $idempotency,
            requestedChannel: (string) ($identity['preferred_notification_channel'] ?? 'auto'),
            citizenIdentityId: (int) $identity['id'],
            entityType: 'citizen_identity',
            entityId: (int) $identity['id'],
            priority: $priority
        );
    }

    private function enqueueUser(
        array $user,
        string $event,
        string $template,
        array $arguments,
        string $idempotency,
        string $audience,
        ?int $identityId,
        int $priority
    ): array {
        return $this->queue->enqueue(
            eventKey: $event,
            templateKey: $template,
            audience: $audience,
            locale: $this->locale((string) $user['locale']),
            destinations: [
                'email' => (string) $user['email'],
                'phone' => $this->userPhone($user),
            ],
            templateArguments: $arguments,
            idempotencySource: $idempotency,
            requestedChannel: $this->userChannel($user),
            recipientUserId: (int) $user['id'],
            citizenIdentityId: $identityId,
            entityType: $identityId === null ? 'user' : 'citizen_identity',
            entityId: $identityId ?? (int) $user['id'],
            priority: $priority
        );
    }

    private function identity(int $identityId): array
    {
        $row = $this->db->table('citizen_identities')
            ->where('tenant_id', $this->tenantContext->id())
            ->where('id', $identityId)->limit(1)->get()->getFirstRow('array');
        if ($row === null) {
            throw new InvalidArgumentException('Citizen identity was not found.');
        }
        $uuid = (string) $row['uuid'];
        $row['phone'] = $this->decrypt($row['phone_ciphertext'] ?? null, fn (string $v) => $this->identityCrypto->decryptPhone($v, $uuid));
        $row['email'] = $this->decrypt($row['email_ciphertext'] ?? null, fn (string $v) => $this->identityCrypto->decryptEmail($v, $uuid));
        $row['first_name'] = $this->decrypt($row['first_name_ciphertext'] ?? null, fn (string $v) => $this->identityCrypto->decryptFirstName($v, $uuid));
        return $row;
    }

    private function user(int $userId): array
    {
        $row = $this->db->table('tenant_users tu')
            ->select('u.id, u.email, u.display_name, u.locale, t.name AS tenant_name, '
                . 'tu.notification_phone_ciphertext, tu.preferred_notification_channel')
            ->join('users u', 'u.id = tu.user_id')
            ->join('tenants t', 't.id = tu.tenant_id')
            ->where('tu.tenant_id', $this->tenantContext->id())
            ->where('u.id', $userId)->limit(1)->get()->getFirstRow('array');
        if ($row === null) {
            throw new InvalidArgumentException('Administrative user was not found.');
        }
        return $row;
    }

    private function administrators(string $permission): array
    {
        return $this->db->table('tenant_users tu')
            ->distinct()->select('u.id, u.email, u.display_name, u.locale, '
                . 'tu.notification_phone_ciphertext, tu.preferred_notification_channel')
            ->join('users u', 'u.id = tu.user_id')
            ->join('user_roles ur', 'ur.tenant_id = tu.tenant_id AND ur.user_id = tu.user_id')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('tu.tenant_id', $this->tenantContext->id())
            ->where('tu.status', 'active')->where('u.status', 'active')->where('u.deleted_at', null)
            ->where('p.code', $permission)->get()->getResultArray();
    }

    private function fieldUsers(string $department): array
    {
        return $this->db->table('tenant_users tu')
            ->distinct()->select('u.id, u.email, u.display_name, u.locale, '
                . 'tu.notification_phone_ciphertext, tu.preferred_notification_channel')
            ->join('users u', 'u.id = tu.user_id')
            ->join('user_roles ur', 'ur.tenant_id = tu.tenant_id AND ur.user_id = tu.user_id')
            ->join('role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('tu.tenant_id', $this->tenantContext->id())
            ->where('tu.status', 'active')->where('u.status', 'active')->where('u.deleted_at', null)
            ->where('tu.field_mode_enabled', 1)->where('p.code', 'identity.view')
            ->groupStart()->where('tu.field_department_code', null)
            ->orWhere('tu.field_department_code', $department)->groupEnd()
            ->get()->getResultArray();
    }

    private function department(string $code, string $locale): string
    {
        return (new HaitiDepartmentCatalog())->options($locale)[$code]
            ?? lang('Notifications.notProvided', [], $locale);
    }

    private function reasonKey(?string $reason): string
    {
        return match ($reason) {
            'document_illisible' => 'rejectionDocumentUnreadable',
            'photo_floue' => 'rejectionBlurryPhoto',
            'carte_incomplete' => 'rejectionIncompleteCard',
            'portrait_non_conforme' => 'rejectionPortrait',
            'information_incoherente' => 'rejectionMismatch',
            default => 'rejectionOther',
        };
    }

    private function decrypt(mixed $payload, callable $decrypt): ?string
    {
        return is_string($payload) && $payload !== '' ? $decrypt($payload) : null;
    }

    private function userPhone(array $user): ?string
    {
        $payload = $user['notification_phone_ciphertext'] ?? null;

        return is_string($payload) && $payload !== ''
            ? (new TenantSecretCipher($this->tenantContext))->decrypt(
                'tenant_user.phone.' . (int) $user['id'],
                $payload
            )
            : null;
    }

    private function userChannel(array $user): string
    {
        $channel = (string) ($user['preferred_notification_channel'] ?? 'email');

        return in_array($channel, ['auto', 'whatsapp', 'sms', 'email'], true)
            ? $channel
            : 'email';
    }

    private function locale(string $locale): string
    {
        return $locale === 'fr' ? 'fr' : 'ht';
    }

    private function url(string $path): string
    {
        return rtrim((string) config('App')->baseURL, '/') . '/' . ltrim($path, '/');
    }
}
