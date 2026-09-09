<?= $this->extend('layouts/admin') ?>
<?= $this->section('topActions') ?>
<a class="btn btn-ghost" href="<?= esc($identityUrl, 'attr') ?>"><?= esc(lang('Admin.identityTitle')) ?></a>
<a class="btn btn-ghost" href="<?= esc($navigationBackUrl, 'attr') ?>"><?= esc(lang('Admin.backToQueue')) ?></a>
<?= $this->endSection() ?>
<?= $this->section('main') ?>
<section class="panel">
    <?php if ($documentIsPdf): ?>
        <iframe class="document-preview" src="<?= esc($documentUrl, 'attr') ?>" title="<?= esc(lang('Admin.documents'), 'attr') ?>"></iframe>
    <?php else: ?>
        <img class="document-preview-image" src="<?= esc($documentUrl, 'attr') ?>" alt="<?= esc(lang('Admin.documents'), 'attr') ?>">
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
