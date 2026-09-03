<?php
$can = static fn (string $permission): bool => in_array($permission, $permissions ?? [], true);
$nav = [
    ['dashboard', '/admin', 'Admin.navDashboard', true],
    ['identities', '/admin/identites', 'Admin.navIdentities', $can('identity.view')],
    ['users', '/admin/utilisateurs', 'Admin.navUsers', $can('users.view')],
    ['roles', '/admin/roles', 'Admin.navRoles', $can('roles.view')],
    ['communications', '/admin/communications', 'Admin.navCommunications', $can('settings.view')],
    ['audit', '/admin/audit', 'Admin.navAudit', $can('audit.view')],
    ['security', '/admin/securite', 'Admin.navSecurity', true],
];
?>
<!doctype html>
<html lang="<?= esc($locale) ?>"<?= $theme === null ? '' : ' data-theme="' . esc($theme, 'attr') . '"' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#15398C" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0D1117" media="(prefers-color-scheme: dark)">
    <title><?= esc($pageTitle) ?> | <?= esc(lang('Admin.productName')) ?></title>
    <link rel="icon" href="<?= esc(versioned_asset('/assets/portal-mark.svg'), 'attr') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= esc(versioned_asset('/assets/tokens.css'), 'attr') ?>">
    <style data-admin-styles="inline"><?= inline_stylesheet('/assets/admin.css') ?></style>
</head>
<body class="admin-page">
<a class="skip-link" href="#main-content"><?= esc(lang('Admin.skipToContent')) ?></a>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a class="admin-brand" href="/admin" aria-label="<?= esc(lang('Admin.navDashboard'), 'attr') ?>">
            <img class="product-mark" src="<?= esc(versioned_asset('/assets/portal-mark.svg'), 'attr') ?>" alt="">
            <span>
                <b><?= esc(lang('Admin.productName')) ?></b>
                <small><?= esc(lang('Admin.adminArea')) ?></small>
            </span>
        </a>

        <nav class="admin-nav" aria-label="<?= esc(lang('Admin.navLabel'), 'attr') ?>">
            <?php foreach ($nav as [$key, $url, $label, $visible]): ?>
                <?php if (! $visible) { continue; } ?>
                <a href="<?= esc($url, 'attr') ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
                    <span class="nav-mark" aria-hidden="true"></span>
                    <?= esc(lang($label)) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-account">
            <b><?= esc($displayName) ?></b>
            <span><?= esc($tenantName) ?></span>
            <form method="post" action="/admin/logout">
                <?= csrf_field() ?>
                <button type="submit" class="text-button"><?= esc(lang('Admin.logout')) ?></button>
            </form>
        </div>
    </aside>

    <div class="admin-workspace">
        <header class="admin-top">
            <div>
                <p class="admin-kicker"><?= esc($tenantName) ?></p>
                <h1><?= esc($pageTitle) ?></h1>
            </div>
            <div class="admin-actions">
                <?= $this->renderSection('topActions') ?>
            </div>
        </header>

        <main id="main-content" class="admin-main" tabindex="-1">
            <?= $this->renderSection('main') ?>
        </main>

        <footer class="admin-footer">
            <div class="preference-group" aria-label="<?= esc(lang('Admin.languageLabel'), 'attr') ?>">
                <span><?= esc(lang('Admin.languageLabel')) ?></span>
                <a href="<?= esc($langUrls['ht'], 'attr') ?>"<?= $locale === 'ht' ? ' aria-current="true"' : '' ?>><?= esc(lang('Admin.languageHt')) ?></a>
                <a href="<?= esc($langUrls['fr'], 'attr') ?>"<?= $locale === 'fr' ? ' aria-current="true"' : '' ?>><?= esc(lang('Admin.languageFr')) ?></a>
            </div>
            <div class="preference-group" aria-label="<?= esc(lang('Admin.themeLabel'), 'attr') ?>">
                <span><?= esc(lang('Admin.themeLabel')) ?></span>
                <a href="<?= esc($themeUrls['auto'], 'attr') ?>"><?= esc(lang('Admin.themeAuto')) ?></a>
                <a href="<?= esc($themeUrls['light'], 'attr') ?>"><?= esc(lang('Admin.themeLight')) ?></a>
                <a href="<?= esc($themeUrls['dark'], 'attr') ?>"><?= esc(lang('Admin.themeDark')) ?></a>
            </div>
        </footer>
    </div>
</div>
</body>
</html>
