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

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(ROOTPATH . $path);

        $this->assertIsString($contents);
        $this->assertNotSame('', $contents);

        return $contents;
    }
}
