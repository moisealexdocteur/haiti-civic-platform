<?= $this->extend('layouts/auth') ?>

<?= $this->section('main') ?>
<section class="auth-card" aria-labelledby="forgot-title">
    <p class="eyebrow"><?= esc(lang('Admin.forgotTitle')) ?></p>
    <h1 id="forgot-title"><?= esc(lang('Admin.forgotHeading')) ?></h1>
    <p class="auth-lead"><?= esc(lang('Admin.forgotLead')) ?></p>
    <?php if ($sent): ?>
        <p class="alert alert-ok" role="status"><?= esc(lang('Admin.resetRequested')) ?></p>
    <?php else: ?>
        <form method="post" action="/admin/password/forgot" autocomplete="on">
            <?= csrf_field() ?>
            <div class="field">
                <label for="tenant"><?= esc(lang('Admin.organizationCode')) ?></label>
                <input id="tenant" name="tenant" type="text" maxlength="80" autocomplete="organization" autocapitalize="off" spellcheck="false" required>
            </div>
            <div class="field">
                <label for="email"><?= esc(lang('Admin.email')) ?></label>
                <input id="email" name="email" type="email" maxlength="191" autocomplete="email" required>
            </div>
            <button type="submit" class="btn"><?= esc(lang('Admin.sendResetLink')) ?></button>
        </form>
    <?php endif; ?>
    <a class="auth-secondary" href="/admin/login?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('Admin.backToLogin')) ?></a>
</section>
<?= $this->endSection() ?>
