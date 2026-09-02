<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(lang('CitizenPortal.confirmationTitle')) ?> — <?= esc($tenant['name']) ?></title>
    <link rel="stylesheet" href="/assets/citizen-portal.css">
    <link rel="stylesheet" href="/assets/citizen-portal-submit.css">
</head>
<body>
    <main class="shell shell-narrow">
        <header class="topbar">
            <a class="back-link" href="/?lang=<?= esc($locale) ?>">← <?= esc(lang('CitizenPortal.backHome')) ?></a>
        </header>

        <section class="confirmation-card">
            <div class="confirmation-mark" aria-hidden="true">✓</div>
            <p class="eyebrow"><?= esc(lang('CitizenPortal.confirmationEyebrow')) ?></p>
            <h1><?= esc(lang('CitizenPortal.confirmationTitle')) ?></h1>
            <p class="lead">
                <?= esc(lang('CitizenPortal.confirmationText', [$tenant['name']])) ?>
            </p>

            <dl class="confirmation-details">
                <div>
                    <dt><?= esc(lang('CitizenPortal.referenceLabel')) ?></dt>
                    <dd><code><?= esc($reference) ?></code></dd>
                </div>
                <div>
                    <dt><?= esc(lang('CitizenPortal.statusLabel')) ?></dt>
                    <dd><?= esc(lang('CitizenPortal.statusPending')) ?></dd>
                </div>
            </dl>

            <p class="field-help confirmation-help">
                <?= esc(lang('CitizenPortal.referenceHelp')) ?>
            </p>

            <a class="primary-button confirmation-button" href="/?lang=<?= esc($locale) ?>">
                <?= esc(lang('CitizenPortal.finish')) ?>
            </a>
        </section>

        <footer>
            <?= esc(lang('CitizenPortal.securityFootnote')) ?>
        </footer>
    </main>
</body>
</html>
