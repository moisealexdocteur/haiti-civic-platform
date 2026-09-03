<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<?php
$labels = [
    'pending' => lang('Admin.statusPending'),
    'verified' => lang('Admin.statusVerified'),
    'rejected' => lang('Admin.statusRejected'),
];
?>
<section class="panel">
    <h2><?= esc(lang('Admin.queueTitle')) ?></h2>
    <p class="panel-note">
        <?= esc(lang('Admin.queueHelp')) ?>
        <?php if ($canManage): ?> <?= esc(lang('Admin.canDecide')) ?><?php endif; ?>
    </p>

    <form method="get" action="/admin/identites" class="filters">
        <div class="field">
            <label for="status"><?= esc(lang('Admin.status')) ?></label>
            <select id="status" name="status">
                <?php foreach ($labels as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>" <?= $status === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn"><?= esc(lang('Admin.filter')) ?></button>
    </form>

    <?php if ($rows === []): ?>
        <p class="empty"><?= esc(lang('Admin.noFiles')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="queue">
                <thead>
                <tr>
                    <th><?= esc(lang('Admin.reference')) ?></th>
                    <th><?= esc(lang('Admin.status')) ?></th>
                    <th><?= esc(lang('Admin.documents')) ?></th>
                    <th><?= esc(lang('Admin.submittedAt')) ?></th>
                    <th><span class="sr-only">Action</span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $rowStatus = (string) $row['verification_status']; ?>
                    <tr>
                        <td class="ref"><?= esc(strtoupper(substr((string) $row['uuid'], 0, 8))) ?></td>
                        <td><span class="pill pill-<?= esc($rowStatus, 'attr') ?>"><?= esc($labels[$rowStatus] ?? $rowStatus) ?></span></td>
                        <td class="num"><?= esc((string) $row['document_count']) ?> / 3</td>
                        <td><?= esc((string) $row['created_at']) ?> UTC</td>
                        <td><a href="/admin/identites/<?= rawurlencode((string) $row['uuid']) ?>"><?= esc(lang('Admin.open')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
