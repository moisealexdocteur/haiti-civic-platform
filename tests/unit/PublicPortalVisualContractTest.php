<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class PublicPortalVisualContractTest extends CIUnitTestCase
{
    public function testPublicLayoutDeliversItsComponentStylesInline(): void
    {
        $layout = $this->readProjectFile('app/Views/layouts/public.php');

        $this->assertStringContainsString('data-portal-styles="inline"', $layout);
        $this->assertStringContainsString("inline_stylesheet('/assets/portal.css')", $layout);
        $this->assertStringNotContainsString("versioned_asset('/assets/portal.css')", $layout);
    }

    public function testTrustIconHasSafeIntrinsicDimensions(): void
    {
        $icon = $this->readProjectFile('app/Views/partials/icon_check.php');

        $this->assertStringContainsString('class="icon-check"', $icon);
        $this->assertStringContainsString('width="17"', $icon);
        $this->assertStringContainsString('height="17"', $icon);
    }

    public function testApprovedDirectoryComponentsKeepTheirCssHooks(): void
    {
        $view = $this->readProjectFile('app/Views/citizen_portal/political_structures.php');

        $this->assertStringContainsString('class="dirsum"', $view);
        $this->assertStringContainsString('class="dirtable-wrap"', $view);
        $this->assertStringContainsString('class="dirtable"', $view);
    }

    public function testInstalledApplicationKeepsOneProductIdentity(): void
    {
        $layout = $this->readProjectFile('app/Views/layouts/public.php');
        $manifest = json_decode(
            $this->readProjectFile('public/manifest.webmanifest'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertStringContainsString(
            '<link rel="manifest" href="/manifest.webmanifest">',
            $layout
        );
        $this->assertStringContainsString(
            'apple-mobile-web-app-title',
            $layout
        );
        $this->assertSame(
            'Portail de vérification citoyenne',
            $manifest['name']
        );
        $this->assertFileExists(ROOTPATH . 'public/assets/portal-mark-192.png');
        $this->assertFileExists(ROOTPATH . 'public/assets/portal-mark-512.png');
    }

    public function testServiceWorkerCachesOnlyStaticAssets(): void
    {
        $worker = $this->readProjectFile('public/service-worker.js');

        $this->assertStringContainsString(
            "requestUrl.pathname.indexOf('/assets/') !== 0",
            $worker
        );
        $this->assertStringNotContainsString('caches.addAll', $worker);
    }

    public function testMobileConfirmationEmbedsQrRenderer(): void
    {
        $confirmation = $this->readProjectFile(
            'app/Views/citizen_portal/confirmation.php'
        );

        $this->assertStringContainsString(
            "inline_script('/assets/qr-code.js')",
            $confirmation
        );
    }

    public function testAdminMapLoadsLeafletLocallyWithItsLayoutStyles(): void
    {
        $view = $this->readProjectFile('app/Views/admin/map/index.php');

        $this->assertStringContainsString(
            "inline_stylesheet('/assets/leaflet.css')",
            $view
        );
        $this->assertStringContainsString(
            "versioned_asset('/assets/leaflet.js')",
            $view
        );
        $this->assertStringNotContainsString('unpkg.com', $view);
        $this->assertStringNotContainsString('cdnjs.cloudflare.com', $view);
        $this->assertFileExists(ROOTPATH . 'public/assets/LEAFLET-LICENSE.txt');
    }

    public function testAdminMapRepairsItsSizeAfterResponsiveLayoutChanges(): void
    {
        $script = $this->readProjectFile('public/assets/admin-map.js');

        $this->assertStringContainsString('map.invalidateSize', $script);
        $this->assertStringContainsString('ResizeObserver', $script);
    }

    public function testOfficialIdentityCheckRemainsManualAndAudited(): void
    {
        $view = $this->readProjectFile(
            'app/Views/admin/identities/show.php'
        );
        $routes = $this->readProjectFile('app/Config/Routes.php');

        $this->assertStringContainsString(
            'https://delidoc.gouv.ht/DemandePasseport/Client/Demande',
            $view
        );
        $this->assertStringContainsString('name="outcome"', $view);
        $this->assertStringContainsString('/controle-oni', $view);
        $this->assertStringContainsString(
            'AdminIdentities::recordAuthorityCheck/$1',
            $routes
        );
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(ROOTPATH . $path);

        $this->assertIsString($contents);
        $this->assertNotSame('', $contents);

        return $contents;
    }
}
