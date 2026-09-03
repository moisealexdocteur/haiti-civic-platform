<?php
$permissionLabels = [
    'audit.view' => lang('Admin.permissionAuditView'),
    'identity.manage' => lang('Admin.permissionIdentityManage'),
    'identity.view' => lang('Admin.permissionIdentityView'),
    'roles.manage' => lang('Admin.permissionRolesManage'),
    'roles.view' => lang('Admin.permissionRolesView'),
    'settings.manage' => lang('Admin.permissionSettingsManage'),
    'settings.view' => lang('Admin.permissionSettingsView'),
    'users.manage' => lang('Admin.permissionUsersManage'),
    'users.view' => lang('Admin.permissionUsersView'),
];
?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.rolesHeading')) ?></h2>
        <p><?= esc(lang('Admin.rolesLead')) ?></p>
    </div>
</section>

<?php if ($saved): ?>
    <p class="alert alert-ok" role="status"><?= esc(lang('Admin.roleSaved')) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    <p class="alert" role="alert"><?= esc($errorMessage) ?></p>
<?php endif; ?>

<?php if ($canManage): ?>
    <details class="panel disclosure-panel">
        <summary><?= esc(lang('Admin.addRole')) ?></summary>
        <form method="post" action="/admin/roles" class="role-form">
            <?= csrf_field() ?>
            <div class="form-grid form-grid-spaced">
                <div class="field">
                    <label for="role-name-new"><?= esc(lang('Admin.roleName')) ?></label>
                    <input id="role-name-new" name="name" maxlength="160" required>
                </div>
                <div class="field">
                    <label for="role-description-new"><?= esc(lang('Admin.roleDescription')) ?></label>
                    <input id="role-description-new" name="description" maxlength="500">
                </div>
            </div>
            <fieldset class="permission-fieldset">
                <legend><?= esc(lang('Admin.permissionsTitle')) ?></legend>
                <div class="permission-grid">
                    <?php foreach ($permissionCatalog as $permission): ?>
                        <?php $code = (string) $permission['code']; ?>
                        <label class="permission-choice">
                            <input type="checkbox" name="permission_codes[]" value="<?= esc($code, 'attr') ?>">
                            <span><b><?= esc((string) ($permissionLabels[$code] ?? $code)) ?></b><small><?= esc($code) ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <button type="submit" class="btn btn-fit"><?= esc(lang('Admin.createRole')) ?></button>
        </form>
    </details>
<?php endif; ?>

<div class="role-grid">
    <?php foreach ($roles as $role): ?>
        <?php $editable = $canManage && (bool) $role['mutable']; ?>
        <section class="panel role-card">
            <form method="post" action="/admin/roles/<?= rawurlencode((string) $role['uuid']) ?>">
                <?= csrf_field() ?>
                <header class="role-card-head">
                    <div>
                        <input class="role-name-input" name="name" value="<?= esc((string) $role['name'], 'attr') ?>" maxlength="160" aria-label="<?= esc(lang('Admin.roleName'), 'attr') ?>" <?= $editable ? '' : 'readonly' ?>>
                        <code><?= esc((string) $role['code']) ?></code>
                    </div>
                    <?php if (! $role['mutable']): ?><span class="pill pill-owner"><?= esc(lang('Admin.protectedRole')) ?></span><?php endif; ?>
                </header>
                <div class="field">
                    <label for="role-description-<?= esc((string) $role['id'], 'attr') ?>"><?= esc(lang('Admin.roleDescription')) ?></label>
                    <textarea id="role-description-<?= esc((string) $role['id'], 'attr') ?>" name="description" maxlength="500" rows="2" <?= $editable ? '' : 'readonly' ?>><?= esc((string) ($role['description'] ?? '')) ?></textarea>
                </div>
                <fieldset class="permission-fieldset">
                    <legend><?= esc(lang('Admin.permissionsTitle')) ?></legend>
                    <div class="permission-grid">
                        <?php foreach ($permissionCatalog as $permission): ?>
                            <?php $code = (string) $permission['code']; ?>
                            <label class="permission-choice">
                                <input type="checkbox" name="permission_codes[]" value="<?= esc($code, 'attr') ?>" <?= in_array($code, $role['permission_codes'], true) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                                <span><b><?= esc((string) ($permissionLabels[$code] ?? $code)) ?></b><small><?= esc($code) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php if ($editable): ?>
                    <button type="submit" class="btn btn-fit"><?= esc(lang('Admin.saveRole')) ?></button>
                <?php endif; ?>
            </form>
        </section>
    <?php endforeach; ?>
</div>
<?= $this->endSection() ?>
