<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.dashboardHeading')) ?></h2>
        <p><?= esc(lang('Admin.dashboardLead')) ?></p>
    </div>
</section>

<?php if (in_array('identity.view', $summary['permissions'], true) || $summary['members'] !== null): ?>
    <div class="metric-grid">
        <?php if (in_array('identity.view', $summary['permissions'], true)): ?>
            <a class="metric" href="/admin/identites?status=pending">
                <span><?= esc(lang('Admin.pendingFiles')) ?></span>
                <strong><?= esc((string) $summary['identities']['pending']) ?></strong>
            </a>
            <a class="metric" href="/admin/identites?status=verified">
                <span><?= esc(lang('Admin.verifiedFiles')) ?></span>
                <strong><?= esc((string) $summary['identities']['verified']) ?></strong>
            </a>
            <a class="metric" href="/admin/identites?status=rejected">
                <span><?= esc(lang('Admin.rejectedFiles')) ?></span>
                <strong><?= esc((string) $summary['identities']['rejected']) ?></strong>
            </a>
        <?php endif; ?>
        <?php if ($summary['members'] !== null): ?>
            <a class="metric" href="/admin/utilisateurs">
                <span><?= esc(lang('Admin.activeMembers')) ?></span>
                <strong><?= esc((string) $summary['members']) ?></strong>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid-two">
    <?php if ($summary['communications'] !== null): ?>
        <section class="panel">
            <h2><?= esc(lang('Admin.communicationHealth')) ?></h2>
            <p class="panel-note"><?= esc(lang('Admin.communicationHealthHelp')) ?></p>
            <ul class="channel-list">
                <?php foreach ([
                    'whatsapp' => 'WhatsApp',
                    'sms' => 'SMS',
                    'email' => lang('Admin.email'),
                ] as $key => $label): ?>
                    <li>
                        <span><?= esc($label) ?></span>
                        <span class="pill <?= $summary['communications'][$key] ? 'pill-enabled' : 'pill-disabled' ?>">
                            <?= esc(lang($summary['communications'][$key] ? 'Admin.enabled' : 'Admin.disabled')) ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (in_array('settings.manage', $summary['permissions'], true)): ?>
                <a class="btn btn-ghost" href="/admin/communications"><?= esc(lang('Admin.configureChannels')) ?></a>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2><?= esc(lang('Admin.quickActions')) ?></h2>
        <div class="action-list">
            <?php if (in_array('identity.view', $summary['permissions'], true)): ?>
                <a href="/admin/identites?status=pending"><?= esc(lang('Admin.openQueue')) ?></a>
            <?php endif; ?>
            <?php if (in_array('users.view', $summary['permissions'], true)): ?>
                <a href="/admin/utilisateurs"><?= esc(lang('Admin.navUsers')) ?></a>
            <?php endif; ?>
            <?php if (in_array('roles.view', $summary['permissions'], true)): ?>
                <a href="/admin/roles"><?= esc(lang('Admin.navRoles')) ?></a>
            <?php endif; ?>
            <a href="/admin/securite"><?= esc(lang('Admin.navSecurity')) ?></a>
        </div>
    </section>
</div>
<?= $this->endSection() ?>
