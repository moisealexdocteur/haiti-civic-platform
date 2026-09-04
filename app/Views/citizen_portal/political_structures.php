<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<section class="directory">
    <p class="eyebrow"><?= esc(lang('CitizenPortal.officialStructuresEyebrow')) ?></p>
    <h1><?= esc(lang('CitizenPortal.officialStructuresTitle')) ?></h1>
    <p class="lead"><?= esc(lang('CitizenPortal.officialStructuresLead')) ?></p>

    <div class="dirsum">
        <div><b><?= esc((string) $counts['total']) ?></b><span><?= esc(lang('CitizenPortal.officialStructuresTotal')) ?></span></div>
        <div><b><?= esc((string) $counts['parti']) ?></b><span><?= esc(lang('CitizenPortal.officialStructuresParties')) ?></span></div>
        <div><b><?= esc((string) $counts['groupement']) ?></b><span><?= esc(lang('CitizenPortal.officialStructuresGroups')) ?></span></div>
    </div>

    <span class="note"><?= esc(lang('CitizenPortal.officialStructuresNotice')) ?></span>

    <div class="field">
        <label for="structure-search"><?= esc(lang('CitizenPortal.officialStructuresSearchLabel')) ?></label>
        <input
            id="structure-search"
            type="search"
            autocomplete="off"
            placeholder="<?= esc(lang('CitizenPortal.officialStructuresSearchPlaceholder'), 'attr') ?>"
            data-structure-search
        >
    </div>

    <div class="dirtable-wrap">
        <table class="dirtable">
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
                <tr data-structure-row data-search="<?= esc((string) $structure['name'] . ' ' . (string) $structure['acronym'], 'attr') ?>">
                    <td class="num"><?= esc((string) $structure['cep_list_position']) ?></td>
                    <td><?= esc(
                        (string) $structure['structure_type'] === 'groupement'
                            ? lang('CitizenPortal.officialStructuresTypeGroup')
                            : lang('CitizenPortal.officialStructuresTypeParty')
                    ) ?></td>
                    <td><?= esc((string) $structure['name']) ?></td>
                    <td><strong><?= esc((string) $structure['acronym']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p class="hint" data-structure-empty hidden><?= esc(lang('CitizenPortal.officialStructuresNoResult')) ?></p>

    <p class="page-links">
        <a href="/?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('CitizenPortal.backHome')) ?></a>
        <br>
        <a href="<?= esc($sourceUrl, 'attr') ?>" target="_blank" rel="noopener noreferrer"><?= esc(lang('CitizenPortal.officialStructuresSourceLink')) ?></a>
    </p>
    <p class="hint"><?= esc(lang('CitizenPortal.officialStructuresSourceText', [$approvalDate, $sourceReference])) ?></p>
    <p class="hint"><?= esc(lang('CitizenPortal.officialStructuresNumberNote')) ?></p>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(versioned_asset('/assets/political-structures.js'), 'attr') ?>" defer></script>
<?= $this->endSection() ?>
