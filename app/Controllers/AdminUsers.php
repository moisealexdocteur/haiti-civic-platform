<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminPortalReadService;
use App\Services\AdminUserService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use Throwable;

final class AdminUsers extends BaseController
{
    use AdminPage;

    public function index(): string
    {
        $context = $this->adminContext();
        $reads = new AdminPortalReadService($context['tenantContext']);

        return view('admin/users/index', $this->adminPageData(
            $context,
            'Admin.usersTitle',
            'users',
            [
                'users' => $reads->users($context['userId']),
                'roles' => $reads->roles($context['userId']),
                'canManage' => $this->hasPermission($context, 'users.manage')
                    && $this->hasPermission($context, 'roles.manage'),
                'saved' => session()->getFlashdata('user_saved') === true,
                'errorMessage' => session()->getFlashdata('user_error'),
            ]
        ));
    }

    public function create(): RedirectResponse
    {
        $context = $this->adminContext();

        try {
            (new AdminUserService($context['tenantContext']))->create(
                $context['userId'],
                (string) $this->request->getPost('display_name'),
                (string) $this->request->getPost('email'),
                (string) $this->request->getPost('locale'),
                (string) $this->request->getPost('password'),
                (int) $this->request->getPost('role_id'),
                $this->request->getPost('is_owner') === '1'
            );
            session()->setFlashdata('user_saved', true);
        } catch (InvalidArgumentException $exception) {
            session()->setFlashdata('user_error', lang('Admin.userInvalid'));
        } catch (Throwable $exception) {
            log_message('error', 'Administrator creation failed: {type}', ['type' => $exception::class]);
            session()->setFlashdata('user_error', lang('Admin.userSaveFailed'));
        }

        return redirect()->to('/admin/utilisateurs');
    }

    public function status(string $userUuid): RedirectResponse
    {
        $context = $this->adminContext();
        $row = db_connect()->table('tenant_users tu')
            ->select('u.id')
            ->join('users u', 'u.id = tu.user_id')
            ->where('tu.tenant_id', $context['tenantId'])
            ->where('u.uuid', strtolower(trim($userUuid)))
            ->limit(1)
            ->get()
            ->getFirstRow('array');

        try {
            if ($row === null) {
                throw new InvalidArgumentException('Administrator does not exist.');
            }

            $status = (string) $this->request->getPost('status');
            (new AdminUserService($context['tenantContext']))
                ->setStatus($context['userId'], (int) $row['id'], $status);
            session()->setFlashdata('user_saved', true);
        } catch (Throwable $exception) {
            session()->setFlashdata('user_error', lang('Admin.userStatusFailed'));
        }

        return redirect()->to('/admin/utilisateurs');
    }
}
