<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\TenantCommunicationSettingsService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
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
                'deleted' => session()->getFlashdata('settings_deleted') === true,
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

    public function testCommunication(string $channel): ResponseInterface
    {
        $context = $this->adminContext();

        try {
            $result = (new TenantCommunicationSettingsService($context['tenantContext']))
                ->testForActor(
                    $context['userId'],
                    $channel,
                    $this->request->getPost(),
                    (string) $this->request->getPost('test_destination')
                );

            return $this->channelJson($result, $result['ok'] ? 200 : 422);
        } catch (InvalidArgumentException $exception) {
            return $this->channelJson([
                'ok' => false,
                'channel' => $channel,
                'title' => lang('Admin.channelTestFailed'),
                'message' => lang('Admin.channelConfigurationInvalid'),
                'provider_detail' => $exception->getMessage(),
                'advice' => lang('Admin.channelAdviceReview'),
                'failure_code' => 'invalid_configuration',
            ], 422);
        } catch (Throwable $exception) {
            log_message('error', 'Communication channel test failed: {type}', ['type' => $exception::class]);

            return $this->channelJson([
                'ok' => false,
                'channel' => $channel,
                'title' => lang('Admin.channelTestFailed'),
                'message' => lang('Admin.channelTestUnexpected'),
                'provider_detail' => null,
                'advice' => lang('Admin.channelAdviceNetwork'),
                'failure_code' => 'test_unavailable',
            ], 503);
        }
    }

    public function deleteCommunication(string $channel): RedirectResponse
    {
        $context = $this->adminContext();

        try {
            (new TenantCommunicationSettingsService($context['tenantContext']))
                ->deleteForActor($context['userId'], $channel);
            session()->setFlashdata('settings_deleted', true);
        } catch (InvalidArgumentException) {
            session()->setFlashdata('settings_error', lang('Admin.channelUnknown'));
        } catch (Throwable $exception) {
            log_message('error', 'Communication channel deletion failed: {type}', ['type' => $exception::class]);
            session()->setFlashdata('settings_error', lang('Admin.channelDeleteFailed'));
        }

        return redirect()->to('/admin/communications');
    }

    private function channelJson(array $payload, int $status): ResponseInterface
    {
        helper('security');
        $payload['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];

        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setJSON($payload);
    }
}
