<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<?php
$eventLabels = [
    'admin.bootstrap_created' => lang('Admin.eventAdminBootstrap'),
    'admin.user_created' => lang('Admin.eventUserCreated'),
    'admin.password_changed' => lang('Admin.eventPasswordChanged'),
    'admin.password_reset_requested' => lang('Admin.eventResetRequested'),
    'admin.password_reset_completed' => lang('Admin.eventResetCompleted'),
    'admin.password_reset_delivery_failed' => lang('Admin.eventResetFailed'),
    'settings.communication_updated' => lang('Admin.eventSettingsUpdated'),
    'role.created' => lang('Admin.eventRoleCreated'),
    'role.updated' => lang('Admin.eventRoleUpdated'),
    'role.permissions_changed' => lang('Admin.eventRolePermissions'),
    'tenant_user.status_changed' => lang('Admin.eventUserStatus'),
    'identity.public_submitted' => lang('Admin.eventIdentitySubmitted'),
    'identity.verified' => lang('Admin.eventIdentityVerified'),
    'identity.rejected' => lang('Admin.eventIdentityRejected'),
    'identity.reopened' => lang('Admin.eventIdentityReopened'),
];
?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.auditHeading')) ?></h2>
        <p><?= esc(lang('Admin.auditLead')) ?></p>
    </div>
</section>

<section class="panel">
    <?php if ($entries === []): ?>
        <p class="empty"><?= esc(lang('Admin.noAuditEntries')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="queue audit-table">
                <thead>
                <tr>
                    <th><?= esc(lang('Admin.date')) ?></th>
                    <th><?= esc(lang('Admin.event')) ?></th>
                    <th><?= esc(lang('Admin.actor')) ?></th>
                    <th><?= esc(lang('Admin.reference')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= esc((string) $entry['occurred_at']) ?> UTC</td>
                        <td><?= esc($eventLabels[(string) $entry['event']] ?? lang('Admin.eventOther')) ?></td>
                        <td><?= esc((string) ($entry['display_name'] ?? lang('Admin.system'))) ?></td>
                        <td class="ref"><?= esc((string) ($entry['entity_id'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
