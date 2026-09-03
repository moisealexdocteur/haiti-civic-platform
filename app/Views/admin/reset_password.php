<?= $this->extend('layouts/auth') ?>

<?= $this->section('main') ?>
<section class="auth-card" aria-labelledby="reset-title">
    <p class="eyebrow"><?= esc(lang('Admin.resetTitle')) ?></p>
    <h1 id="reset-title"><?= esc(lang('Admin.resetHeading')) ?></h1>
    <?php if (! $usable): ?>
        <p class="alert" role="alert"><?= esc(lang('Admin.resetExpired')) ?></p>
        <a class="btn btn-ghost" href="/admin/password/forgot?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('Admin.requestAnotherLink')) ?></a>
    <?php else: ?>
        <p class="auth-lead"><?= esc(lang('Admin.resetLead')) ?></p>
        <?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
            <p class="alert" role="alert"><?= esc($errorMessage) ?></p>
        <?php endif; ?>
        <form method="post" action="/admin/password/reset">
            <?= csrf_field() ?>
            <input type="hidden" name="tenant" value="<?= esc($tenant, 'attr') ?>">
            <input type="hidden" name="request_uuid" value="<?= esc($requestUuid, 'attr') ?>">
            <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">
            <div class="field">
                <label for="password"><?= esc(lang('Admin.newPassword')) ?></label>
                <input id="password" name="password" type="password" minlength="14" maxlength="256" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="password-confirmation"><?= esc(lang('Admin.confirmPassword')) ?></label>
                <input id="password-confirmation" name="password_confirmation" type="password" minlength="14" maxlength="256" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn"><?= esc(lang('Admin.changePassword')) ?></button>
        </form>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
