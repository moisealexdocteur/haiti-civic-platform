<?php

namespace App\Controllers;

use App\Services\PublicTenantResolver;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

final class CitizenPortal extends BaseController
{
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
        $tenant = (new PublicTenantResolver())
            ->bySlug(
                rawurldecode($tenantSlug)
            );

        if ($tenant === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $locale = $this->resolveLocale(
            (string) ($tenant['default_locale'] ?? '')
        );

        $this->request->setLocale($locale);

        return view(
            'citizen_portal/register',
            [
                'locale' => $locale,
                'tenant' => $tenant,
            ]
        );
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
