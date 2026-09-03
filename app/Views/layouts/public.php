<?php

/**
 * Gabarit unique du portail citoyen.
 *
 * @var string      $locale
 * @var string      $pageTitle
 * @var string|null $theme
 * @var array|null  $langUrls
 * @var string|null $brandName
 * @var string|null $brandInitials
 */

$theme = in_array($theme ?? '', ['light', 'dark'], true) ? $theme : null;
$langUrls = $langUrls ?? ['fr' => '/?lang=fr', 'ht' => '/?lang=ht'];
?>
<!doctype html>
<html lang="<?= esc($locale) ?>"<?= $theme === null ? '' : ' data-theme="' . esc($theme, 'attr') . '"' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#15398C" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0D1117" media="(prefers-color-scheme: dark)">
    <title><?= esc($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/tokens.css">
    <link rel="stylesheet" href="/assets/portal.css">
    <?= $this->renderSection('head') ?>
</head>
<body>
<div class="shell<?= ($wide ?? false) ? ' shell-wide' : '' ?>">
    <header class="topbar">
        <p class="brand">
            <span class="seal" aria-hidden="true"><?= esc($brandInitials ?? 'PS') ?></span>
            <span><?= esc($brandName ?? lang('CitizenPortal.brand')) ?></span>
        </p>
        <nav class="langswitch" aria-label="<?= esc(lang('CitizenPortal.languageSwitch')) ?>">
            <a href="<?= esc($langUrls['ht'], 'attr') ?>" hreflang="ht"<?= $locale === 'ht' ? ' aria-current="true"' : '' ?>><?= esc(lang('CitizenPortal.languageHt')) ?></a>
            <a href="<?= esc($langUrls['fr'], 'attr') ?>" hreflang="fr"<?= $locale === 'fr' ? ' aria-current="true"' : '' ?>><?= esc(lang('CitizenPortal.languageFr')) ?></a>
        </nav>
    </header>

    <?= $this->renderSection('main') ?>

    <footer class="foot">
        <p><?= esc(lang('CitizenPortal.securityFootnote')) ?></p>
        <?php if (isset($themeUrls)): ?>
            <nav class="themepick" aria-label="<?= esc(lang('CitizenPortal.themeLabel')) ?>">
                <a href="<?= esc($themeUrls['auto'], 'attr') ?>"<?= $theme === null ? ' aria-current="true"' : '' ?>><?= esc(lang('CitizenPortal.themeAuto')) ?></a>
                <a href="<?= esc($themeUrls['light'], 'attr') ?>"<?= $theme === 'light' ? ' aria-current="true"' : '' ?>><?= esc(lang('CitizenPortal.themeLight')) ?></a>
                <a href="<?= esc($themeUrls['dark'], 'attr') ?>"<?= $theme === 'dark' ? ' aria-current="true"' : '' ?>><?= esc(lang('CitizenPortal.themeDark')) ?></a>
            </nav>
        <?php endif; ?>
    </footer>
</div>
<?= $this->renderSection('scripts') ?>
</body>
</html>
