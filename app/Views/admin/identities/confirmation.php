<!doctype html>
<html lang="<?= esc($locale, 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= esc(lang('Admin.confirmationDocumentTitle')) ?></title>
    <link rel="icon" href="<?= esc(versioned_asset('/assets/portal-mark.svg'), 'attr') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= esc(versioned_asset('/assets/tokens.css'), 'attr') ?>">
    <style>
        *{box-sizing:border-box}body{margin:0;background:#eef1f7;color:#171b23;font-family:Arial,sans-serif}.sheet{width:min(720px,calc(100% - 32px));margin:32px auto;padding:40px;background:#fff;border:1px solid #d5dbe6;border-radius:12px}.brand{display:flex;align-items:center;gap:12px;padding-bottom:24px;border-bottom:1px solid #d5dbe6}.brand img{width:42px;height:42px}.brand span{display:grid}.brand small{color:#647087}.main{text-align:center;padding:42px 0 24px}.main h1{font-size:26px}.reference{font:700 24px ui-monospace,monospace;letter-spacing:.06em;padding:18px;border:1px dashed #8090aa;border-radius:8px}.qr{display:grid;grid-template-columns:150px 1fr;gap:24px;align-items:center;margin:28px 0;text-align:left}.qr-code{width:144px;height:144px}.tracking{overflow-wrap:anywhere}.meta{color:#647087;font-size:14px}.actions{display:flex;justify-content:center;gap:10px;margin-top:28px}.actions button{min-height:44px;padding:0 20px;border:0;border-radius:6px;background:#15398c;color:#fff;font-weight:700;cursor:pointer}@media(max-width:560px){.sheet{margin:0;width:100%;min-height:100vh;border:0;border-radius:0;padding:24px}.qr{grid-template-columns:1fr;text-align:center}.qr-code{margin:auto}}@media print{body{background:#fff}.sheet{width:100%;margin:0;border:0;box-shadow:none}.actions{display:none}}
    </style>
</head>
<body>
<main class="sheet">
    <header class="brand">
        <img src="<?= esc(versioned_asset('/assets/portal-mark.svg'), 'attr') ?>" alt="">
        <span><b><?= esc(lang('Admin.productName')) ?></b><small><?= esc($tenantName) ?></small></span>
    </header>
    <section class="main">
        <h1><?= esc(lang('Admin.confirmationDocumentHeading')) ?></h1>
        <p><?= esc(lang('Admin.confirmationDocumentLead')) ?></p>
        <p class="reference"><?= esc($reference) ?></p>
        <p class="meta"><?= esc(lang('Admin.submittedAt')) ?>: <?= esc($submittedAt) ?> UTC</p>
    </section>
    <section class="qr">
        <div class="qr-code" data-qr data-value="<?= esc($trackingUrl, 'attr') ?>" aria-label="<?= esc(lang('Admin.confirmationQrAlt'), 'attr') ?>"></div>
        <div>
            <h2><?= esc(lang('Admin.confirmationTrackTitle')) ?></h2>
            <p><?= esc(lang('Admin.confirmationTrackHelp')) ?></p>
            <a class="tracking" href="<?= esc($trackingUrl, 'attr') ?>"><?= esc($trackingUrl) ?></a>
        </div>
    </section>
    <div class="actions"><button type="button" onclick="window.print()"><?= esc(lang('Admin.printNow')) ?></button></div>
</main>
<script src="<?= esc(versioned_asset('/assets/qr-code.js'), 'attr') ?>" defer></script>
</body>
</html>
