<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<?php
$labels = [
    'all' => lang('Admin.statusAll'),
    'pending' => lang('Admin.statusPending'),
    'verified' => lang('Admin.statusVerified'),
    'rejected' => lang('Admin.statusRejected'),
];
$contactLabels = [
    'otp_verified' => lang('Admin.contactOtpVerified'),
    'manual_review' => lang('Admin.contactManualReview'),
];
$rows = $listing['rows'];
$status = $listing['status'];
$department = $listing['department'];
$sort = $listing['sort'];
$direction = $listing['direction'];
$query = [
    'status' => $status,
    'department' => $department,
    'sort' => $sort,
    'direction' => $direction,
    'per_page' => $listing['perPage'],
    'page' => $listing['page'],
];
$url = static function (array $changes = []) use ($query): string {
    $values = array_merge($query, $changes);
    $values = array_filter($values, static fn ($value): bool => $value !== null && $value !== '');

    return '/admin/identites?' . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
};
$exportQuery = $query;
unset($exportQuery['page'], $exportQuery['per_page']);
$exportSuffix = http_build_query(
    array_filter($exportQuery, static fn ($value): bool => $value !== null && $value !== ''),
    '',
    '&',
    PHP_QUERY_RFC3986
);
$sortDirection = static fn (string $field): string => $sort === $field && $direction === 'asc' ? 'desc' : 'asc';
$sortState = static fn (string $field): string => $sort === $field ? 'is-' . $direction : 'is-none';
?>

<?php if (is_string($confirmationSent) && $confirmationSent !== ''): ?>
    <p class="alert alert-ok" role="status"><?= esc($confirmationSent) ?></p>
<?php endif; ?>

