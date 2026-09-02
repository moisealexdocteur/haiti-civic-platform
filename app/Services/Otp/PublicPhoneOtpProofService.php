<?php

namespace App\Services\Otp;

use App\Services\IdentityInputNormalizer;
use App\Services\TenantContext;
use CodeIgniter\Session\Session;
use InvalidArgumentException;
use RuntimeException;

final class PublicPhoneOtpProofService
{
    public const PROOF_TTL_SECONDS = 900;

    private const SESSION_KEY = 'public_phone_otp_proof';

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
                'Public OTP proof session service is unavailable.'
            );
        }

        $this->session = $resolvedSession;
        $this->normalizer = $normalizer
            ?? new IdentityInputNormalizer();
        $this->crypto = $crypto
            ?? new OtpChallengeCrypto($tenantContext);
    }

    public function rememberIssued(
        string $challengeUuid,
        string $normalizedPhone
    ): void {
        $challengeUuid = $this->normalizeUuid($challengeUuid);
        $normalizedPhone = $this->requiredNormalizedPhone(
            $normalizedPhone
        );

        $this->session->set(self::SESSION_KEY, [
            'tenant_id' => $this->tenantContext->id(),
            'challenge_uuid' => $challengeUuid,
            'phone_fingerprint' =>
                $this->crypto->phoneFingerprint($normalizedPhone),
            'issued_at' => time(),
            'verified_at' => null,
            'valid_until' => null,
        ]);
    }

    public function markVerified(string $challengeUuid): void
    {
        $challengeUuid = $this->normalizeUuid($challengeUuid);
        $proof = $this->proof();

        if (
            $proof === null
            || (int) ($proof['tenant_id'] ?? 0)
                !== $this->tenantContext->id()
            || ! hash_equals(
                (string) ($proof['challenge_uuid'] ?? ''),
                $challengeUuid
            )
        ) {
            throw new RuntimeException(
                'OTP challenge is not bound to the current public session.'
            );
        }

        $issuedAt = (int) ($proof['issued_at'] ?? 0);

        if (
            $issuedAt <= 0
            || $issuedAt + OtpChallengeService::TTL_SECONDS < time()
        ) {
            $this->clear();
            throw new RuntimeException(
                'OTP public session challenge has expired.'
            );
        }

        $now = time();
        $proof['verified_at'] = $now;
        $proof['valid_until'] = $now + self::PROOF_TTL_SECONDS;

        $this->session->set(self::SESSION_KEY, $proof);
    }

    public function hasVerifiedPhone(string $phone): bool
    {
        $proof = $this->proof();

        if (
            $proof === null
            || (int) ($proof['tenant_id'] ?? 0)
                !== $this->tenantContext->id()
            || (int) ($proof['verified_at'] ?? 0) <= 0
            || (int) ($proof['valid_until'] ?? 0) < time()
        ) {
            return false;
        }

        try {
            $normalizedPhone = $this->normalizer
                ->normalizeHaitiPhone($phone);
        } catch (InvalidArgumentException) {
            return false;
        }

        if ($normalizedPhone === null) {
            return false;
        }

        $expected = (string) ($proof['phone_fingerprint'] ?? '');

        if (preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1) {
            return false;
        }

        return hash_equals(
            $expected,
            $this->crypto->phoneFingerprint($normalizedPhone)
        );
    }

    public function assertVerifiedPhone(string $phone): void
    {
        if (! $this->hasVerifiedPhone($phone)) {
            throw new InvalidArgumentException(
                'Phone OTP verification is required.'
            );
        }
    }

    public function consumeVerifiedPhone(string $phone): void
    {
        $this->assertVerifiedPhone($phone);
        $this->clear();
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    public function snapshot(): ?array
    {
        $proof = $this->proof();

        if ($proof === null) {
            return null;
        }

        return [
            'tenant_id' => (int) ($proof['tenant_id'] ?? 0),
            'challenge_uuid' =>
                (string) ($proof['challenge_uuid'] ?? ''),
            'phone_fingerprint' =>
                (string) ($proof['phone_fingerprint'] ?? ''),
            'issued_at' => (int) ($proof['issued_at'] ?? 0),
            'verified_at' => isset($proof['verified_at'])
                ? (int) $proof['verified_at']
                : null,
            'valid_until' => isset($proof['valid_until'])
                ? (int) $proof['valid_until']
                : null,
        ];
    }

    private function proof(): ?array
    {
        $value = $this->session->get(self::SESSION_KEY);
        return is_array($value) ? $value : null;
    }

    private function requiredNormalizedPhone(string $phone): string
    {
        $normalized = $this->normalizer->normalizeHaitiPhone($phone);

        if ($normalized === null || $normalized !== $phone) {
            throw new InvalidArgumentException(
                'Public OTP proof requires a normalized phone number.'
            );
        }

        return $normalized;
    }

    private function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-'
                . '[0-9a-f]{4}-[0-9a-f]{4}-'
                . '[0-9a-f]{12}$/D',
                $uuid
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'OTP challenge UUID is invalid.'
            );
        }

        return $uuid;
    }
}
