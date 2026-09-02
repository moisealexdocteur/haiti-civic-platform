<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(lang('CitizenPortal.registerTitle')) ?> — <?= esc($tenant['name']) ?></title>
    <link rel="stylesheet" href="/assets/citizen-portal.css">
</head>
<body>
    <main class="shell shell-narrow">
        <header class="topbar">
            <a class="back-link" href="/?lang=<?= esc($locale) ?>">← <?= esc(lang('CitizenPortal.backHome')) ?></a>
            <nav class="language-switch" aria-label="Language">
                <a href="?lang=fr" class="<?= $locale === 'fr' ? 'active' : '' ?>">FR</a>
                <a href="?lang=ht" class="<?= $locale === 'ht' ? 'active' : '' ?>">HT</a>
            </nav>
        </header>

        <section class="registration-head">
            <p class="eyebrow"><?= esc(lang('CitizenPortal.registerEyebrow')) ?></p>
            <h1><?= esc(lang('CitizenPortal.registerTitle')) ?></h1>
            <p class="lead tenant-name">
                <?= esc(lang('CitizenPortal.forOrganization', [$tenant['name']])) ?>
            </p>
        </section>

        <ol class="progress" aria-label="Progression">
            <li class="active"><span>1</span><?= esc(lang('CitizenPortal.progressIdentity')) ?></li>
            <li><span>2</span><?= esc(lang('CitizenPortal.progressContact')) ?></li>
            <li><span>3</span><?= esc(lang('CitizenPortal.progressDocuments')) ?></li>
            <li><span>4</span><?= esc(lang('CitizenPortal.progressConfirm')) ?></li>
        </ol>

        <div class="preview-notice" role="status">
            <?= esc(lang('CitizenPortal.previewNotice')) ?>
        </div>

        <form class="registration-card" aria-label="<?= esc(lang('CitizenPortal.registerTitle')) ?>">
            <section class="form-section">
                <h2><?= esc(lang('CitizenPortal.identitySection')) ?></h2>
                <label for="ninu"><?= esc(lang('CitizenPortal.ninuLabel')) ?></label>
                <input id="ninu" type="text" inputmode="numeric" autocomplete="off" disabled>
                <p class="field-help"><?= esc(lang('CitizenPortal.ninuHelp')) ?></p>
            </section>

            <section class="form-section">
                <h2><?= esc(lang('CitizenPortal.contactSection')) ?></h2>
                <label for="phone"><?= esc(lang('CitizenPortal.phoneLabel')) ?></label>
                <input id="phone" type="tel" placeholder="<?= esc(lang('CitizenPortal.phonePlaceholder')) ?>" disabled>
            </section>

            <section class="form-section">
                <h2><?= esc(lang('CitizenPortal.documentsSection')) ?></h2>
                <div class="upload-grid">
                    <div class="upload-box">
                        <strong><?= esc(lang('CitizenPortal.cinFront')) ?></strong>
                        <span>JPG • PNG • PDF</span>
                    </div>
                    <div class="upload-box">
                        <strong><?= esc(lang('CitizenPortal.cinBack')) ?></strong>
                        <span>JPG • PNG • PDF</span>
                    </div>
                    <div class="upload-box">
                        <strong><?= esc(lang('CitizenPortal.portrait')) ?></strong>
                        <span>JPG • PNG</span>
                    </div>
                </div>
                <p class="field-help"><?= esc(lang('CitizenPortal.portraitHelp')) ?></p>
            </section>

            <section class="consent-box">
                <div class="fake-checkbox" aria-hidden="true"></div>
                <div>
                    <strong><?= esc(lang('CitizenPortal.consentTitle')) ?></strong>
                    <p><?= esc(lang('CitizenPortal.consentText')) ?></p>
                </div>
            </section>

            <button type="button" class="primary-button" disabled>
                <?= esc(lang('CitizenPortal.submitPreview')) ?>
            </button>
        </form>

        <footer>
            <?= esc(lang('CitizenPortal.securityFootnote')) ?>
        </footer>
    </main>
</body>
</html>
