<?php

namespace App\Controllers;

use App\Services\PublicIdentitySubmissionService;
use App\Services\PublicTenantResolver;
use App\Services\TenantContext;
use App\Services\VerificationDocumentWriteService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
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

            $documents = [
                VerificationDocumentWriteService::CIN_FRONT =>
                    $this->uploadedTemporaryPath('cin_front'),
                VerificationDocumentWriteService::CIN_BACK =>
                    $this->uploadedTemporaryPath('cin_back'),
                VerificationDocumentWriteService::PORTRAIT =>
                    $this->uploadedTemporaryPath('portrait'),
            ];

            $tenantContext = (new TenantContext())
                ->set((int) $tenant['id']);

            $result = (new PublicIdentitySubmissionService(
                $tenantContext
            ))->submit(
                $ninu,
                $phoneInput === '' ? null : $phoneInput,
                self::CONSENT_VERSION,
                $documents
            );

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
