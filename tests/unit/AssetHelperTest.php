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
}
