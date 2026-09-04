<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminIdentityMapService;
use RuntimeException;

final class AdminMap extends BaseController
{
    use AdminPage;

    public function index()
    {
        $context = $this->adminContext();

        try {
            $rows = (new AdminIdentityMapService(
                $context['tenantContext']
            ))->summaryForActor(
                $context['userId'],
                $context['locale']
            );
        } catch (RuntimeException) {
            return $this->response
                ->setStatusCode(403)
                ->setBody(lang('Admin.accessDenied'));
        }

        return view('admin/map/index', $this->adminPageData(
            $context,
            'Admin.mapTitle',
            'map',
            ['rows' => $rows]
        ));
    }
}
