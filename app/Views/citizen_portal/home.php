<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(lang('CitizenPortal.brand')) ?></title>
    <link rel="stylesheet" href="/assets/citizen-portal.css">
    <link rel="stylesheet" href="/assets/political-structures.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div class="brand-mark" aria-hidden="true">HC</div>
            <div class="brand-copy">
                <strong><?= esc(lang('CitizenPortal.brand')) ?></strong>
                <span><?= esc(lang('CitizenPortal.eyebrow')) ?></span>
            </div>
            <nav class="language-switch" aria-label="Language">
                <a class="<?= $locale === 'fr' ? 'active' : '' ?>" href="/?lang=fr">
                    <?= esc(lang('CitizenPortal.languageFr')) ?>
                </a>
                <a class="<?= $locale === 'ht' ? 'active' : '' ?>" href="/?lang=ht">
                    <?= esc(lang('CitizenPortal.languageHt')) ?>
                </a>
            </nav>
        </header>

        <section class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow"><?= esc(lang('CitizenPortal.eyebrow')) ?></p>
                <h1><?= esc(lang('CitizenPortal.homeTitle')) ?></h1>
                <p class="lead"><?= esc(lang('CitizenPortal.homeLead')) ?></p>

                <div class="trust-row">
                    <span>✓ <?= esc(lang('CitizenPortal.trustEncrypted')) ?></span>
                    <span>✓ <?= esc(lang('CitizenPortal.trustIsolated')) ?></span>
                    <span>✓ <?= esc(lang('CitizenPortal.trustAuditable')) ?></span>
                </div>
            </div>

            <section class="access-card" id="access" aria-labelledby="access-title">
                <div>
                    <p class="eyebrow"><?= esc(lang('CitizenPortal.brand')) ?></p>
                    <h2 id="access-title"><?= esc(lang('CitizenPortal.accessTitle')) ?></h2>
                </div>

                <?php if ($organizationError): ?>
                    <div class="alert" role="alert">
                        <?= esc(lang('CitizenPortal.organizationRequired')) ?>
                    </div>
                <?php endif; ?>

                <form action="/inscription" method="get" class="stack">
                    <input type="hidden" name="lang" value="<?= esc($locale) ?>">
                    <label for="organisation">
                        <?= esc(lang('CitizenPortal.organizationCode')) ?>
                    </label>
                    <input
                        id="organisation"
                        name="organisation"
                        type="text"
                        maxlength="80"
                        autocomplete="organization"
                        placeholder="<?= esc(lang('CitizenPortal.organizationPlaceholder')) ?>"
                        required
                    >
                    <p class="field-help"><?= esc(lang('CitizenPortal.organizationHelp')) ?></p>
                    <a class="directory-link" href="/structures-politiques?lang=<?= esc($locale) ?>">
                        <?= esc(lang('CitizenPortal.officialStructuresLink')) ?>
                    </a>
                    <button type="submit" class="primary-button">
                        <?= esc(lang('CitizenPortal.continue')) ?>
                    </button>
                </form>
            </section>
        </section>

        <section class="privacy-card">
            <div class="privacy-icon" aria-hidden="true">🔐</div>
            <div>
                <h2><?= esc(lang('CitizenPortal.privacyTitle')) ?></h2>
                <p><?= esc(lang('CitizenPortal.privacyText')) ?></p>
            </div>
        </section>

        <footer>
            <?= esc(lang('CitizenPortal.securityFootnote')) ?>
        </footer>
    </main>
</body>
</html>
