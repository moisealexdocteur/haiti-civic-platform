<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<section class="screen is-active screen-center">
    <div class="tick" aria-hidden="true"><?= view('partials/icon_tick') ?></div>

    <h1><?= esc(lang('CitizenPortal.confirmationTitle')) ?></h1>
    <p class="lead"><?= esc(lang('CitizenPortal.confirmationLead')) ?></p>

    <p class="eyebrow reference-eyebrow"><?= esc(lang('CitizenPortal.referenceLabel')) ?></p>
    <p class="reference" data-reference><?= esc($reference) ?></p>
    <p class="hint text-center"><?= esc(lang('CitizenPortal.referenceHelp')) ?></p>

    <div class="qr-card">
        <div
            class="qr-code"
            data-qr
            data-value="<?= esc($trackingUrl, 'attr') ?>"
            aria-label="<?= esc(lang('CitizenPortal.qrAlt'), 'attr') ?>"
        ></div>
        <div class="qr-copy">
            <h2><?= esc(lang('CitizenPortal.qrTitle')) ?></h2>
            <p><?= esc(lang('CitizenPortal.qrHelp')) ?></p>
            <a href="<?= esc($trackingUrl, 'attr') ?>"><?= esc(lang('CitizenPortal.trackNow')) ?></a>
        </div>
    </div>

    <div class="card next-step-card">
        <h2><?= esc(lang('CitizenPortal.nextStepTitle')) ?></h2>
        <p class="lead next-step-lead">
            <?= esc(lang('CitizenPortal.nextStepText', [lang('CitizenPortal.channelWhatsApp')])) ?>
        </p>
    </div>

    <div class="spacer"></div>

    <a class="btn btn-ghost" href="/?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('CitizenPortal.finish')) ?></a>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script><?= inline_script('/assets/qr-code.js') ?></script>
<?= $this->endSection() ?>
