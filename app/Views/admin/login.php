<?= $this->extend('layouts/auth') ?>

<?= $this->section('main') ?>
<section class="auth-card" aria-labelledby="login-title">
    <p class="eyebrow"><?= esc(lang('Admin.loginEyebrow')) ?></p>
    <h1 id="login-title"><?= esc(lang('Admin.loginHeading')) ?></h1>
    <p class="auth-lead"><?= esc(lang('Admin.loginLead')) ?></p>

    <?php if ($resetCompleted): ?>
        <p class="alert alert-ok" role="status"><?= esc(lang('Admin.resetCompleted')) ?></p>
    <?php endif; ?>
    <?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
        <p class="alert" role="alert"><?= esc(lang('Admin.invalidCredentials')) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/login" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
            <label for="tenant"><?= esc(lang('Admin.organizationCode')) ?></label>
            <input id="tenant" name="tenant" type="text" maxlength="80" value="<?= esc($tenantValue, 'attr') ?>" autocomplete="organization" autocapitalize="off" spellcheck="false" required>
            <p class="hint"><?= esc(lang('Admin.organizationHelp')) ?></p>
        </div>
        <div class="field">
            <label for="email"><?= esc(lang('Admin.email')) ?></label>
            <input id="email" name="email" type="email" maxlength="191" value="<?= esc($emailValue, 'attr') ?>" autocomplete="username" required>
        </div>
        <div class="field">
            <label for="password"><?= esc(lang('Admin.password')) ?></label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn"><?= esc(lang('Admin.signIn')) ?></button>
    </form>
    <a class="auth-secondary" href="/admin/password/forgot?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('Admin.forgotPassword')) ?></a>
</section>
<?= $this->endSection() ?>
