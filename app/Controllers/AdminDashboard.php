<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminPortalReadService;

final class AdminDashboard extends BaseController
{
    use AdminPage;

    public function index(): string
    {
        $context = $this->adminContext();
        $summary = (new AdminPortalReadService($context['tenantContext']))
            ->dashboard($context['userId']);

        return view('admin/dashboard', $this->adminPageData(
            $context,
            'Admin.dashboardTitle',
            'dashboard',
            ['summary' => $summary]
        ));
    }
}
