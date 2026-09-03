<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\TenantCommunicationSettingsService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use Throwable;

final class AdminSettings extends BaseController
{
    use AdminPage;

    public function communications(): string
    {
        $context = $this->adminContext();
        $service = new TenantCommunicationSettingsService($context['tenantContext']);

        return view('admin/settings/communications', $this->adminPageData(
            $context,
            'Admin.communicationsTitle',
            'communications',
            [
                'settings' => $service->readForActor($context['userId']),
                'canManage' => $this->hasPermission($context, 'settings.manage'),
                'saved' => session()->getFlashdata('settings_saved') === true,
                'errorMessage' => session()->getFlashdata('settings_error'),
            ]
        ));
    }

    public function saveCommunications(): RedirectResponse
    {
        $context = $this->adminContext();

        try {
            (new TenantCommunicationSettingsService($context['tenantContext']))
                ->saveForActor($context['userId'], $this->request->getPost());
            session()->setFlashdata('settings_saved', true);
        } catch (InvalidArgumentException $exception) {
            session()->setFlashdata('settings_error', lang('Admin.settingsInvalid'));
        } catch (Throwable $exception) {
            log_message('error', 'Communication settings update failed: {type}', ['type' => $exception::class]);
            session()->setFlashdata('settings_error', lang('Admin.settingsSaveFailed'));
        }

        return redirect()->to('/admin/communications');
    }
}
