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

    public function testInlineStylesheetRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        inline_stylesheet('../.env');
    }
}
