<?php

namespace App\Filters;

use App\Services\AuthorizationService;
use App\Services\TenantContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AdminPermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = service('session');
        $userId = (int) $session->get('admin_user_id');
        $tenantId = (int) $session->get('admin_tenant_id');
        $required = array_values(array_filter(
            is_array($arguments) ? $arguments : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));

        if ($userId <= 0 || $tenantId <= 0 || $required === []) {
            return service('response')->setStatusCode(403)->setBody('Forbidden');
        }

        $tenantContext = (new TenantContext())->set($tenantId);
        $authorization = new AuthorizationService($tenantContext);

        foreach ($required as $permission) {
            if (! $authorization->userHasPermission($userId, $permission)) {
                return $this->forbidden($request, $authorization, $userId);
            }
        }

        return null;
    }

    public function after(
        RequestInterface $request,
        ResponseInterface $response,
        $arguments = null
    ) {
        return null;
    }

    private function forbidden(
        RequestInterface $request,
        AuthorizationService $authorization,
        int $userId
    ): ResponseInterface {
        $session = service('session');
        $locale = (string) $session->get('admin_locale');

        if (! in_array($locale, ['fr', 'ht'], true)) {
            $locale = 'ht';
        }

        $request->setLocale($locale);
        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        $data = [
            'locale' => $locale,
            'pageTitle' => lang('Admin.accessDeniedTitle'),
            'langUrls' => [
                'fr' => $path . '?lang=fr',
                'ht' => $path . '?lang=ht',
            ],
            'theme' => null,
            'themeUrls' => [
                'auto' => $path . '?lang=' . rawurlencode($locale) . '&theme=auto',
                'light' => $path . '?lang=' . rawurlencode($locale) . '&theme=light',
                'dark' => $path . '?lang=' . rawurlencode($locale) . '&theme=dark',
            ],
            'tenantName' => (string) $session->get('admin_tenant_name'),
            'displayName' => (string) $session->get('admin_display_name'),
            'permissions' => $authorization->permissionsForUser($userId),
            'activeNav' => '',
        ];

        return service('response')
            ->setStatusCode(403)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Referrer-Policy', 'same-origin')
            ->setBody(view('admin/forbidden', $data));
    }
}
