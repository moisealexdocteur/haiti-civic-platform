<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AdminAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = service('session');

        if (
            (int) $session->get('admin_user_id') > 0
            && (int) $session->get('admin_tenant_id') > 0
            && is_string($session->get('admin_tenant_slug'))
            && $session->get('admin_tenant_slug') !== ''
        ) {
            return null;
        }

        return redirect()->to('/admin/login');
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        return null;
    }
}
