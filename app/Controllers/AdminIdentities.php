<?php

namespace App\Controllers;

use App\Services\AdminIdentityDecisionService;
use App\Services\AdminIdentityReadService;
use App\Services\AuthorizationService;
use App\Services\TenantContext;
use CodeIgniter\Exceptions\PageNotFoundException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminIdentities extends BaseController
{
    public function index()
    {
        helper('form');
        $this->noStore();

        [$tenantContext, $actorUserId, $session] = $this->adminContext();
        $status = strtolower(trim((string) $this->request->getGet('status')));
        $status = $status === '' ? 'pending' : $status;

        try {
            $rows = (new AdminIdentityReadService($tenantContext))
                ->listForActor($actorUserId, $status);
        } catch (RuntimeException $exception) {
            return $this->forbidden();
        } catch (InvalidArgumentException $exception) {
            $this->response->setStatusCode(400);
            $rows = [];
            $status = 'pending';
        }

        $authorization = new AuthorizationService($tenantContext);

        return view('admin/identities/index', [
            'rows' => $rows,
            'status' => $status,
            'canManage' => $authorization->userHasPermission(
                $actorUserId,
                'identity.manage'
            ),
            'displayName' => (string) $session->get('admin_display_name'),
            'tenantName' => (string) $session->get('admin_tenant_name'),
        ]);
    }

    public function show(string $identityUuid)
    {
        helper('form');
        $this->noStore();

        [$tenantContext, $actorUserId, $session] = $this->adminContext();

        try {
            $identity = (new AdminIdentityReadService($tenantContext))
                ->detailForActorByUuid($actorUserId, rawurldecode($identityUuid));
        } catch (RuntimeException $exception) {
            return $this->forbidden();
        } catch (InvalidArgumentException $exception) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($identity === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $authorization = new AuthorizationService($tenantContext);

        return view('admin/identities/show', [
            'identity' => $identity,
            'canManage' => $authorization->userHasPermission(
                $actorUserId,
                'identity.manage'
            ),
            'displayName' => (string) $session->get('admin_display_name'),
            'tenantName' => (string) $session->get('admin_tenant_name'),
            'decisionOk' => $this->request->getGet('decision') === 'ok',
            'decisionError' => $session->getFlashdata('decision_error'),
        ]);
    }

    public function document(string $identityUuid, string $documentUuid)
    {
        $this->noStore();
        [$tenantContext, $actorUserId] = $this->adminContext();

        try {
            $document = (new AdminIdentityReadService($tenantContext))
                ->documentForActor(
                    $actorUserId,
                    rawurldecode($identityUuid),
                    rawurldecode($documentUuid)
                );
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Permission denied:')) {
                return $this->forbidden();
            }

            log_message('error', 'Protected identity document read failed: {type}', [
                'type' => $exception::class,
            ]);

            throw PageNotFoundException::forPageNotFound();
        } catch (InvalidArgumentException $exception) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($document === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $body = file_get_contents((string) $document['absolute_path']);

        if (! is_string($body)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setHeader('Content-Type', (string) $document['content_type'])
            ->setHeader('Content-Length', (string) strlen($body))
            ->setHeader('Content-Disposition', 'inline; filename="' . (string) $document['download_name'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setBody($body);
    }

    public function transition(string $identityUuid)
    {
        [$tenantContext, $actorUserId, $session] = $this->adminContext();
        $toStatus = (string) $this->request->getPost('to_status');
        $reasonCode = trim((string) $this->request->getPost('reason_code'));

        try {
            (new AdminIdentityDecisionService($tenantContext))->transition(
                $actorUserId,
                rawurldecode($identityUuid),
                $toStatus,
                $reasonCode === '' ? null : $reasonCode
            );
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Permission denied:')) {
                return $this->forbidden();
            }

            $session->setFlashdata(
                'decision_error',
                'La décision n’a pas pu être enregistrée.'
            );

            return redirect()->to(
                '/admin/identites/' . rawurlencode($identityUuid)
            );
        } catch (InvalidArgumentException $exception) {
            $session->setFlashdata(
                'decision_error',
                'Transition invalide ou motif obligatoire manquant.'
            );

            return redirect()->to(
                '/admin/identites/' . rawurlencode($identityUuid)
            );
        } catch (Throwable $exception) {
            log_message('error', 'Admin identity decision failed: {type}', [
                'type' => $exception::class,
            ]);

            $session->setFlashdata(
                'decision_error',
                'La décision n’a pas pu être enregistrée.'
            );

            return redirect()->to(
                '/admin/identites/' . rawurlencode($identityUuid)
            );
        }

        return redirect()->to(
            '/admin/identites/' . rawurlencode($identityUuid) . '?decision=ok'
        );
    }

    private function adminContext(): array
    {
        $session = service('session');
        $actorUserId = (int) $session->get('admin_user_id');
        $tenantId = (int) $session->get('admin_tenant_id');

        if ($actorUserId <= 0 || $tenantId <= 0) {
            throw new RuntimeException('Administrative session is missing.');
        }

        return [
            (new TenantContext())->set($tenantId),
            $actorUserId,
            $session,
        ];
    }

    private function noStore(): void
    {
        $this->response
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setHeader('Pragma', 'no-cache');
    }

    private function forbidden()
    {
        return $this->response
            ->setStatusCode(403)
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setBody('Accès refusé.');
    }
}
