<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<section class="empty-panel">
    <p class="eyebrow"><?= esc(lang('Admin.accessDeniedTitle')) ?></p>
    <h2><?= esc(lang('Admin.accessDeniedHeading')) ?></h2>
    <p><?= esc(lang('Admin.accessDeniedLead')) ?></p>
    <a class="btn" href="/admin"><?= esc(lang('Admin.backDashboard')) ?></a>
</section>
<?= $this->endSection() ?>
