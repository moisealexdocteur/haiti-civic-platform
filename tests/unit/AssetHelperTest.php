<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class AssetHelperTest extends CIUnitTestCase
{
    public function testExistingAssetReceivesItsContentFingerprint(): void
    {
        $asset = '/assets/tokens.css';
        $expected = substr((string) hash_file('sha256', FCPATH . ltrim($asset, '/')), 0, 12);

        $this->assertSame($asset . '?v=' . $expected, versioned_asset($asset));
    }

    public function testMissingAssetKeepsItsOriginalPublicPath(): void
    {
        $this->assertSame(
            '/assets/does-not-exist.css',
            versioned_asset('assets/does-not-exist.css')
        );
    }

    public function testAdminStylesheetCanBeDeliveredInline(): void
    {
        $stylesheet = inline_stylesheet('/assets/admin.css');

        $this->assertStringContainsString('.admin-shell', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 620px)', $stylesheet);
    }

    public function testPublicPortalStylesheetCanBeDeliveredInline(): void
    {
        $stylesheet = inline_stylesheet('/assets/portal.css');

        $this->assertStringContainsString('.trust svg', $stylesheet);
        $this->assertStringContainsString('.dirsum', $stylesheet);
        $this->assertStringContainsString('.dirtable-wrap', $stylesheet);
        $this->assertStringContainsString('.dirtable th', $stylesheet);
    }

    public function testInlineStylesheetRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        inline_stylesheet('../.env');
    }

    public function testQrScriptCanBeDeliveredInline(): void
    {
        $script = inline_script('/assets/qr-code.js');

        $this->assertStringContainsString('data-qr', $script);
        $this->assertStringContainsString('renderQr', $script);
    }

    public function testInlineScriptRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        inline_script('../composer.json');
    }
}
