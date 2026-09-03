<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<section class="screen is-active screen-center">
    <div class="tick" aria-hidden="true"><?= view('partials/icon_tick') ?></div>

    <h1><?= esc(lang('CitizenPortal.confirmationTitle')) ?></h1>
    <p class="lead"><?= esc(lang('CitizenPortal.confirmationLead')) ?></p>

    <p class="reference"><?= esc($reference) ?></p>
    <p class="hint text-center"><?= esc(lang('CitizenPortal.referenceHelp')) ?></p>

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
