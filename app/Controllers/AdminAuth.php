<?php

namespace App\Controllers;

use App\Services\AdminAuthService;
use CodeIgniter\HTTP\RedirectResponse;

final class AdminAuth extends BaseController
{
    public function login(): string|RedirectResponse
    {
        $session = service('session');

        if ((int) $session->get('admin_user_id') > 0) {
            return redirect()->to('/admin/identites');
        }

        helper('form');

        return view('admin/login', [
            'errorMessage' => null,
        ]);
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

            return view('admin/login', [
                'errorMessage' => 'Identifiants invalides ou accès inactif.',
            ]);
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
        ]);

        return redirect()->to('/admin/identites');
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
        ]);
        $session->regenerate(true);

        return redirect()->to('/admin/login');
    }
}
