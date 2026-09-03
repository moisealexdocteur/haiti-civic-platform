<?php

namespace App\Services;

use App\Services\Otp\OtpChallengeDeliveryService;
use App\Services\Otp\OtpChallengeService;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpDeliveryRequest;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Session\Session;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicIdentityTrackingService
{
    private const SESSION_KEY = 'public_identity_tracking';
    private const ACCESS_TTL_SECONDS = 900;

    private BaseConnection $db;
    private Session $session;
    private PublicReferenceGenerator $references;

    public function __construct(
        ?BaseConnection $db = null,
        ?Session $session = null,
        ?PublicReferenceGenerator $references = null
    ) {
        $this->db = $db ?? Database::connect();
        $resolvedSession = $session ?? service('session');

        if (! $resolvedSession instanceof Session) {
            throw new RuntimeException('Public tracking session is unavailable.');
        }

        $this->session = $resolvedSession;
        $this->references = $references ?? new PublicReferenceGenerator();
    }

    public function dossier(string $reference): ?array
    {
        $reference = $this->references->normalize($reference);

        return $this->db->table('citizen_identities ci')
            ->select([
                'ci.id',
                'ci.uuid',
                'ci.public_reference',
                'ci.phone_ciphertext',
                'ci.verification_status',
                'ci.created_at',
                'ci.updated_at',
                't.id AS tenant_id',
                't.uuid AS tenant_uuid',
                't.slug AS tenant_slug',
                't.name AS tenant_name',
                't.default_locale',
            ])
            ->join('tenants t', 't.id = ci.tenant_id', 'inner')
            ->where('ci.public_reference', $reference)
            ->where('t.status', 'active')
            ->where('t.deleted_at', null)
            ->limit(1)
            ->get()
            ->getFirstRow('array');
    }

    public function requestCode(
        string $reference,
        ?string $requestFingerprint = null
    ): array {
        $dossier = $this->requiredDossier($reference);
        $phoneCiphertext = (string) ($dossier['phone_ciphertext'] ?? '');

        if ($phoneCiphertext === '') {
            throw new RuntimeException('Tracking contact is unavailable.');
        }

        $context = (new TenantContext())->set((int) $dossier['tenant_id']);
        $phone = (new IdentityCryptoService($context))->decryptPhone(
            $phoneCiphertext,
            (string) $dossier['uuid']
        );
        $router = (new TenantCommunicationSettingsService($context))->router();
        $preference = array_values(array_filter([
            $router->hasTransport(OtpChannel::WHATSAPP) ? OtpChannel::WHATSAPP : null,
            $router->hasTransport(OtpChannel::SMS) ? OtpChannel::SMS : null,
        ]));

        if ($preference === []) {
            throw new RuntimeException('Tracking delivery is unavailable.');
        }

        $challenges = new OtpChallengeService($context, $this->db);
        $issued = $challenges->issue(
            $phone,
            OtpChallengeService::PURPOSE_CITIZEN_TRACKING,
            $preference[0],
            $requestFingerprint
        );
        $challengeUuid = (string) $issued['uuid'];
        $code = (string) $issued['code'];
        $deliveries = new OtpChallengeDeliveryService($context, $this->db);

        try {
            $delivery = $router->deliver(
                new OtpDeliveryRequest(
                    (string) $issued['normalized_phone'],
                    $code,
                    (int) $issued['ttl_seconds']
                ),
                $preference
            );

            if (! $delivery->accepted) {
                $deliveries->invalidateUndelivered(
                    $challengeUuid,
                    $delivery->failureCode ?? 'delivery_rejected'
                );
                throw new RuntimeException('Tracking delivery is unavailable.');
            }

            $deliveries->markDelivered($challengeUuid, $delivery);
            $this->session->set(self::SESSION_KEY, [
                'tenant_id' => (int) $dossier['tenant_id'],
                'identity_id' => (int) $dossier['id'],
                'reference' => (string) $dossier['public_reference'],
                'challenge_uuid' => $challengeUuid,
                'issued_at' => time(),
                'verified_until' => null,
            ]);

            return [
                'challenge_uuid' => $challengeUuid,
                'delivered_channel' => $delivery->channel->value,
                'ttl_seconds' => (int) $issued['ttl_seconds'],
            ];
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                try {
                    $deliveries->invalidateUndelivered($challengeUuid, 'transport_unavailable');
                } catch (Throwable) {
                }
            }

            throw $exception;
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($code);
                sodium_memzero($phone);
            }
        }
    }

    public function verifyCode(
        string $reference,
        string $challengeUuid,
        string $code
    ): bool {
        $dossier = $this->requiredDossier($reference);
        $proof = $this->session->get(self::SESSION_KEY);

        if (
            ! is_array($proof)
            || (int) ($proof['tenant_id'] ?? 0) !== (int) $dossier['tenant_id']
            || (int) ($proof['identity_id'] ?? 0) !== (int) $dossier['id']
            || ! hash_equals((string) ($proof['reference'] ?? ''), (string) $dossier['public_reference'])
            || ! hash_equals((string) ($proof['challenge_uuid'] ?? ''), trim($challengeUuid))
            || (int) ($proof['issued_at'] ?? 0) + OtpChallengeService::TTL_SECONDS < time()
        ) {
            throw new RuntimeException('Tracking challenge is not bound to this session.');
        }

        $context = (new TenantContext())->set((int) $dossier['tenant_id']);
        $result = (new OtpChallengeService($context, $this->db))
            ->verify($challengeUuid, $code);

        if (! $result['accepted']) {
            return false;
        }

        $proof['verified_until'] = time() + self::ACCESS_TTL_SECONDS;
        $this->session->set(self::SESSION_KEY, $proof);
        return true;
    }

    public function visibleStatus(string $reference): ?array
    {
        $dossier = $this->requiredDossier($reference);
        $proof = $this->session->get(self::SESSION_KEY);

        if (
            ! is_array($proof)
            || (int) ($proof['tenant_id'] ?? 0) !== (int) $dossier['tenant_id']
            || (int) ($proof['identity_id'] ?? 0) !== (int) $dossier['id']
            || ! hash_equals((string) ($proof['reference'] ?? ''), (string) $dossier['public_reference'])
            || (int) ($proof['verified_until'] ?? 0) < time()
        ) {
            return null;
        }

        return [
            'reference' => (string) $dossier['public_reference'],
            'status' => (string) $dossier['verification_status'],
            'created_at' => (string) $dossier['created_at'],
            'updated_at' => (string) $dossier['updated_at'],
        ];
    }

    private function requiredDossier(string $reference): array
    {
        $dossier = $this->dossier($reference);

        if ($dossier === null) {
            throw new InvalidArgumentException('Public reference was not found.');
        }

        return $dossier;
    }
}
