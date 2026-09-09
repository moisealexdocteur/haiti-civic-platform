<?php
namespace Tests\Unit;

use App\Services\NavigationLinks;
use PHPUnit\Framework\TestCase;

final class NavigationLinksTest extends TestCase
{
    public function testFilteredListReturnKeepsPageAndDepartment(): void
    {
        $url = NavigationLinks::listUrl('/admin/identites/abc/confirmation',
            ['status' => 'auto_accepted', 'department' => '03', 'page' => 2], 'fr');
        self::assertSame('/admin/identites?status=auto_accepted&department=03&page=2&lang=fr', $url);
    }

    public function testExternalReturnTargetsAndArrayQueriesAreNotUsed(): void
    {
        $url = NavigationLinks::listUrl('/admin/notifications/abc',
            ['return' => 'https://attacker.test', 'page' => [1], 'status' => 'queued'], 'ht');
        self::assertSame('/admin/notifications?status=queued&lang=ht', $url);
        self::assertSame('/admin?lang=ht', NavigationLinks::listUrl('//attacker.test', [], 'evil'));
    }
}
