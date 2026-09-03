<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.securityHeading')) ?></h2>
        <p><?= esc(lang('Admin.securityLead')) ?></p>
    </div>
</section>

<?php if ($saved): ?>
    <p class="alert alert-ok" role="status"><?= esc(lang('Admin.passwordChanged')) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    <p class="alert" role="alert"><?= esc($errorMessage) ?></p>
<?php endif; ?>

<section class="panel narrow-panel">
    <form method="post" action="/admin/securite/mot-de-passe" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
            <label for="current-password"><?= esc(lang('Admin.currentPassword')) ?></label>
            <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="new-password"><?= esc(lang('Admin.newPassword')) ?></label>
            <input id="new-password" name="new_password" type="password" minlength="14" maxlength="256" autocomplete="new-password" required>
        </div>
        <div class="field">
            <label for="password-confirmation"><?= esc(lang('Admin.confirmPassword')) ?></label>
            <input id="password-confirmation" name="password_confirmation" type="password" minlength="14" maxlength="256" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn"><?= esc(lang('Admin.changePassword')) ?></button>
    </form>
</section>
<?= $this->endSection() ?>
