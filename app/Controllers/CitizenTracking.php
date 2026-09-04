<?php

namespace App\Controllers;

use App\Controllers\Concerns\PublicPage;
use App\Services\PublicIdentityTrackingService;
use App\Services\PublicReferenceGenerator;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CitizenTracking extends BaseController
{
    use PublicPage;

    public function index(?string $reference = null): string|RedirectResponse
    {
        $locale = $this->resolveLocale();
        $this->request->setLocale($locale);
        $this->rememberLocale($locale);

        if ($reference === null || trim($reference) === '') {
            return view('citizen_portal/tracking_lookup', $this->pageData(
                $locale,
                lang('CitizenPortal.trackingTitle'),
                ['fr' => '/swiv?lang=fr', 'ht' => '/swiv?lang=ht']
            ));
        }

        try {
            $reference = (new PublicReferenceGenerator())->normalize($reference);
            $service = new PublicIdentityTrackingService();
            $dossier = $service->dossier($reference);

            if ($dossier === null) {
                throw new InvalidArgumentException('Not found.');
            }

            $locale = $this->resolveLocale((string) $dossier['default_locale']);
            $this->request->setLocale($locale);
            $status = $service->visibleStatus($reference);
            $path = '/swiv/' . rawurlencode($reference);

            $this->response->setHeader('Cache-Control', 'no-store, private, max-age=0');

            return view('citizen_portal/tracking', $this->pageData(
                $locale,
                lang('CitizenPortal.trackingTitle'),
                [
                    'fr' => $path . '?lang=fr',
                    'ht' => $path . '?lang=ht',
                ],
                [
                    'reference' => $reference,
                    'status' => $status,
                    'brandName' => (string) $dossier['tenant_name'],
                    'strings' => [
                        'sending' => lang('CitizenPortal.trackingSending'),
                        'sentWhatsapp' => lang('CitizenPortal.trackingSentWhatsapp'),
                        'sentSms' => lang('CitizenPortal.trackingSentSms'),
                        'invalid' => lang('CitizenPortal.trackingCodeInvalid'),
                        'unavailable' => lang('CitizenPortal.trackingUnavailable'),
                    ],
                ]
            ));
        } catch (InvalidArgumentException) {
            return redirect()->to('/swiv?lang=' . rawurlencode($locale) . '&erreur=reference');
        }
    }

    public function locate(): RedirectResponse
    {
        $locale = $this->resolveLocale();
        $identifier = trim((string) (
            $this->request->getPost('identifier')
            ?? $this->request->getPost('reference')
        ));

        try {
            $reference = (new PublicReferenceGenerator())->normalize($identifier);
        } catch (InvalidArgumentException) {
            try {
                $reference = (new PublicIdentityTrackingService())
                    ->referenceForNinu(
                        (string) $this->request->getPost('organisation'),
                        $identifier
                    );
            } catch (InvalidArgumentException) {
                $reference = null;
            }
        }

        if (! is_string($reference) || $reference === '') {
            return redirect()->to(
                '/swiv?lang=' . rawurlencode($locale) . '&erreur=reference'
            );
        }

        return redirect()->to(
            '/swiv/' . rawurlencode($reference)
            . '?lang=' . rawurlencode($locale)
        );
    }

    public function requestCode(string $reference): ResponseInterface
    {
        $this->request->setLocale($this->resolveLocale());

        try {
            $result = (new PublicIdentityTrackingService())->requestCode(
                $reference,
                hash('sha256', implode('|', [
                    (string) $this->request->getIPAddress(),
                    (string) $this->request->getUserAgent(),
                    'tracking',
                ]))
            );

            return $this->json([
                'ok' => true,
                'challenge_uuid' => $result['challenge_uuid'],
                'delivered_channel' => $result['delivered_channel'],
                'ttl_seconds' => $result['ttl_seconds'],
                'message' => lang(
                    $result['delivered_channel'] === 'sms'
                        ? 'CitizenPortal.trackingSentSms'
                        : 'CitizenPortal.trackingSentWhatsapp'
                ),
            ]);
        } catch (InvalidArgumentException) {
            return $this->json(['ok' => false, 'message' => lang('CitizenPortal.trackingReferenceInvalid')], 404);
        } catch (Throwable $exception) {
            log_message('error', 'Public tracking OTP request failed: {type}', ['type' => $exception::class]);
            return $this->json(['ok' => false, 'message' => lang('CitizenPortal.trackingUnavailable')], 503);
        }
    }

    public function verifyCode(string $reference): ResponseInterface
    {
        $this->request->setLocale($this->resolveLocale());

        try {
            $accepted = (new PublicIdentityTrackingService())->verifyCode(
                $reference,
                (string) $this->request->getPost('challenge_uuid'),
                (string) $this->request->getPost('code')
            );

            if (! $accepted) {
                return $this->json(['ok' => false, 'message' => lang('CitizenPortal.trackingCodeInvalid')], 422);
            }

            return $this->json([
                'ok' => true,
                'redirect' => '/swiv/' . rawurlencode((new PublicReferenceGenerator())->normalize($reference))
                    . '?lang=' . rawurlencode($this->resolveLocale()),
            ]);
        } catch (InvalidArgumentException | RuntimeException) {
            return $this->json(['ok' => false, 'message' => lang('CitizenPortal.trackingCodeInvalid')], 422);
        } catch (Throwable $exception) {
            log_message('error', 'Public tracking OTP verification failed: {type}', ['type' => $exception::class]);
            return $this->json(['ok' => false, 'message' => lang('CitizenPortal.trackingUnavailable')], 503);
        }
    }

    private function json(array $payload, int $status = 200): ResponseInterface
    {
        helper(['security']);
        $payload['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];

        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setJSON($payload);
    }
}
