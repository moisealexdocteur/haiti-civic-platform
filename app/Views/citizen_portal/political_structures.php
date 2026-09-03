<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(lang('CitizenPortal.officialStructuresTitle')) ?> — <?= esc(lang('CitizenPortal.brand')) ?></title>
    <link rel="stylesheet" href="/assets/citizen-portal.css">
    <link rel="stylesheet" href="/assets/political-structures.css">
</head>
<body>
<main class="shell directory-shell">
    <header class="topbar">
        <div class="brand-mark" aria-hidden="true">HC</div>
        <div class="brand-copy">
            <strong><?= esc(lang('CitizenPortal.brand')) ?></strong>
            <span><?= esc(lang('CitizenPortal.eyebrow')) ?></span>
        </div>
        <nav class="language-switch" aria-label="Language">
            <a class="<?= $locale === 'fr' ? 'active' : '' ?>" href="/structures-politiques?lang=fr">
                <?= esc(lang('CitizenPortal.languageFr')) ?>
            </a>
            <a class="<?= $locale === 'ht' ? 'active' : '' ?>" href="/structures-politiques?lang=ht">
                <?= esc(lang('CitizenPortal.languageHt')) ?>
            </a>
        </nav>
    </header>

    <section class="directory-head">
        <a class="back-link" href="/?lang=<?= esc($locale) ?>">← <?= esc(lang('CitizenPortal.backHome')) ?></a>
        <p class="eyebrow"><?= esc(lang('CitizenPortal.officialStructuresEyebrow')) ?></p>
        <h1><?= esc(lang('CitizenPortal.officialStructuresTitle')) ?></h1>
        <p class="lead"><?= esc(lang('CitizenPortal.officialStructuresLead')) ?></p>

        <div class="directory-counts" aria-label="<?= esc(lang('CitizenPortal.officialStructuresCounts')) ?>">
            <span><strong><?= esc((string) $counts['total']) ?></strong> <?= esc(lang('CitizenPortal.officialStructuresTotal')) ?></span>
            <span><strong><?= esc((string) $counts['parti']) ?></strong> <?= esc(lang('CitizenPortal.officialStructuresParties')) ?></span>
            <span><strong><?= esc((string) $counts['groupement']) ?></strong> <?= esc(lang('CitizenPortal.officialStructuresGroups')) ?></span>
        </div>
    </section>

    <section class="directory-panel">
        <div class="directory-source">
            <div>
                <strong><?= esc(lang('CitizenPortal.officialStructuresSourceTitle')) ?></strong>
                <p><?= esc(lang('CitizenPortal.officialStructuresSourceText', [$approvalDate, $sourceReference])) ?></p>
                <p class="directory-warning"><?= esc(lang('CitizenPortal.officialStructuresNumberNote')) ?></p>
            </div>
            <a class="directory-source-link" href="<?= esc($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">
                <?= esc(lang('CitizenPortal.officialStructuresSourceLink')) ?> ↗
            </a>
        </div>

        <label for="political-structure-search"><?= esc(lang('CitizenPortal.officialStructuresSearchLabel')) ?></label>
        <input
            id="political-structure-search"
            class="directory-search"
            type="search"
            autocomplete="off"
            placeholder="<?= esc(lang('CitizenPortal.officialStructuresSearchPlaceholder')) ?>"
            data-structure-search
        >

        <div class="directory-table-wrap">
            <table class="directory-table">
                <thead>
                <tr>
                    <th><?= esc(lang('CitizenPortal.officialStructuresPosition')) ?></th>
                    <th><?= esc(lang('CitizenPortal.officialStructuresType')) ?></th>
                    <th><?= esc(lang('CitizenPortal.officialStructuresName')) ?></th>
                    <th><?= esc(lang('CitizenPortal.officialStructuresAcronym')) ?></th>
                </tr>
                </thead>
                <tbody data-structure-rows>
                <?php foreach ($structures as $structure): ?>
                    <tr data-structure-row data-search="<?= esc((string) $structure['name'] . ' ' . (string) $structure['acronym']) ?>">
                        <td><?= esc((string) $structure['cep_list_position']) ?></td>
                        <td>
                            <span class="structure-type">
                                <?= esc(
                                    (string) $structure['structure_type'] === 'groupement'
                                        ? lang('CitizenPortal.officialStructuresTypeGroup')
                                        : lang('CitizenPortal.officialStructuresTypeParty')
                                ) ?>
                            </span>
                        </td>
                        <td><?= esc((string) $structure['name']) ?></td>
                        <td><strong><?= esc((string) $structure['acronym']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="directory-empty" data-structure-empty hidden>
            <?= esc(lang('CitizenPortal.officialStructuresNoResult')) ?>
        </p>
    </section>
</main>
<script src="/assets/political-structures.js" defer></script>
</body>
</html>
