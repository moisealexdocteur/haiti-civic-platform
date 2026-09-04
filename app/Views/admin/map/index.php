<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<style data-leaflet-styles="inline"><?= inline_stylesheet('/assets/leaflet.css') ?></style>
<?= $this->endSection() ?>

<?= $this->section('main') ?>
<?php
$total = array_sum(array_column($rows, 'total'));
$mapped = count(array_filter(
    $rows,
    static fn (array $row): bool => (int) $row['total'] > 0
));
?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.mapHeading')) ?></h2>
        <p><?= esc(lang('Admin.mapLead')) ?></p>
    </div>
</section>

<div class="metric-grid map-metrics">
    <article class="metric">
        <span><?= esc(lang('Admin.mapFiles')) ?></span>
        <strong><?= esc((string) $total) ?></strong>
    </article>
    <article class="metric">
        <span><?= esc(lang('Admin.mapDepartments')) ?></span>
        <strong><?= esc((string) $mapped) ?> / 10</strong>
    </article>
</div>

<section class="panel map-panel">
    <h2><?= esc(lang('Admin.mapTitle')) ?></h2>
    <p class="panel-note"><?= esc(lang('Admin.mapPrivacy')) ?></p>
    <p class="map-status" id="map-status"><?= esc(lang('Admin.mapLoading')) ?></p>
    <div
        id="identity-map"
        class="identity-map"
        role="img"
        aria-label="<?= esc(lang('Admin.mapAriaLabel'), 'attr') ?>"
    ></div>
    <p class="hint"><?= esc(lang('Admin.mapMarkerNote')) ?></p>
</section>

<section class="panel">
    <h2><?= esc(lang('Admin.mapTableTitle')) ?></h2>
    <div class="table-wrap">
        <table class="queue">
            <thead>
            <tr>
                <th><?= esc(lang('Admin.department')) ?></th>
                <th><?= esc(lang('Admin.mapFiles')) ?></th>
                <th><?= esc(lang('Admin.statusPending')) ?></th>
                <th><?= esc(lang('Admin.statusVerified')) ?></th>
                <th><?= esc(lang('Admin.statusRejected')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= esc((string) $row['name']) ?></td>
                    <td class="num"><?= esc((string) $row['total']) ?></td>
                    <td class="num"><?= esc((string) $row['pending']) ?></td>
                    <td class="num"><?= esc((string) $row['verified']) ?></td>
                    <td class="num"><?= esc((string) $row['rejected']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script type="application/json" id="map-data"><?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="map-strings"><?= json_encode([
    'files' => lang('Admin.mapFiles'),
    'pending' => lang('Admin.statusPending'),
    'verified' => lang('Admin.statusVerified'),
    'rejected' => lang('Admin.statusRejected'),
    'unavailable' => lang('Admin.mapUnavailable'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= esc(versioned_asset('/assets/leaflet.js'), 'attr') ?>" defer></script>
<script src="<?= esc(versioned_asset('/assets/admin-map.js'), 'attr') ?>" defer></script>
<?= $this->endSection() ?>
