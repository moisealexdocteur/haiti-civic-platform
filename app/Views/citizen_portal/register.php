<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(lang('CitizenPortal.registerTitle')) ?> — <?= esc($tenant['name']) ?></title>
    <link rel="stylesheet" href="/assets/citizen-portal.css">
    <link rel="stylesheet" href="/assets/citizen-portal-submit.css">
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
            <li class="active"><span>2</span><?= esc(lang('CitizenPortal.progressContact')) ?></li>
            <li class="active"><span>3</span><?= esc(lang('CitizenPortal.progressDocuments')) ?></li>
            <li><span>4</span><?= esc(lang('CitizenPortal.progressConfirm')) ?></li>
        </ol>

        <?php if ($errorMessage !== null): ?>
            <div class="alert" role="alert">
                <?= esc($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div class="preview-notice" role="status">
            <?= esc(lang('CitizenPortal.secureSubmissionNotice')) ?>
        </div>

        <form
            class="registration-card"
            aria-label="<?= esc(lang('CitizenPortal.registerTitle')) ?>"
            method="post"
            enctype="multipart/form-data"
            action="/inscription/<?= esc($tenant['slug']) ?>?lang=<?= esc($locale) ?>"
        >
            <?= csrf_field() ?>

            <section class="form-section">
                <h2><?= esc(lang('CitizenPortal.identitySection')) ?></h2>
                <label for="ninu"><?= esc(lang('CitizenPortal.ninuLabel')) ?></label>
                <input
                    id="ninu"
                    name="ninu"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="96"
                    required
                >
                <p class="field-help"><?= esc(lang('CitizenPortal.ninuHelp')) ?></p>
            </section>

            <section class="form-section">
                <h2><?= esc(lang('CitizenPortal.contactSection')) ?></h2>
                <label for="phone"><?= esc(lang('CitizenPortal.phoneLabel')) ?></label>
                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    maxlength="24"
                    autocomplete="tel"
                    placeholder="<?= esc(lang('CitizenPortal.phonePlaceholder')) ?>"
                    required
                >
            </section>

            <section class="form-section">
                <h2><?= esc(lang('CitizenPortal.documentsSection')) ?></h2>
                <div class="upload-grid">
                    <label class="upload-box" for="cin_front">
                        <strong><?= esc(lang('CitizenPortal.cinFront')) ?></strong>
                        <span>JPG • PNG • PDF • <?= esc(lang('CitizenPortal.maxFiveMb')) ?></span>
                        <input
                            id="cin_front"
                            name="cin_front"
                            type="file"
                            accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf"
                            required
                        >
                    </label>
                    <label class="upload-box" for="cin_back">
                        <strong><?= esc(lang('CitizenPortal.cinBack')) ?></strong>
                        <span>JPG • PNG • PDF • <?= esc(lang('CitizenPortal.maxFiveMb')) ?></span>
                        <input
                            id="cin_back"
                            name="cin_back"
                            type="file"
                            accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf"
                            required
                        >
                    </label>
                    <label class="upload-box" for="portrait">
                        <strong><?= esc(lang('CitizenPortal.portrait')) ?></strong>
                        <span>JPG • PNG • <?= esc(lang('CitizenPortal.maxFiveMb')) ?></span>
                        <input
                            id="portrait"
                            name="portrait"
                            type="file"
                            accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                            required
                        >
                    </label>
                </div>
                <p class="field-help"><?= esc(lang('CitizenPortal.portraitHelp')) ?></p>
            </section>

            <label class="consent-box consent-live" for="consent">
                <input
                    id="consent"
                    name="consent"
                    type="checkbox"
                    value="1"
                    required
                >
                <div>
                    <strong><?= esc(lang('CitizenPortal.consentTitle')) ?></strong>
                    <p><?= esc(lang('CitizenPortal.consentText')) ?></p>
                </div>
            </label>

            <button type="submit" class="primary-button">
                <?= esc(lang('CitizenPortal.submitSecure')) ?>
            </button>
        </form>

        <footer>
            <?= esc(lang('CitizenPortal.securityFootnote')) ?>
        </footer>
    </main>
</body>
</html>
