<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminPortalReadService;

final class AdminAudit extends BaseController
{
    use AdminPage;

    public function index(): string
    {
        $context = $this->adminContext();
        $entries = (new AdminPortalReadService($context['tenantContext']))
            ->audit($context['userId']);

        return view('admin/audit/index', $this->adminPageData(
            $context,
            'Admin.auditTitle',
            'audit',
            ['entries' => $entries]
        ));
    }
}
