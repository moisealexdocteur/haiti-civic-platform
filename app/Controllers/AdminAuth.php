<?php

namespace App\Controllers;

use App\Controllers\Concerns\PublicPage;
use App\Services\AdminAuthService;
use App\Services\AdminPasswordResetService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use Throwable;

final class AdminAuth extends BaseController
{
    use PublicPage;

    public function login(): string|RedirectResponse
    {
        $session = service('session');

        if ((int) $session->get('admin_user_id') > 0) {
            return redirect()->to('/admin');
        }

        helper('form');
        $locale = $this->authLocale();

        return view('admin/login', $this->authPageData($locale, [
            'errorMessage' => null,
            'resetCompleted' => $this->request->getGet('reset') === '1',
            'tenantValue' => '',
            'emailValue' => '',
        ]));
    }

    public function authenticate(): string|RedirectResponse
    {
        helper('form');

        $result = (new AdminAuthService())->authenticate(
            (string) $this->request->getPost('tenant'),
            (string) $this->request->getPost('email'),
            (string) $this->request->getPost('password')
        );

        if ($result === null) {
            $this->response->setStatusCode(422);

            $locale = $this->authLocale();

            return view('admin/login', $this->authPageData($locale, [
                'errorMessage' => 'Identifiants invalides ou accès inactif.',
                'resetCompleted' => false,
                'tenantValue' => (string) $this->request->getPost('tenant'),
                'emailValue' => (string) $this->request->getPost('email'),
            ]));
        }

        $session = service('session');
        $session->regenerate(true);
        $session->set([
            'admin_user_id' => (int) $result['user_id'],
            'admin_user_uuid' => (string) $result['user_uuid'],
            'admin_display_name' => (string) $result['display_name'],
            'admin_tenant_id' => (int) $result['tenant_id'],
            'admin_tenant_uuid' => (string) $result['tenant_uuid'],
            'admin_tenant_slug' => (string) $result['tenant_slug'],
            'admin_tenant_name' => (string) $result['tenant_name'],
            'admin_locale' => (string) $result['locale'],
            'admin_session_version' => (int) $result['session_version'],
        ]);

        return redirect()->to('/admin');
    }

    public function forgot(): string|RedirectResponse
    {
        if ((int) service('session')->get('admin_user_id') > 0) {
            return redirect()->to('/admin/securite');
        }

        helper('form');
        $locale = $this->authLocale();

        return view('admin/forgot_password', $this->authPageData($locale, [
            'sent' => session()->getFlashdata('reset_requested') === true,
        ], 'Admin.forgotTitle'));
    }

    public function requestReset(): RedirectResponse
    {
        $locale = $this->authLocale();

        try {
            (new AdminPasswordResetService())->request(
                (string) $this->request->getPost('tenant'),
                (string) $this->request->getPost('email'),
                site_url('admin/password/reset')
            );
        } catch (Throwable $exception) {
            log_message('error', 'Admin password reset request failed: {type}', ['type' => $exception::class]);
        }

        session()->setFlashdata('reset_requested', true);
        return redirect()->to('/admin/password/forgot?lang=' . rawurlencode($locale));
    }

    public function reset(): string
    {
        helper('form');
        $locale = $this->authLocale();
        $tenant = (string) $this->request->getGet('organisation');
        $requestUuid = (string) $this->request->getGet('demande');
        $token = (string) $this->request->getGet('jeton');
        $usable = (new AdminPasswordResetService())->isUsable($tenant, $requestUuid, $token);

        return view('admin/reset_password', $this->authPageData($locale, [
            'tenant' => $tenant,
            'requestUuid' => $requestUuid,
            'token' => $token,
            'usable' => $usable,
            'errorMessage' => null,
            'hidePreferences' => true,
        ], 'Admin.resetTitle'));
    }

    public function completeReset(): string|RedirectResponse
    {
        helper('form');
        $locale = $this->authLocale();
        $tenant = (string) $this->request->getPost('tenant');
        $requestUuid = (string) $this->request->getPost('request_uuid');
        $token = (string) $this->request->getPost('token');
        $password = (string) $this->request->getPost('password');
        $confirmation = (string) $this->request->getPost('password_confirmation');

        $service = new AdminPasswordResetService();

        try {
            if (! hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException('Password confirmation does not match.');
            }

            if (! $service->reset($tenant, $requestUuid, $token, $password)) {
                throw new InvalidArgumentException('Password reset link is invalid.');
            }

            return redirect()->to('/admin/login?lang=' . rawurlencode($locale) . '&reset=1');
        } catch (InvalidArgumentException $exception) {
            $this->response->setStatusCode(422);

            return view('admin/reset_password', $this->authPageData($locale, [
                'tenant' => $tenant,
                'requestUuid' => $requestUuid,
                'token' => $token,
                'usable' => $service->isUsable($tenant, $requestUuid, $token),
                'errorMessage' => lang('Admin.resetInvalid'),
                'hidePreferences' => true,
            ], 'Admin.resetTitle'));
        } catch (Throwable $exception) {
            log_message('error', 'Admin password reset failed: {type}', ['type' => $exception::class]);
            $this->response->setStatusCode(503);

            return view('admin/reset_password', $this->authPageData($locale, [
                'tenant' => $tenant,
                'requestUuid' => $requestUuid,
                'token' => $token,
                'usable' => true,
                'errorMessage' => lang('Admin.resetFailed'),
                'hidePreferences' => true,
            ], 'Admin.resetTitle'));
        }
    }

    public function logout(): RedirectResponse
    {
        $session = service('session');
        $session->remove([
            'admin_user_id',
            'admin_user_uuid',
            'admin_display_name',
            'admin_tenant_id',
            'admin_tenant_uuid',
            'admin_tenant_slug',
            'admin_tenant_name',
            'admin_locale',
            'admin_session_version',
        ]);
        $session->regenerate(true);

        return redirect()->to('/admin/login');
    }

    private function authLocale(): string
    {
        $locale = $this->resolveLocale();
        $this->request->setLocale($locale);
        $this->rememberLocale($locale);
        return $locale;
    }

    private function authPageData(
        string $locale,
        array $extra,
        string $titleKey = 'Admin.loginTitle'
    ): array
    {
        $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Referrer-Policy', 'no-referrer');

        $path = '/' . ltrim($this->request->getUri()->getPath(), '/');

        return $this->pageData(
            $locale,
            lang($titleKey),
            ['fr' => $path . '?lang=fr', 'ht' => $path . '?lang=ht'],
            $extra
        );
    }
}
