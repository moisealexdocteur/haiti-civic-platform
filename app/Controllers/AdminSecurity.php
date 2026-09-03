<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminPasswordResetService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use Throwable;

final class AdminSecurity extends BaseController
{
    use AdminPage;

    public function index(): string
    {
        $context = $this->adminContext();

        return view('admin/security', $this->adminPageData(
            $context,
            'Admin.securityTitle',
            'security',
            [
                'saved' => session()->getFlashdata('password_saved') === true,
                'errorMessage' => session()->getFlashdata('password_error'),
            ]
        ));
    }

    public function changePassword(): RedirectResponse
    {
        $context = $this->adminContext();
        $newPassword = (string) $this->request->getPost('new_password');
        $confirmation = (string) $this->request->getPost('password_confirmation');

        try {
            if (! hash_equals($newPassword, $confirmation)) {
                throw new InvalidArgumentException('Password confirmation does not match.');
            }

            $newVersion = (new AdminPasswordResetService())->changeAuthenticatedPassword(
                $context['tenantId'],
                $context['userId'],
                (string) $this->request->getPost('current_password'),
                $newPassword
            );

            if ($newVersion === 0) {
                throw new InvalidArgumentException('Current password is invalid.');
            }

            $context['session']->regenerate(true);
            $context['session']->set('admin_session_version', $newVersion);
            $context['session']->setFlashdata('password_saved', true);
        } catch (InvalidArgumentException $exception) {
            $context['session']->setFlashdata('password_error', lang('Admin.passwordChangeInvalid'));
        } catch (Throwable $exception) {
            log_message('error', 'Admin password change failed: {type}', ['type' => $exception::class]);
            $context['session']->setFlashdata('password_error', lang('Admin.passwordChangeFailed'));
        }

        return redirect()->to('/admin/securite');
    }
}
