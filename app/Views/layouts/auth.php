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
    <link rel="icon" href="<?= esc(versioned_asset('/assets/portal-mark.svg'), 'attr') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= esc(versioned_asset('/assets/tokens.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(versioned_asset('/assets/admin.css'), 'attr') ?>">
    <style>
        .auth-page .auth-shell {
            display: grid;
            align-content: center;
            width: calc(100% - 32px);
            max-width: 470px;
            min-height: 100vh;
            min-height: 100svh;
            margin: 0 auto;
            padding: 40px 0 28px;
        }

        .auth-page .auth-brand {
            display: flex;
            align-items: center;
            gap: 11px;
            margin: 0 0 24px 4px;
            color: var(--ink, #12161C);
            text-decoration: none;
        }

        .auth-page .auth-brand > span:last-child {
            display: grid;
            line-height: 1.2;
        }

        .auth-page .auth-brand small {
            color: var(--ink-3, #5F6B7C);
            font-size: var(--t-xs, .8125rem);
        }

        .auth-page .admin-seal {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 50%;
            background: var(--flag, #00209F);
            color: var(--accent-on, #FFFFFF);
            font-weight: 800;
            letter-spacing: .04em;
        }

        .auth-page .auth-card {
            padding: 28px;
            border: 1px solid var(--line, #D6DAE2);
            border-radius: var(--radius-lg, 10px);
            background: var(--surface, #FAFBFD);
            box-shadow: var(--shadow, 0 1px 2px rgba(18, 22, 28, .06));
        }

        .auth-page .auth-card h1 { margin-bottom: 8px; }

        .auth-page .auth-lead {
            margin-bottom: 24px;
            color: var(--ink-2, #39424F);
        }

        .auth-page .eyebrow {
            margin-bottom: 7px;
            color: var(--accent, #15398C);
            font-size: var(--t-xs, .8125rem);
            font-weight: 750;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .auth-page .auth-secondary {
            display: block;
            margin-top: 18px;
            text-align: center;
            font-size: var(--t-sm, .875rem);
        }

        .auth-page .auth-footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin-top: 10px;
            padding: 18px 0 28px;
            color: var(--ink-3, #5F6B7C);
            font-size: var(--t-xs, .8125rem);
        }

        .auth-page .preference-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .auth-page .preference-group a[aria-current="true"] {
            color: var(--ink, #12161C);
            font-weight: 700;
            text-decoration: none;
        }

        @media (max-width: 620px) {
            .auth-page .auth-shell {
                width: calc(100% - 24px);
                padding-top: 24px;
            }

            .auth-page .auth-card { padding: 22px 18px; }
        }

        @media (max-height: 700px) {
            .auth-page .auth-shell { align-content: start; }
        }
    </style>
</head>
<body class="auth-page">
<main class="auth-shell">
    <header class="auth-brand">
        <img class="product-mark" src="<?= esc(versioned_asset('/assets/portal-mark.svg'), 'attr') ?>" alt="">
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
