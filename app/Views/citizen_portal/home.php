<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<section class="home-hero">
    <h1><?= esc(lang('CitizenPortal.homeTitle')) ?></h1>
    <p class="lead"><?= esc(lang('CitizenPortal.homeLead')) ?></p>

    <ul class="trust">
        <li><?= view('partials/icon_check') ?><span><?= esc(lang('CitizenPortal.trustOne')) ?></span></li>
        <li><?= view('partials/icon_check') ?><span><?= esc(lang('CitizenPortal.trustTwo')) ?></span></li>
        <li><?= view('partials/icon_check') ?><span><?= esc(lang('CitizenPortal.trustThree')) ?></span></li>
    </ul>
</section>

<section class="card" aria-labelledby="access-title">
    <h2 id="access-title"><?= esc(lang('CitizenPortal.accessTitle')) ?></h2>

    <?php if ($organizationError): ?>
        <p class="alert" role="alert"><?= esc(lang('CitizenPortal.organizationRequired')) ?></p>
    <?php endif; ?>

    <form action="/inscription" method="get">
        <input type="hidden" name="lang" value="<?= esc($locale, 'attr') ?>">

        <div class="field">
            <label for="organisation"><?= esc(lang('CitizenPortal.organizationCode')) ?></label>
            <input
                id="organisation"
                name="organisation"
                type="text"
                maxlength="80"
                autocomplete="organization"
                autocapitalize="off"
                spellcheck="false"
                placeholder="<?= esc(lang('CitizenPortal.organizationPlaceholder'), 'attr') ?>"
                aria-describedby="organisation-hint"
                required
            >
            <p class="hint" id="organisation-hint"><?= esc(lang('CitizenPortal.organizationHelp')) ?></p>
        </div>

        <button type="submit" class="btn"><?= esc(lang('CitizenPortal.continue')) ?></button>
    </form>
</section>

<p class="page-links">
    <a href="/structures-politiques?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('CitizenPortal.directoryLink')) ?></a>
</p>
<?= $this->endSection() ?>
