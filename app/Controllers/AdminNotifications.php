<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminNotificationService;
use CodeIgniter\Exceptions\PageNotFoundException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminNotifications extends BaseController
{
    use AdminPage;

    public function index(): string
    {
        $context = $this->adminContext();
        $service = new AdminNotificationService($context['tenantContext']);
        $listing = $service->page(
            $context['userId'],
            strtolower(trim((string) $this->request->getGet('status'))),
            strtolower(trim((string) $this->request->getGet('audience'))),
            strtolower(trim((string) $this->request->getGet('channel'))),
            max(1, (int) $this->request->getGet('page')),
            (int) $this->request->getGet('per_page')
        );
        return view('admin/notifications/index', $this->adminPageData($context, 'Admin.notificationsTitle', 'notifications', [
            'listing' => $listing,
            'canManage' => $this->hasPermission($context, 'notifications.manage'),
            'notice' => session()->getFlashdata('notification_notice'),
            'errorMessage' => session()->getFlashdata('notification_error'),
        ]));
    }

    public function show(string $uuid): string
    {
        $context = $this->adminContext();
        $message = (new AdminNotificationService($context['tenantContext']))
            ->detail($context['userId'], $uuid);
        if ($message === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        return view('admin/notifications/show', $this->adminPageData($context, 'Admin.notificationDetailTitle', 'notifications', [
            'message' => $message,
            'canManage' => $this->hasPermission($context, 'notifications.manage'),
        ]));
    }

    public function retry(string $uuid)
    {
        return $this->action($uuid, 'retry');
    }

    public function cancel(string $uuid)
    {
        return $this->action($uuid, 'cancel');
    }

    private function action(string $uuid, string $action)
    {
        $context = $this->adminContext();
        try {
            $service = new AdminNotificationService($context['tenantContext']);
            $action === 'retry'
                ? $service->retry($context['userId'], $uuid)
                : $service->cancel($context['userId'], $uuid);
            session()->setFlashdata('notification_notice', lang($action === 'retry'
                ? 'Admin.notificationRetrySaved' : 'Admin.notificationCancelSaved'));
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Permission denied:')) {
                return $this->forbidden();
            }
            session()->setFlashdata('notification_error', lang('Admin.notificationActionFailed'));
        } catch (Throwable $exception) {
            session()->setFlashdata('notification_error', lang('Admin.notificationActionFailed'));
        }
        return redirect()->to('/admin/notifications');
    }
}
