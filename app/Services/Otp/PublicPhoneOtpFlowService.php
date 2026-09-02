<?php

namespace App\Services\Otp;

use App\Services\TenantContext;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PublicPhoneOtpFlowService
{
    private OtpChallengeService $challenges;
    private OtpChallengeDeliveryService $deliveries;
    private PublicPhoneOtpProofService $proofs;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OtpChannelRouter $router,
        ?OtpChallengeService $challenges = null,
        ?OtpChallengeDeliveryService $deliveries = null,
        ?PublicPhoneOtpProofService $proofs = null
    ) {
        $this->challenges = $challenges
            ?? new OtpChallengeService($tenantContext);
        $this->deliveries = $deliveries
            ?? new OtpChallengeDeliveryService($tenantContext);
        $this->proofs = $proofs
            ?? new PublicPhoneOtpProofService($tenantContext);
    }

    /**
     * @return array{
     *   challenge_uuid:string,
     *   delivered_channel:string,
     *   expires_at:string,
     *   ttl_seconds:int
     * }
     */
    public function request(
        string $phone,
        ?string $requestFingerprint = null
    ): array {
        $issued = $this->challenges->issue(
            $phone,
            OtpChallengeService::PURPOSE_CITIZEN_PHONE,
            OtpChannel::WHATSAPP,
            $requestFingerprint
        );

        $challengeUuid = (string) $issued['uuid'];
        $normalizedPhone = (string) $issued['normalized_phone'];
        $code = (string) $issued['code'];

        try {
            $delivery = $this->router->deliver(
                new OtpDeliveryRequest(
                    $normalizedPhone,
                    $code,
                    (int) $issued['ttl_seconds']
                )
            );

            if (! $delivery->accepted) {
                $this->deliveries->invalidateUndelivered(
                    $challengeUuid,
                    $delivery->failureCode ?? 'delivery_rejected'
                );

                throw new RuntimeException(
                    'OTP delivery is temporarily unavailable.'
                );
            }

            $this->deliveries->markDelivered(
                $challengeUuid,
                $delivery
            );

            $this->proofs->rememberIssued(
                $challengeUuid,
                $normalizedPhone
            );

            return [
                'challenge_uuid' => $challengeUuid,
                'delivered_channel' => $delivery->channel->value,
                'expires_at' => (string) $issued['expires_at'],
                'ttl_seconds' => (int) $issued['ttl_seconds'],
            ];
        } catch (Throwable $exception) {
            if (
                ! $exception instanceof RuntimeException
                || $exception->getMessage()
                    !== 'OTP delivery is temporarily unavailable.'
            ) {
                try {
                    $this->deliveries->invalidateUndelivered(
                        $challengeUuid,
                        'transport_unavailable'
                    );
                } catch (Throwable) {
                    // Ne jamais masquer l'exception de transport initiale.
                }
            }

            if (
                $exception instanceof InvalidArgumentException
                || $exception instanceof RuntimeException
            ) {
                throw $exception;
            }

            throw new RuntimeException(
                'OTP delivery is temporarily unavailable.',
                0,
                $exception
            );
        } finally {
            $this->forget($code);
        }
    }

    /**
     * @return array{accepted:bool,reason:string,attempts_used:int}
     */
    public function verify(
        string $challengeUuid,
        string $code
    ): array {
        $result = $this->challenges->verify(
            $challengeUuid,
            $code
        );

        if ($result['accepted']) {
            $this->proofs->markVerified($challengeUuid);
        }

        return $result;
    }

    public function proofService(): PublicPhoneOtpProofService
    {
        return $this->proofs;
    }

    private function forget(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
            return;
        }

        $value = '';
    }
}
