<?php

namespace App\Controllers;

use App\Controllers\Concerns\PublicPage;
use App\Services\ContactVerificationStatus;
use App\Services\HaitiDepartmentCatalog;
use App\Services\Otp\OtpChannelRouter;
use App\Services\Otp\OtpChannel;
use App\Services\Otp\OtpTransportFactory;
use App\Services\Otp\PublicContactFallbackService;
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
    use PublicPage;

    private const CONSENT_VERSION = 'public-identity-v1';

    public function home(): string
    {
        $locale = $this->resolveLocale();
        $this->request->setLocale($locale);
        $this->rememberLocale($locale);

        return view(
            'citizen_portal/home',
            $this->pageData(
                $locale,
                lang('CitizenPortal.brand'),
                ['fr' => '/?lang=fr', 'ht' => '/?lang=ht'],
                [
                    'organizationError' =>
                        $this->request->getGet('erreur')
                        === 'organisation',
                ]
            )
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
        $this->rememberLocale($locale);
        helper('form');

        return $this->registrationView(
            $tenant,
            $locale,
            null
        );
    }

    public function confirmation(string $tenantSlug): string
    {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);

        $reference = (string) session()->getFlashdata('civic_reference');

        if ($reference === '') {
            return $this->registrationView($tenant, $locale, null);
        }

        return view(
            'citizen_portal/confirmation',
            $this->pageData(
                $locale,
                lang('CitizenPortal.confirmationTitle'),
                $this->tenantLangUrls($tenant),
                [
                    'tenant' => $tenant,
                    'reference' => $reference,
                    'trackingUrl' => site_url(
                        'swiv/' . rawurlencode($reference)
                    ) . '?lang=' . rawurlencode($locale),
                    'brandName' => (string) $tenant['name'],
                    'brandInitials' => $this->initials(
                        (string) $tenant['name']
                    ),
                ]
            )
        );
    }

    public function requestOtp(string $tenantSlug): ResponseInterface
    {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);

        $phone = '';
        $tenantContext = null;

        try {
            $phone = trim((string) $this->request->getPost('phone'));
            $email = trim((string) $this->request->getPost('email'));
            $channel = trim((string) $this->request->getPost('channel'));
            $tenantContext = $this->tenantContext($tenant);
            (new PublicContactFallbackService($tenantContext))->clear();
            $flow = new PublicPhoneOtpFlowService(
                $tenantContext,
                OtpTransportFactory::forTenant($tenantContext)
            );

            $result = $flow->request(
                $phone,
                $this->otpRequestFingerprint((int) $tenant['id']),
                $email === '' ? null : $email,
                $channel === '' ? null : $channel,
                $locale
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
            $deliveryUnavailable = in_array(
                $exception->getMessage(),
                [
                    'Requested OTP channel is not configured.',
                    'OTP delivery is temporarily unavailable.',
                    'No configured OTP transport matches the requested channels.',
                ],
                true
            );

            $fallbackAvailable = false;

            if (
                $deliveryUnavailable
                && $tenantContext instanceof TenantContext
                && $phone !== ''
            ) {
                try {
                    (new PublicContactFallbackService($tenantContext))
                        ->offer($phone);
                    $fallbackAvailable = true;
                } catch (Throwable) {
                    $fallbackAvailable = false;
                }
            }

            return $this->otpJson([
                'ok' => false,
                'fallback_available' => $fallbackAvailable,
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

    public function continueWithoutOtp(
        string $tenantSlug
    ): ResponseInterface {
        $tenant = $this->resolveTenant($tenantSlug);
        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);

        try {
            $phone = trim((string) $this->request->getPost('phone'));
            $fallback = new PublicContactFallbackService(
                $this->tenantContext($tenant)
            );
            $fallback->accept($phone);

            return $this->otpJson([
                'ok' => true,
                'message' => lang('CitizenPortal.manualAccepted'),
            ]);
        } catch (InvalidArgumentException | RuntimeException) {
            return $this->otpJson([
                'ok' => false,
                'message' => lang('CitizenPortal.manualUnavailable'),
            ], 422);
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Public OTP fallback failed: {type}',
                ['type' => $exception::class]
            );

            return $this->otpJson([
                'ok' => false,
                'message' => lang('CitizenPortal.manualUnavailable'),
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

            (new PublicContactFallbackService(
                $this->tenantContext($tenant)
            ))->clear();

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

    public function submit(string $tenantSlug): string|ResponseInterface
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
            $firstName = trim(
                (string) $this->request->getPost('first_name')
            );
            $lastName = trim(
                (string) $this->request->getPost('last_name')
            );
            $departmentCode = trim(
                (string) $this->request->getPost('department_code')
            );

            if ($departmentCode === '') {
                throw new InvalidArgumentException(
                    'Department is required.'
                );
            }

            if ($phoneInput === '') {
                throw new InvalidArgumentException(
                    'Contact OTP verification is required.'
                );
            }

            $tenantContext = $this->tenantContext($tenant);
            $contactProof = new PublicPhoneOtpProofService($tenantContext);
            $contactFallback = new PublicContactFallbackService(
                $tenantContext
            );

            if ($contactProof->hasVerifiedContact(
                $phoneInput,
                $emailInput === '' ? null : $emailInput
            )) {
                $contactStatus = ContactVerificationStatus::OTP_VERIFIED;
                $preferredChannel = (string) ($contactProof->snapshot()['delivered_channel'] ?? 'auto');
            } elseif ($contactFallback->hasAccepted($phoneInput)) {
                $contactStatus = ContactVerificationStatus::MANUAL_REVIEW;
                $preferredChannel = 'auto';
            } else {
                throw new InvalidArgumentException(
                    'Contact verification is required.'
                );
            }

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
                $documents,
                contactVerificationStatus: $contactStatus,
                departmentCode: $departmentCode,
                email: $emailInput === '' ? null : $emailInput,
                firstName: $firstName,
                lastName: $lastName,
                preferredLocale: $locale,
                preferredNotificationChannel: $preferredChannel
            );

            $contactProof->clear();
            $contactFallback->clear();

            // POST-Redirect-GET : un rafraîchissement ne rejoue pas l'envoi.
            session()->setFlashdata(
                'civic_reference',
                (string) $result['public_reference']
            );

            return $this->submissionOutcome(
                $tenant,
                $locale,
                null
            );
        } catch (InvalidArgumentException $exception) {
            return $this->submissionOutcome(
                $tenant,
                $locale,
                $this->publicErrorMessage($exception),
                422
            );
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Public identity submission failed: {type}',
                ['type' => $exception::class]
            );

            return $this->submissionOutcome(
                $tenant,
                $locale,
                lang('CitizenPortal.submissionError'),
                500
            );
        }
    }

    /**
     * Le parcours est piloté en JavaScript : en cas d'erreur, la réponse
     * reste du JSON pour que la page, et donc les photos déjà prises,
     * ne soient jamais détruites par un nouveau rendu.
     */
    private function submissionOutcome(
        array $tenant,
        string $locale,
        ?string $errorMessage,
        int $status = 200
    ): string|ResponseInterface {
        $target = '/inscription/'
            . rawurlencode((string) $tenant['slug'])
            . '/konfimasyon?lang='
            . rawurlencode($locale);

        if ($this->request->isAJAX()) {
            helper(['form', 'security']);

            return $this->response
                ->setStatusCode($errorMessage === null ? 200 : $status)
                ->setHeader('Cache-Control', 'no-store, private, max-age=0')
                ->setJSON([
                    'ok' => $errorMessage === null,
                    'message' => $errorMessage,
                    'redirect' => $errorMessage === null ? $target : null,
                    'csrf' => [
                        'name' => csrf_token(),
                        'hash' => csrf_hash(),
                    ],
                ]);
        }

        if ($errorMessage === null) {
            return redirect()->to($target);
        }

        $this->response->setStatusCode($status);

        return $this->registrationView($tenant, $locale, $errorMessage);
    }

    private function registrationView(
        array $tenant,
        string $locale,
        ?string $errorMessage
    ): string {
        helper(['form', 'security']);

        $channels = [
            'whatsapp' => false,
            'sms' => false,
            'email' => false,
        ];

        try {
            $router = OtpTransportFactory::forTenant(
                $this->tenantContext($tenant)
            );
            $channels = [
                'whatsapp' => $router->hasTransport(
                    OtpChannel::WHATSAPP
                ),
                'sms' => $router->hasTransport(
                    OtpChannel::SMS
                ),
                'email' => $router->hasTransport(
                    OtpChannel::EMAIL
                ),
            ];
        } catch (Throwable) {
            // Le parcours proposera le contrôle manuel si aucun canal ne répond.
        }

        return view(
            'citizen_portal/register',
            $this->pageData(
                $locale,
                (string) $tenant['name'],
                $this->tenantLangUrls($tenant),
                [
                    'tenant' => $tenant,
                    'errorMessage' => $errorMessage,
                    'brandName' => (string) $tenant['name'],
                    'brandInitials' => $this->initials(
                        (string) $tenant['name']
                    ),
                    'departments' => (new HaitiDepartmentCatalog())
                        ->options($locale),
                    'strings' => $this->wizardStrings(),
                    'channels' => $channels,
                ]
            )
        );
    }

    /**
     * @return array<string, string>
     */
    private function tenantLangUrls(array $tenant): array
    {
        $base = '/inscription/' . rawurlencode((string) $tenant['slug']);

        return [
            'fr' => $base . '?lang=fr',
            'ht' => $base . '?lang=ht',
        ];
    }

    /**
     * Toutes les chaînes affichées par le JavaScript. Rien n'est écrit
     * en dur dans le script : la page kreyòl n'affiche plus « Erreur. ».
     *
     * @return array<string, string>
     */
    private function wizardStrings(): array
    {
        $keys = [
            'stepAnnounce', 'ninuRequired', 'firstNameRequired',
            'lastNameRequired', 'phoneRequired', 'emailRequired',
            'emailInvalid', 'sendingCode',
            'phoneSend', 'codeLead', 'channelWhatsApp', 'channelSms',
            'channelEmail', 'codeExpires', 'codeExpired', 'codeResend',
            'codeResendIn', 'codeIncomplete', 'networkError', 'fileNotImage',
            'fileTooLarge', 'reviewTitle', 'photosCount', 'consentRequired',
            'fileTooSmall', 'fileUnreadable', 'piecesMissing',
            'scanNinuReading', 'scanNinuSuccess', 'scanNinuNotFound',
            'scanNinuUnsupported', 'abandonConfirm',
            'scanIdentitySuccess',
            'verifiedTitle',
            'departmentRequired',
            'manualTitle', 'manualLead', 'manualAction',
            'manualSending', 'manualAccepted', 'manualUnavailable',
            'contactVerificationRequired',
        ];

        $strings = ['csrfName' => csrf_token()];

        foreach ($keys as $key) {
            $strings[$key] = lang('CitizenPortal.' . $key);
        }

        $strings['title_cin_front'] = lang('CitizenPortal.frontTitle');
        $strings['title_cin_back'] = lang('CitizenPortal.backTitle');
        $strings['title_portrait'] = lang('CitizenPortal.portraitTitle');

        return $strings;
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
            'Contact OTP verification is required.',
            'Contact verification is required.' =>
                lang('CitizenPortal.contactVerificationRequired'),
            'Department is required.',
            'Unknown Haiti department code.' =>
                lang('CitizenPortal.departmentRequired'),
            'Person name cannot be empty.',
            'Person name contains unsupported characters.' =>
                lang('CitizenPortal.identityNameInvalid'),
            'Email address is invalid.' =>
                lang('CitizenPortal.emailInvalid'),
            'Citizen identity already exists in the current tenant.' =>
                lang('CitizenPortal.duplicateIdentity'),
            default =>
                lang('CitizenPortal.submissionInvalid'),
        };
    }
}
