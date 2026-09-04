<?php

namespace App\Controllers;

use App\Controllers\Concerns\AdminPage;
use App\Services\AdminIdentityDecisionService;
use App\Services\AdminIdentityConfirmationService;
use App\Services\AdminIdentityExportService;
use App\Services\AdminIdentityReadService;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\HaitiDepartmentCatalog;
use App\Services\ManualIdentityAuthorityCheckService;
use CodeIgniter\Exceptions\PageNotFoundException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AdminIdentities extends BaseController
{
    use AdminPage;

    public function index()
    {
        helper('form');
        $this->noStore();

        $context = $this->adminContext();
        $tenantContext = $context['tenantContext'];
        $actorUserId = $context['userId'];
        $session = $context['session'];
        $status = strtolower(trim((string) $this->request->getGet('status')));
        $status = $status === '' ? 'pending' : $status;
        $department = trim((string) $this->request->getGet('department'));
        $sort = strtolower(trim((string) $this->request->getGet('sort')));
        $direction = strtolower(trim((string) $this->request->getGet('direction')));
        $page = max(1, (int) $this->request->getGet('page'));
        $perPage = (int) $this->request->getGet('per_page');
        $sort = $sort === '' ? 'submitted' : $sort;
        $direction = $direction === '' ? 'asc' : $direction;
        $perPage = $perPage === 0 ? 50 : $perPage;

        try {
            $listing = (new AdminIdentityReadService($tenantContext))
                ->pageForActor(
                    $actorUserId,
                    $status,
                    $department === '' ? null : $department,
                    $sort,
                    $direction,
                    $page,
                    $perPage
                );
        } catch (RuntimeException $exception) {
            return $this->forbidden();
        } catch (InvalidArgumentException $exception) {
            $this->response->setStatusCode(400);
            $listing = [
                'rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 50,
                'pages' => 1, 'status' => 'pending', 'department' => null,
                'sort' => 'submitted', 'direction' => 'asc',
            ];
        }

        $authorization = new AuthorizationService($tenantContext);

        return view('admin/identities/index', $this->adminPageData(
            $context,
            'Admin.identitiesTitle',
            'identities',
            [
            'listing' => $listing,
            'canManage' => $authorization->userHasPermission(
                $actorUserId,
                'identity.manage'
            ),
            'departments' => (new HaitiDepartmentCatalog())
                ->options($context['locale']),
            'confirmationSent' => $session->getFlashdata('confirmation_sent'),
            'confirmationError' => $session->getFlashdata('confirmation_error'),
            ]
        ));
    }

    public function export(string $format)
    {
        $this->noStore();
        $context = $this->adminContext();
        $format = strtolower(trim(rawurldecode($format)));

        if (! in_array($format, ['pdf', 'xls'], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $status = strtolower(trim((string) $this->request->getGet('status')));
        $department = trim((string) $this->request->getGet('department'));
        $sort = strtolower(trim((string) $this->request->getGet('sort')));
        $direction = strtolower(trim((string) $this->request->getGet('direction')));
        $status = $status === '' ? 'all' : $status;
        $sort = $sort === '' ? 'submitted' : $sort;
        $direction = $direction === '' ? 'asc' : $direction;

        try {
            $rows = (new AdminIdentityReadService($context['tenantContext']))
                ->exportForActor(
                    $context['userId'],
                    $status,
                    $department === '' ? null : $department,
                    $sort,
                    $direction
                );
        } catch (RuntimeException $exception) {
            return $this->forbidden();
        } catch (InvalidArgumentException $exception) {
            return $this->response->setStatusCode(400)->setBody(lang('Admin.exportInvalid'));
        }

        $statuses = [
            'pending' => lang('Admin.statusPending'),
            'verified' => lang('Admin.statusVerified'),
            'rejected' => lang('Admin.statusRejected'),
        ];
        $labels = [
            'sheet' => lang('Admin.exportSheet'),
            'title' => lang('Admin.exportTitle'),
            'page' => lang('Admin.exportPage'),
            'reference' => lang('Admin.reference'),
            'status' => lang('Admin.status'),
            'department' => lang('Admin.department'),
            'documents' => lang('Admin.documents'),
            'submitted' => lang('Admin.submittedAt'),
            'notProvided' => lang('Admin.notProvided'),
            'statuses' => $statuses,
        ];
        $departments = (new HaitiDepartmentCatalog())->options($context['locale']);
        $tenantName = (string) $context['session']->get('admin_tenant_name');
        $exporter = new AdminIdentityExportService();
        $body = $format === 'pdf'
            ? $exporter->pdf($rows, $labels, $departments, $tenantName)
            : $exporter->xls($rows, $labels, $departments);

        (new AuditService($context['tenantContext']))->record(
            event: 'citizen_identity.list_exported',
            actorUserId: $context['userId'],
            entityType: 'citizen_identity_list',
            entityId: $context['tenantId'],
            context: [
                'format' => $format,
                'status' => $status,
                'department' => $department === '' ? null : $department,
                'sort' => $sort,
                'direction' => $direction,
                'row_count' => count($rows),
                'sensitive_fields_included' => false,
            ]
        );

        $date = gmdate('Ymd-His');
        $contentType = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.ms-excel; charset=UTF-8';

        return $this->response
            ->setHeader('Content-Type', $contentType)
            ->setHeader('Content-Disposition', 'attachment; filename="dossiers-' . $date . '.' . $format . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($body);
    }

    public function confirmation(string $identityUuid)
    {
        $this->noStore();
        $context = $this->adminContext();

        try {
            $identity = (new AdminIdentityReadService($context['tenantContext']))
                ->detailForActorByUuid($context['userId'], rawurldecode($identityUuid));
        } catch (RuntimeException $exception) {
            return $this->forbidden();
        } catch (InvalidArgumentException $exception) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($identity === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        (new AuditService($context['tenantContext']))->record(
            event: 'citizen_identity.confirmation_printed',
            actorUserId: $context['userId'],
            entityType: 'citizen_identity',
            entityId: (int) $identity['record_id'],
            context: ['sensitive_fields_included' => false]
        );

        return view('admin/identities/confirmation', [
            'locale' => $context['locale'],
            'tenantName' => (string) $context['session']->get('admin_tenant_name'),
            'reference' => (string) $identity['public_reference'],
            'submittedAt' => (string) $identity['created_at'],
            'trackingUrl' => site_url('swiv/' . rawurlencode((string) $identity['public_reference']))
                . '?lang=' . rawurlencode($context['locale']),
        ]);
    }

    public function resendConfirmation(string $identityUuid)
    {
        $context = $this->adminContext();
        $session = $context['session'];

        try {
            (new AdminIdentityConfirmationService($context['tenantContext']))->resend(
                $context['userId'],
                rawurldecode($identityUuid),
                rtrim(site_url(), '/')
            );
            $session->setFlashdata('confirmation_sent', lang('Admin.confirmationResent'));
        } catch (RuntimeException | InvalidArgumentException $exception) {
            if (str_starts_with($exception->getMessage(), 'Permission denied:')) {
                return $this->forbidden();
            }

            log_message('warning', 'Admin confirmation resend failed: {reason}', [
                'reason' => $exception->getMessage(),
            ]);
            $session->setFlashdata('confirmation_error', lang('Admin.confirmationResendFailed'));
        } catch (Throwable $exception) {
            log_message('error', 'Admin confirmation resend failed: {type}', [
                'type' => $exception::class,
            ]);
            $session->setFlashdata('confirmation_error', lang('Admin.confirmationResendFailed'));
        }

        return redirect()->to('/admin/identites/' . rawurlencode($identityUuid));
    }

    public function show(string $identityUuid)
    {
        helper('form');
        $this->noStore();

        $context = $this->adminContext();
        $tenantContext = $context['tenantContext'];
        $actorUserId = $context['userId'];
        $session = $context['session'];

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

        return view('admin/identities/show', $this->adminPageData(
            $context,
            'Admin.identityTitle',
            'identities',
            [
            'identity' => $identity,
            'canManage' => $authorization->userHasPermission(
                $actorUserId,
                'identity.manage'
            ),
            'decisionOk' => $this->request->getGet('decision') === 'ok',
            'decisionError' => $session->getFlashdata('decision_error'),
            'confirmationSent' => $session->getFlashdata('confirmation_sent'),
            'confirmationError' => $session->getFlashdata('confirmation_error'),
            'authorityCheckSaved' => $session->getFlashdata('authority_check_saved'),
            'authorityCheckError' => $session->getFlashdata('authority_check_error'),
            'departments' => (new HaitiDepartmentCatalog())
                ->options($context['locale']),
            ]
        ));
    }

    public function document(string $identityUuid, string $documentUuid)
    {
        $this->noStore();
        $context = $this->adminContext();
        $tenantContext = $context['tenantContext'];
        $actorUserId = $context['userId'];

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
        $context = $this->adminContext();
        $tenantContext = $context['tenantContext'];
        $actorUserId = $context['userId'];
        $session = $context['session'];
        $toStatus = (string) $this->request->getPost('to_status');
        $reasonCode = trim((string) $this->request->getPost('reason_code'));

        try {
            (new AdminIdentityDecisionService($tenantContext))->transition(
                $actorUserId,
                rawurldecode($identityUuid),
                $toStatus,
                $reasonCode === '' ? null : $reasonCode,
                (string) $this->request->getPost('contact_reviewed') === '1'
            );
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Permission denied:')) {
                return $this->forbidden();
            }

            $session->setFlashdata(
                'decision_error',
                lang('Admin.decisionFailed')
            );

            return redirect()->to(
                '/admin/identites/' . rawurlencode($identityUuid)
            );
        } catch (InvalidArgumentException $exception) {
            $session->setFlashdata(
                'decision_error',
                lang(
                    $exception->getMessage()
                        === 'A confirmed identity authority check is required.'
                        ? 'Admin.authorityCheckRequired'
                        : 'Admin.decisionInvalid'
                )
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
                lang('Admin.decisionFailed')
            );

            return redirect()->to(
                '/admin/identites/' . rawurlencode($identityUuid)
            );
        }

        return redirect()->to(
            '/admin/identites/' . rawurlencode($identityUuid) . '?decision=ok'
        );
    }

    public function recordAuthorityCheck(string $identityUuid)
    {
        $context = $this->adminContext();
        $session = $context['session'];

        try {
            (new ManualIdentityAuthorityCheckService(
                $context['tenantContext']
            ))->record(
                $context['userId'],
                rawurldecode($identityUuid),
                (string) $this->request->getPost('outcome'),
                (string) $this->request->getPost('evidence_reference'),
                (string) $this->request->getPost('note')
            );
            $session->setFlashdata(
                'authority_check_saved',
                lang('Admin.authorityCheckSaved')
            );
        } catch (RuntimeException $exception) {
            if (str_starts_with($exception->getMessage(), 'Permission denied:')) {
                return $this->forbidden();
            }

            $session->setFlashdata(
                'authority_check_error',
                lang('Admin.authorityCheckFailed')
            );
        } catch (InvalidArgumentException) {
            $session->setFlashdata(
                'authority_check_error',
                lang('Admin.authorityCheckInvalid')
            );
        } catch (Throwable $exception) {
            log_message(
                'error',
                'Manual identity authority check failed: {type}',
                ['type' => $exception::class]
            );
            $session->setFlashdata(
                'authority_check_error',
                lang('Admin.authorityCheckFailed')
            );
        }

        return redirect()->to(
            '/admin/identites/' . rawurlencode($identityUuid)
            . '#authority-check'
        );
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
            ->setBody(lang('Admin.accessDenied'));
    }
}
