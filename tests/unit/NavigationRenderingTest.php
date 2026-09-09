<?php
namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class NavigationRenderingTest extends CIUnitTestCase
{
    private function publicData(bool $admin): array
    {
        return ['locale' => 'fr', 'pageTitle' => 'Test', 'theme' => null,
            'adminAvailable' => $admin, 'navigationPath' => '/swiv',
            'langUrls' => ['fr' => '/swiv?lang=fr', 'ht' => '/swiv?lang=ht']];
    }

    public function testGuestCanReturnHomeWithoutSeeingAdminLink(): void
    {
        helper('asset');
        service('request')->setLocale('fr');
        $html = view('layouts/public', $this->publicData(false), ['saveData' => false]);
        $this->assertStringContainsString('href="/?lang=fr"', $html);
        $this->assertStringNotContainsString('href="/admin?lang=fr"', $html);
    }

    public function testActiveAdminCanReturnFromPublicPortal(): void
    {
        helper('asset');
        $html = view('layouts/public', $this->publicData(true), ['saveData' => false]);
        $this->assertStringContainsString('href="/admin?lang=fr"', $html);
    }

    public function testNewDocumentViewerIsProtectedByAuthenticationAndPermission(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $this->assertMatchesRegularExpression(
            "/documents\/\(:segment\)\/voir',\\s*'AdminIdentities::documentPreview\/\\$1\/\\$2',\\s*\\['filter' => \\['adminauth', 'adminperm:identity.view'\\]\\]/",
            $routes
        );
    }
}