<?php if (is_string($confirmationError) && $confirmationError !== ''): ?>
    <p class="alert" role="alert"><?= esc($confirmationError) ?></p>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading-row">
        <div>
            <h2><?= esc(lang('Admin.queueTitle')) ?></h2>
            <p class="panel-note">
                <?= esc(lang('Admin.queueHelp')) ?>
                <?php if ($canManage): ?> <?= esc(lang('Admin.canDecide')) ?><?php endif; ?>
            </p>
        </div>
        <div class="export-actions" aria-label="<?= esc(lang('Admin.exportActions'), 'attr') ?>">
            <a class="btn btn-ghost btn-compact" href="/admin/identites/export/pdf?<?= esc($exportSuffix, 'attr') ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 2h7l5 5v15H7zM14 2v6h6M9 14h6M9 18h4"/></svg>
                <?= esc(lang('Admin.exportPdf')) ?>
            </a>
            <a class="btn btn-ghost btn-compact" href="/admin/identites/export/xls?<?= esc($exportSuffix, 'attr') ?>">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 2h10v20H7zM10 9l4 6M14 9l-4 6M4 5h3M4 19h3"/></svg>
                <?= esc(lang('Admin.exportXls')) ?>
            </a>
        </div>
    </div>

    <form method="get" action="/admin/identites" class="filters">
        <div class="field">
            <label for="status"><?= esc(lang('Admin.status')) ?></label>
            <select id="status" name="status">
                <?php foreach ($labels as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>" <?= $status === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="department"><?= esc(lang('Admin.department')) ?></label>
            <select id="department" name="department">
                <option value=""><?= esc(lang('Admin.allDepartments')) ?></option>
                <?php foreach ($departments as $code => $name): ?>
                    <option value="<?= esc($code, 'attr') ?>" <?= $department === $code ? 'selected' : '' ?>><?= esc($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field field-small">
            <label for="per_page"><?= esc(lang('Admin.rowsPerPage')) ?></label>
            <select id="per_page" name="per_page">
                <?php foreach ([25, 50, 100] as $size): ?>
                    <option value="<?= $size ?>" <?= $listing['perPage'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?= esc($sort, 'attr') ?>">
        <input type="hidden" name="direction" value="<?= esc($direction, 'attr') ?>">
        <button type="submit" class="btn"><?= esc(lang('Admin.filter')) ?></button>
    </form>

    <p class="results-count"><?= esc(lang('Admin.resultCount', [$listing['total']])) ?></p>

    <?php if ($rows === []): ?>
        <p class="empty"><?= esc(lang('Admin.noFiles')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="queue">
                <thead>
                <tr>
                    <th><a class="sort-link" href="<?= esc($url(['sort' => 'reference', 'direction' => $sortDirection('reference'), 'page' => 1]), 'attr') ?>"><?= esc(lang('Admin.reference')) ?> <span class="sort-mark <?= $sortState('reference') ?>" aria-hidden="true"></span></a></th>
                    <th><?= esc(lang('Admin.status')) ?></th>
                    <th><?= esc(lang('Admin.contactVerification')) ?></th>
                    <th><a class="sort-link" href="<?= esc($url(['sort' => 'department', 'direction' => $sortDirection('department'), 'page' => 1]), 'attr') ?>"><?= esc(lang('Admin.department')) ?> <span class="sort-mark <?= $sortState('department') ?>" aria-hidden="true"></span></a></th>
                    <th><?= esc(lang('Admin.documents')) ?></th>
                    <th><a class="sort-link" href="<?= esc($url(['sort' => 'submitted', 'direction' => $sortDirection('submitted'), 'page' => 1]), 'attr') ?>"><?= esc(lang('Admin.submittedAt')) ?> <span class="sort-mark <?= $sortState('submitted') ?>" aria-hidden="true"></span></a></th>
                    <th><span class="sr-only"><?= esc(lang('Admin.actions')) ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $rowStatus = (string) $row['verification_status']; ?>
                    <?php $rowUuid = rawurlencode((string) $row['uuid']); ?>
                    <tr>
                        <td class="ref"><?= esc((string) $row['public_reference']) ?></td>
                        <td><span class="pill pill-<?= esc($rowStatus, 'attr') ?>"><?= esc($labels[$rowStatus] ?? $rowStatus) ?></span></td>
                        <?php $contactStatus = (string) $row['contact_verification_status']; ?>
                        <td><?= esc($contactLabels[$contactStatus] ?? $contactStatus) ?></td>
                        <td><?= esc($departments[(string) ($row['department_code'] ?? '')] ?? lang('Admin.notProvided')) ?></td>
                        <td class="num"><?= esc((string) $row['document_count']) ?> / 3</td>
                        <td><?= esc((string) $row['created_at']) ?> UTC</td>
                        <td>
                            <div class="row-actions">
                                <a class="icon-action" href="/admin/identites/<?= $rowUuid ?>" aria-label="<?= esc(lang('Admin.open'), 'attr') ?>"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 12s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6zM12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/></svg></a>
                                <a class="icon-action" href="/admin/identites/<?= $rowUuid ?>/confirmation" target="_blank" rel="noopener" aria-label="<?= esc(lang('Admin.printConfirmation'), 'attr') ?>"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M7 8V3h10v5M7 17H4V9h16v8h-3M7 14h10v7H7z"/></svg></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav class="pager" aria-label="<?= esc(lang('Admin.pagination'), 'attr') ?>">
            <span><?= esc(lang('Admin.pageOf', [$listing['page'], $listing['pages']])) ?></span>
            <div>
                <?php if ($listing['page'] > 1): ?><a class="btn btn-ghost btn-compact" href="<?= esc($url(['page' => $listing['page'] - 1]), 'attr') ?>"><?= esc(lang('Admin.previous')) ?></a><?php endif; ?>
                <?php if ($listing['page'] < $listing['pages']): ?><a class="btn btn-ghost btn-compact" href="<?= esc($url(['page' => $listing['page'] + 1]), 'attr') ?>"><?= esc(lang('Admin.next')) ?></a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
