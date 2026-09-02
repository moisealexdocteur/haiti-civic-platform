<?php

namespace App\Filters;

use App\Services\AdminAuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AdminAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = service('session');
        $userId = (int) $session->get('admin_user_id');
        $tenantId = (int) $session->get('admin_tenant_id');
        $tenantSlug = (string) $session->get('admin_tenant_slug');

        if (
            $userId > 0
            && $tenantId > 0
            && $tenantSlug !== ''
            && (new AdminAuthService())->sessionIsActive(
                $userId,
                $tenantId,
                $tenantSlug
            )
        ) {
            return null;
        }

        $session->remove([
            'admin_user_id',
            'admin_user_uuid',
            'admin_display_name',
            'admin_tenant_id',
            'admin_tenant_uuid',
            'admin_tenant_slug',
            'admin_tenant_name',
        ]);

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
