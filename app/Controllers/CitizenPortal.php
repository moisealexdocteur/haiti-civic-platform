<?php

namespace App\Controllers;

use App\Services\Otp\OtpChannelRouter;
use App\Services\Otp\OtpTransportFactory;
use App\Services\Otp\PublicPhoneOtpFlowService;
use App\Services\Otp\PublicPhoneOtpProofService;
use App\Services\PublicIdentitySubmissionService;
use App\Services\PublicTenantResolver;
use App\Services\TenantContext;
use App\Services\VerificationDocumentWriteService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CitizenPortal extends BaseController
{
    private const CONSENT_VERSION = 'public-identity-v1';

    public function home(): string
    {
        $locale = $this->resolveLocale();
        $this->request->setLocale($locale);

        return view(
            'citizen_portal/home',
            [
                'locale' => $locale,
                'organizationError' =>
                    $this->request->getGet('erreur')
                    === 'organisation',
            ]
        );
    }

    public function locate(): RedirectResponse
    {
        $locale = $this->resolveLocale();
        $slug = trim(
            (string) $this->request
                ->getGet('organisation')
        );

        if ($slug === '') {
            return redirect()->to(
                '/?lang=' . rawurlencode($locale)
                . '&erreur=organisation#access'
            );
        }

        return redirect()->to(
            '/inscription/'
            . rawurlencode($slug)
            . '?lang='
            . rawurlencode($locale)
        );
    }

    public function register(string $tenantSlug): string
    {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);
        helper('form');

        return $this->registrationView(
            $tenant,
            $locale,
            null
        );
    }

    public function requestOtp(string $tenantSlug): ResponseInterface
    {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);

        try {
            $phone = trim((string) $this->request->getPost('phone'));
            $email = trim((string) $this->request->getPost('email'));
            $tenantContext = $this->tenantContext($tenant);
            $flow = new PublicPhoneOtpFlowService(
                $tenantContext,
                OtpTransportFactory::fromEnvironment()
            );

            $result = $flow->request(
                $phone,
                $this->otpRequestFingerprint((int) $tenant['id']),
                $email === '' ? null : $email
            );

            $messageKey = match ($result['delivered_channel']) {
                'sms' => 'CitizenPortal.otpSentSms',
                'email' => 'CitizenPortal.otpSentEmail',
                default => 'CitizenPortal.otpSentWhatsApp',
            };

            return $this->otpJson([
                'ok' => true,
                'challenge_uuid' => (string) $result['challenge_uuid'],
                'delivered_channel' => (string) $result['delivered_channel'],
                'ttl_seconds' => (int) $result['ttl_seconds'],
                'message' => lang($messageKey),
            ]);
        } catch (InvalidArgumentException $exception) {
            $messageKey = match ($exception->getMessage()) {
                'OTP email recipient is required.' =>
                    'CitizenPortal.otpEmailRequired',
                'OTP email recipient is invalid.' =>
                    'CitizenPortal.otpEmailInvalid',
                default => 'CitizenPortal.otpPhoneInvalid',
            };

            return $this->otpJson([
                'ok' => false,
                'message' => lang($messageKey),
            ], 422);
        } catch (RuntimeException $exception) {
            $rateLimited = in_array(
                $exception->getMessage(),
                [
                    'OTP issue rate limit exceeded.',
                    'OTP requester rate limit exceeded.',
                    'OTP resend cooldown is active.',
                ],
                true
            );

            return $this->otpJson([
                'ok' => false,
                'message' => lang(
                    $rateLimited
                        ? 'CitizenPortal.otpRateLimited'
                        : 'CitizenPortal.otpUnavailable'
                ),
            ], $rateLimited ? 429 : 503);
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Public OTP request failed: {type}',
                ['type' => $exception::class]
            );

            return $this->otpJson([
                'ok' => false,
                'message' => lang('CitizenPortal.otpUnavailable'),
            ], 503);
        }
    }

    public function verifyOtp(string $tenantSlug): ResponseInterface
    {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);

        try {
            $challengeUuid = trim(
                (string) $this->request->getPost('challenge_uuid')
            );
            $code = trim((string) $this->request->getPost('code'));

            $flow = new PublicPhoneOtpFlowService(
                $this->tenantContext($tenant),
                new OtpChannelRouter([])
            );

            $result = $flow->verify($challengeUuid, $code);

            if (! $result['accepted']) {
                return $this->otpJson([
                    'ok' => false,
                    'message' => lang('CitizenPortal.otpInvalid'),
                ], 422);
            }

            return $this->otpJson([
                'ok' => true,
                'message' => lang('CitizenPortal.otpVerified'),
            ]);
        } catch (InvalidArgumentException | RuntimeException) {
            return $this->otpJson([
                'ok' => false,
                'message' => lang('CitizenPortal.otpInvalid'),
            ], 422);
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Public OTP verification failed: {type}',
                ['type' => $exception::class]
            );

            return $this->otpJson([
                'ok' => false,
                'message' => lang('CitizenPortal.otpUnavailable'),
            ], 503);
        }
    }

    public function submit(string $tenantSlug): string
    {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);
        helper('form');

        try {
            if ((string) $this->request->getPost('consent') !== '1') {
                throw new InvalidArgumentException(
                    'consent_required'
                );
            }

            $ninu = (string) $this->request->getPost('ninu');
            $phoneInput = trim(
                (string) $this->request->getPost('phone')
            );
            $emailInput = trim(
                (string) $this->request->getPost('email')
            );

            if ($phoneInput === '') {
                throw new InvalidArgumentException(
                    'Contact OTP verification is required.'
                );
            }

            $tenantContext = $this->tenantContext($tenant);
            $contactProof = new PublicPhoneOtpProofService($tenantContext);
            $contactProof->assertVerifiedContact(
                $phoneInput,
                $emailInput === '' ? null : $emailInput
            );

            $documents = [
                VerificationDocumentWriteService::CIN_FRONT =>
                    $this->uploadedTemporaryPath('cin_front'),
                VerificationDocumentWriteService::CIN_BACK =>
                    $this->uploadedTemporaryPath('cin_back'),
                VerificationDocumentWriteService::PORTRAIT =>
                    $this->uploadedTemporaryPath('portrait'),
            ];

            $result = (new PublicIdentitySubmissionService(
                $tenantContext
            ))->submit(
                $ninu,
                $phoneInput,
                self::CONSENT_VERSION,
                $documents
            );

            $contactProof->clear();

            return view(
                'citizen_portal/confirmation',
                [
                    'locale' => $locale,
                    'tenant' => $tenant,
                    'reference' => (string) $result['uuid'],
                    'status' => (string) $result[
                        'verification_status'
                    ],
                ]
            );
        } catch (InvalidArgumentException $exception) {
            $this->response->setStatusCode(422);

            return $this->registrationView(
                $tenant,
                $locale,
                $this->publicErrorMessage($exception)
            );
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Public identity submission failed: {type}',
                ['type' => $exception::class]
            );

            $this->response->setStatusCode(500);

            return $this->registrationView(
                $tenant,
                $locale,
                lang('CitizenPortal.submissionError')
            );
        }
    }

    private function registrationView(
        array $tenant,
        string $locale,
        ?string $errorMessage
    ): string {
        return view(
            'citizen_portal/register',
            [
                'locale' => $locale,
                'tenant' => $tenant,
                'errorMessage' => $errorMessage,
            ]
        );
    }

    private function resolveTenant(string $tenantSlug): array
    {
        $tenant = (new PublicTenantResolver())
            ->bySlug(rawurldecode($tenantSlug));

        if ($tenant === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $tenant;
    }

    private function tenantContext(array $tenant): TenantContext
    {
        return (new TenantContext())->set((int) $tenant['id']);
    }

    private function otpRequestFingerprint(int $tenantId): string
    {
        $secret = getenv('APP_KEY');

        if (
            ! is_string($secret)
            || strlen($secret) < 32
            || str_contains($secret, 'CHANGE_ME')
        ) {
            throw new RuntimeException(
                'OTP request fingerprint secret is unavailable.'
            );
        }

        return hash_hmac(
            'sha256',
            "v1\0otp-request\0tenant:{$tenantId}\0"
            . $this->request->getIPAddress(),
            $secret
        );
    }

    private function otpJson(
        array $payload,
        int $status = 200
    ): ResponseInterface {
        helper(['form', 'security']);

        $payload['csrf'] = [
            'name' => csrf_token(),
            'hash' => csrf_hash(),
        ];

        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setJSON($payload);
    }

    private function uploadedTemporaryPath(string $field): string
    {
        $file = $this->request->getFile($field);

        if (
            $file === null
            || ! $file->isValid()
            || $file->hasMoved()
        ) {
            throw new InvalidArgumentException(
                'document_invalid'
            );
        }

        $temporaryPath = $file->getTempName();

        if ($temporaryPath === '' || ! is_file($temporaryPath)) {
            throw new InvalidArgumentException(
                'document_invalid'
            );
        }

        return $temporaryPath;
    }

    private function publicErrorMessage(
        InvalidArgumentException $exception
    ): string {
        return match ($exception->getMessage()) {
            'consent_required' =>
                lang('CitizenPortal.consentRequired'),
            'document_invalid' =>
                lang('CitizenPortal.documentInvalid'),
            'Phone OTP verification is required.',
            'Contact OTP verification is required.' =>
                lang('CitizenPortal.contactVerificationRequired'),
            'Citizen identity already exists in the current tenant.' =>
                lang('CitizenPortal.duplicateIdentity'),
            default =>
                lang('CitizenPortal.submissionInvalid'),
        };
    }

    private function resolveLocale(
        ?string $tenantDefault = null
    ): string {
        $requested = strtolower(
            trim(
                (string) $this->request
                    ->getGet('lang')
            )
        );

        if (in_array($requested, ['fr', 'ht'], true)) {
            return $requested;
        }

        $tenantDefault = strtolower(
            trim((string) $tenantDefault)
        );

        if (in_array($tenantDefault, ['fr', 'ht'], true)) {
            return $tenantDefault;
        }

        return 'fr';
    }
}
