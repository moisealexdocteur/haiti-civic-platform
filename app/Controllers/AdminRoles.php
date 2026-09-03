<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminPortalReadService;
use App\Services\RoleWriteService;
use CodeIgniter\HTTP\RedirectResponse;
use InvalidArgumentException;
use Throwable;

final class AdminRoles extends BaseController
{
    use AdminPage;

    public function index(): string
    {
        $context = $this->adminContext();
        $matrix = (new AdminPortalReadService($context['tenantContext']))
            ->roleMatrix($context['userId']);

        return view('admin/roles/index', $this->adminPageData(
            $context,
            'Admin.rolesTitle',
            'roles',
            [
                'roles' => $matrix['roles'],
                'permissionCatalog' => $matrix['permissions'],
                'canManage' => $this->hasPermission($context, 'roles.manage'),
                'saved' => session()->getFlashdata('role_saved') === true,
                'errorMessage' => session()->getFlashdata('role_error'),
            ]
        ));
    }

    public function create(): RedirectResponse
    {
        $context = $this->adminContext();
        $permissions = $this->permissionsFromRequest();

        try {
            $service = new RoleWriteService($context['tenantContext']);
            $roleId = $service->createRole(
                $context['userId'],
                'custom_' . substr(hash('sha256', random_bytes(32)), 0, 16),
                (string) $this->request->getPost('name'),
                (string) $this->request->getPost('description')
            );
            $service->setPermissions($context['userId'], $roleId, $permissions);
            session()->setFlashdata('role_saved', true);
        } catch (InvalidArgumentException $exception) {
            session()->setFlashdata('role_error', lang('Admin.roleInvalid'));
        } catch (Throwable $exception) {
            log_message('error', 'Administrator role creation failed: {type}', ['type' => $exception::class]);
            session()->setFlashdata('role_error', lang('Admin.roleSaveFailed'));
        }

        return redirect()->to('/admin/roles');
    }

    public function update(string $roleUuid): RedirectResponse
    {
        $context = $this->adminContext();

        try {
            $role = db_connect()->table('roles')
                ->select('id, code, is_system')
                ->where('tenant_id', $context['tenantId'])
                ->where('uuid', strtolower(trim($roleUuid)))
                ->limit(1)
                ->get()
                ->getFirstRow('array');

            if ($role === null || (int) $role['is_system'] === 1 || (string) $role['code'] === 'identity_admin') {
                throw new InvalidArgumentException('Role is not editable.');
            }

            $service = new RoleWriteService($context['tenantContext']);
            $service->updateRole(
                $context['userId'],
                (int) $role['id'],
                (string) $this->request->getPost('name'),
                (string) $this->request->getPost('description')
            );
            $service->setPermissions(
                $context['userId'],
                (int) $role['id'],
                $this->permissionsFromRequest()
            );
            session()->setFlashdata('role_saved', true);
        } catch (InvalidArgumentException $exception) {
            session()->setFlashdata('role_error', lang('Admin.roleInvalid'));
        } catch (Throwable $exception) {
            log_message('error', 'Administrator role update failed: {type}', ['type' => $exception::class]);
            session()->setFlashdata('role_error', lang('Admin.roleSaveFailed'));
        }

        return redirect()->to('/admin/roles');
    }

    private function permissionsFromRequest(): array
    {
        $value = $this->request->getPost('permission_codes');
        return is_array($value) ? array_values($value) : [];
    }
}
