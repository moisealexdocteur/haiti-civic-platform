<!doctype html>
<html lang="<?= esc($locale) ?>"<?= $theme === null ? '' : ' data-theme="' . esc($theme, 'attr') . '"' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#15398C" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0D1117" media="(prefers-color-scheme: dark)">
    <title><?= esc($pageTitle) ?> | <?= esc(lang('Admin.productName')) ?></title>
    <link rel="stylesheet" href="<?= esc(versioned_asset('/assets/tokens.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(versioned_asset('/assets/admin.css'), 'attr') ?>">
</head>
<body>
<main class="auth-shell">
    <header class="auth-brand">
        <span class="admin-seal" aria-hidden="true">BS</span>
        <span><b><?= esc(lang('Admin.productName')) ?></b><small><?= esc(lang('Admin.adminArea')) ?></small></span>
    </header>
    <?= $this->renderSection('main') ?>
    <?php if (empty($hidePreferences)): ?>
    <footer class="auth-footer">
        <div class="preference-group" aria-label="<?= esc(lang('Admin.languageLabel'), 'attr') ?>">
            <a href="<?= esc($langUrls['ht'], 'attr') ?>"<?= $locale === 'ht' ? ' aria-current="true"' : '' ?>><?= esc(lang('Admin.languageHt')) ?></a>
            <a href="<?= esc($langUrls['fr'], 'attr') ?>"<?= $locale === 'fr' ? ' aria-current="true"' : '' ?>><?= esc(lang('Admin.languageFr')) ?></a>
        </div>
        <div class="preference-group" aria-label="<?= esc(lang('Admin.themeLabel'), 'attr') ?>">
            <a href="<?= esc($themeUrls['auto'], 'attr') ?>"><?= esc(lang('Admin.themeAuto')) ?></a>
            <a href="<?= esc($themeUrls['light'], 'attr') ?>"><?= esc(lang('Admin.themeLight')) ?></a>
            <a href="<?= esc($themeUrls['dark'], 'attr') ?>"><?= esc(lang('Admin.themeDark')) ?></a>
        </div>
    </footer>
    <?php endif; ?>
</main>
</body>
</html>
