<?php

$context = require __DIR__ . DIRECTORY_SEPARATOR . '_localized_context.php';
$locale = $context['locale'];
$path = $context['path'];
$isAdmin = $context['isAdmin'];
$title = lang($titleKey);
$description = isset($technicalMessage) && ENVIRONMENT !== 'production'
    ? (string) $technicalMessage
    : lang($messageKey);
$homePath = $isAdmin ? '/admin/login' : '/';
$homeLabel = $isAdmin ? lang('ErrorPage.backAdmin') : lang('ErrorPage.backHome');
?>
<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#15398C" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0D1117" media="(prefers-color-scheme: dark)">
    <title><?= esc($title) ?> | <?= esc(lang('ErrorPage.productName')) ?></title>
    <link rel="icon" href="/assets/portal-mark.svg" type="image/svg+xml">
    <style>
        :root{color-scheme:light dark;--blue:#15398c;--ink:#172033;--muted:#5d6b82;--line:#d2d9e5;--surface:#fff;--background:#f2f5fa}*{box-sizing:border-box}body{min-height:100vh;margin:0;background:var(--background);color:var(--ink);font:16px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.error-shell{width:min(100% - 32px,680px);margin-inline:auto;min-height:100vh;display:grid;grid-template-rows:auto 1fr auto}.error-top{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:24px 0;border-bottom:1px solid var(--line)}.error-brand{display:flex;align-items:center;gap:12px;font-weight:750}.error-brand img{width:34px;height:34px}.error-language{display:flex;border:1px solid #8b98ad;border-radius:5px;overflow:hidden}.error-language a{padding:8px 12px;color:var(--muted);text-decoration:none;font-size:14px;font-weight:650}.error-language a[aria-current=true]{background:var(--ink);color:#fff}.error-main{display:grid;place-items:center;padding:56px 0}.error-card{width:100%;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:clamp(28px,6vw,48px);box-shadow:0 14px 32px rgba(23,32,51,.08)}.error-code{margin:0 0 12px;color:var(--blue);font-size:14px;font-weight:800;letter-spacing:.12em}.error-card h1{max-width:560px;margin:0;font-size:clamp(28px,6vw,42px);line-height:1.12;letter-spacing:-.025em}.error-card p{margin:18px 0 0;color:var(--muted);font-size:17px}.error-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:30px}.error-actions a{display:inline-flex;min-height:48px;align-items:center;justify-content:center;border:1px solid var(--blue);border-radius:5px;padding:10px 20px;background:var(--blue);color:#fff;text-decoration:none;font-weight:750}.error-actions a.secondary{background:transparent;color:var(--blue)}.error-foot{padding:22px 0 28px;color:var(--muted);font-size:13px;text-align:center}@media(prefers-color-scheme:dark){:root{--blue:#8daaf0;--ink:#f5f7fb;--muted:#b3bfd2;--line:#354158;--surface:#171d28;--background:#0d1117}.error-card{box-shadow:none}.error-actions a{background:#244b9b;color:#fff;border-color:#7599e8}.error-actions a.secondary{background:transparent;color:#b7cbff}}@media(max-width:540px){.error-top{align-items:flex-start}.error-brand span{max-width:170px}.error-language a{padding:7px 9px}.error-actions{display:grid}.error-actions a{width:100%}}
    </style>
</head>
<body>
<div class="error-shell">
    <header class="error-top">
        <div class="error-brand">
            <img src="/assets/portal-mark.svg" alt="">
            <span><?= esc(lang('ErrorPage.productName')) ?></span>
        </div>
        <nav class="error-language" aria-label="<?= esc(lang('ErrorPage.languageLabel'), 'attr') ?>">
            <a href="<?= $path ?>?lang=ht" hreflang="ht"<?= $locale === 'ht' ? ' aria-current="true"' : '' ?>><?= esc(lang('ErrorPage.languageHt')) ?></a>
            <a href="<?= $path ?>?lang=fr" hreflang="fr"<?= $locale === 'fr' ? ' aria-current="true"' : '' ?>><?= esc(lang('ErrorPage.languageFr')) ?></a>
        </nav>
    </header>
    <main class="error-main">
        <section class="error-card" aria-labelledby="error-title">
            <p class="error-code"><?= esc((string) $errorCode) ?></p>
            <h1 id="error-title"><?= esc($title) ?></h1>
            <p><?= nl2br(esc($description)) ?></p>
            <div class="error-actions">
                <a href="<?= esc($homePath, 'attr') ?>?lang=<?= esc($locale, 'attr') ?>"><?= esc($homeLabel) ?></a>
                <?php if ($allowRetry): ?>
                    <a class="secondary" href="<?= $path ?>?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('ErrorPage.retry')) ?></a>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <footer class="error-foot"><?= esc(lang('ErrorPage.productName')) ?></footer>
</div>
</body>
</html>
