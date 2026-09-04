<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<section class="screen tracking-screen">
    <p class="eyebrow"><?= esc(lang('CitizenPortal.trackingEyebrow')) ?></p>
    <h1><?= esc(lang('CitizenPortal.trackingLookupTitle')) ?></h1>
    <p class="lead"><?= esc(lang('CitizenPortal.trackingLookupLead')) ?></p>

    <?php if ((string) service('request')->getGet('erreur') === 'reference'): ?>
        <p class="form-error" role="alert"><?= esc(lang('CitizenPortal.trackingReferenceInvalid')) ?></p>
    <?php endif; ?>

    <form method="post" action="/swiv" class="card tracking-lookup-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
            <label for="tracking-reference"><?= esc(lang('CitizenPortal.trackingIdentifierLabel')) ?></label>
            <input
                id="tracking-reference"
                name="identifier"
                type="text"
                inputmode="text"
                autocapitalize="characters"
                maxlength="19"
                placeholder="DOS-7K4M-9P2R-X8CW"
                required
            >
            <p class="hint"><?= esc(lang('CitizenPortal.trackingIdentifierHint')) ?></p>
        </div>
        <div class="field">
            <label for="tracking-organisation"><?= esc(lang('CitizenPortal.trackingOrganizationLabel')) ?></label>
            <input
                id="tracking-organisation"
                name="organisation"
                type="text"
                inputmode="text"
                autocapitalize="none"
                maxlength="80"
                placeholder="demo-citoyen"
            >
            <p class="hint"><?= esc(lang('CitizenPortal.trackingOrganizationHint')) ?></p>
        </div>
        <button type="submit" class="btn"><?= esc(lang('CitizenPortal.trackingOpen')) ?></button>
    </form>
</section>
<?= $this->endSection() ?>
