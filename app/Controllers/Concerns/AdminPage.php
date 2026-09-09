<?php

namespace App\Controllers\Concerns;

use App\Services\AdminPortalReadService;
use App\Services\TenantContext;

trait AdminPage
{
    use PublicPage;

    protected function adminContext(): array
    {
        $session = service('session');
        $tenantId = (int) $session->get('admin_tenant_id');
        $userId = (int) $session->get('admin_user_id');
        $context = (new TenantContext())->set($tenantId);
        $locale = $this->resolveLocale((string) $session->get('admin_locale'));
        $this->request->setLocale($locale);
        $this->rememberLocale($locale);

        return [
            'session' => $session,
            'tenantContext' => $context,
            'tenantId' => $tenantId,
            'userId' => $userId,
            'locale' => $locale,
            'permissions' => (new AdminPortalReadService($context))->permissions($userId),
        ];
    }

    protected function adminPageData(
        array $context,
        string $titleKey,
        string $activeNav,
        array $extra = []
    ): array {
        $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Referrer-Policy', 'same-origin');

        $session = $context['session'];
        $path = '/' . ltrim($this->request->getUri()->getPath(), '/');
        $list = \App\Services\NavigationLinks::listPath($path);
        $key = 'admin_list_' . $context['tenantId'] . '_' . $context['userId'] . '_' . $list;
        if ($list !== null && $path === $list) {
            parse_str(\App\Services\NavigationLinks::query($this->request->getGet() ?? []), $saved);
            $session->set($key, $saved);
        }
        $saved = $list === null ? [] : (array) $session->get($key);
        $backUrl = \App\Services\NavigationLinks::listUrl($path, $saved, $context['locale']);

        return $this->pageData(
            $context['locale'],
            lang($titleKey),
            $this->adminLanguageUrls(),
            array_merge([
                'tenantName' => (string) $session->get('admin_tenant_name'),
                'displayName' => (string) $session->get('admin_display_name'),
                'permissions' => $context['permissions'],
                'activeNav' => $activeNav,
                'navigationBackUrl' => $backUrl,
            ], $extra)
        );
    }

    protected function adminLanguageUrls(): array
    {
        $path = '/' . ltrim($this->request->getUri()->getPath(), '/');

        return [
            'fr' => $path . '?' . \App\Services\NavigationLinks::query(array_merge($this->request->getGet() ?? [], ['lang' => 'fr'])),
            'ht' => $path . '?' . \App\Services\NavigationLinks::query(array_merge($this->request->getGet() ?? [], ['lang' => 'ht'])),
        ];
    }

    protected function returnToList(array $context, string $path): string
    {
        $list = \App\Services\NavigationLinks::listPath($path);
        $key = 'admin_list_' . $context['tenantId'] . '_' . $context['userId'] . '_' . $list;
        return \App\Services\NavigationLinks::listUrl($path, (array) $context['session']->get($key), $context['locale']);
    }

    protected function hasPermission(array $context, string $permission): bool
    {
        return in_array($permission, $context['permissions'], true);
    }
}
