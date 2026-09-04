<?php

namespace App\Services\Otp;

use App\Services\IdentityInputNormalizer;
use App\Services\TenantContext;
use CodeIgniter\Session\Session;
use InvalidArgumentException;
use RuntimeException;

final class PublicContactFallbackService
{
    public const ACCEPTANCE_TTL_SECONDS = 900;

    private const OFFER_TTL_SECONDS = 300;
    private const SESSION_KEY = 'public_contact_fallback';

    private Session $session;
    private IdentityInputNormalizer $normalizer;
    private OtpChallengeCrypto $crypto;

    public function __construct(
        private readonly TenantContext $tenantContext,
        ?Session $session = null,
        ?IdentityInputNormalizer $normalizer = null,
        ?OtpChallengeCrypto $crypto = null
    ) {
        $resolvedSession = $session ?? service('session');

        if (! $resolvedSession instanceof Session) {
            throw new RuntimeException(
                'Public contact fallback session service is unavailable.'
            );
        }

        $this->session = $resolvedSession;
        $this->normalizer = $normalizer ?? new IdentityInputNormalizer();
        $this->crypto = $crypto ?? new OtpChallengeCrypto($tenantContext);
    }

    public function offer(string $phone): void
    {
        $normalizedPhone = $this->requiredPhone($phone);

        $this->session->set(self::SESSION_KEY, [
            'tenant_id' => $this->tenantContext->id(),
            'phone_fingerprint' =>
                $this->crypto->phoneFingerprint($normalizedPhone),
            'offered_at' => time(),
            'accepted_at' => null,
            'valid_until' => null,
        ]);
    }

    public function accept(string $phone): void
    {
        $normalizedPhone = $this->requiredPhone($phone);
        $record = $this->record();

        if ($record === null) {
            throw new InvalidArgumentException(
                'Contact fallback is not available for this session.'
            );
        }

        $offeredAt = (int) ($record['offered_at'] ?? 0);

        if (
            (int) ($record['tenant_id'] ?? 0)
                !== $this->tenantContext->id()
            || $offeredAt <= 0
            || $offeredAt + self::OFFER_TTL_SECONDS < time()
            || ! $this->phoneMatches($record, $normalizedPhone)
        ) {
            $this->clear();
            throw new InvalidArgumentException(
                'Contact fallback is not available for this session.'
            );
        }

        $now = time();
        $record['accepted_at'] = $now;
        $record['valid_until'] = $now + self::ACCEPTANCE_TTL_SECONDS;

        $this->session->set(self::SESSION_KEY, $record);
    }

    public function hasAccepted(string $phone): bool
    {
        $record = $this->record();

        if (
            $record === null
            || (int) ($record['tenant_id'] ?? 0)
                !== $this->tenantContext->id()
            || (int) ($record['accepted_at'] ?? 0) <= 0
            || (int) ($record['valid_until'] ?? 0) < time()
        ) {
            return false;
        }

        try {
            $normalizedPhone = $this->requiredPhone($phone);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $this->phoneMatches($record, $normalizedPhone);
    }

    public function assertAccepted(string $phone): void
    {
        if (! $this->hasAccepted($phone)) {
            throw new InvalidArgumentException(
                'Manual contact verification is required.'
            );
        }
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    public function snapshot(): ?array
    {
        $record = $this->record();

        if ($record === null) {
            return null;
        }

        return [
            'tenant_id' => (int) ($record['tenant_id'] ?? 0),
            'phone_fingerprint' =>
                (string) ($record['phone_fingerprint'] ?? ''),
            'offered_at' => (int) ($record['offered_at'] ?? 0),
            'accepted_at' => isset($record['accepted_at'])
                ? (int) $record['accepted_at']
                : null,
            'valid_until' => isset($record['valid_until'])
                ? (int) $record['valid_until']
                : null,
        ];
    }

    private function record(): ?array
    {
        $value = $this->session->get(self::SESSION_KEY);
        return is_array($value) ? $value : null;
    }

    private function requiredPhone(string $phone): string
    {
        $normalized = $this->normalizer->normalizeHaitiPhone($phone);

        if ($normalized === null) {
            throw new InvalidArgumentException(
                'Contact fallback requires a phone number.'
            );
        }

        return $normalized;
    }

    private function phoneMatches(array $record, string $phone): bool
    {
        $expected = (string) ($record['phone_fingerprint'] ?? '');

        return preg_match('/^[0-9a-f]{64}$/D', $expected) === 1
            && hash_equals(
                $expected,
                $this->crypto->phoneFingerprint($phone)
            );
    }
}
