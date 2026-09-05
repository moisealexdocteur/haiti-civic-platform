<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<?php
$queryUrl = static function (array $changes) use ($listing): string {
    $values = array_merge([
        'status' => $listing['status'], 'audience' => $listing['audience'],
        'channel' => $listing['channel'], 'per_page' => $listing['perPage'],
        'page' => $listing['page'],
    ], $changes);
    return '/admin/notifications?' . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
};
$statusKey = static fn (string $status): string => 'Admin.notificationStatus' . ucfirst($status);
?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.notificationsHeading')) ?></h2>
        <p><?= esc(lang('Admin.notificationsLead')) ?></p>
    </div>
</section>

<?php if (is_string($notice) && $notice !== ''): ?><p class="alert alert-ok" role="status"><?= esc($notice) ?></p><?php endif; ?>
<?php if (is_string($errorMessage) && $errorMessage !== ''): ?><p class="alert" role="alert"><?= esc($errorMessage) ?></p><?php endif; ?>

<div class="stat-grid notification-stats">
    <?php foreach (['queued', 'retry', 'sent', 'failed'] as $status): ?>
        <a class="stat-card" href="<?= esc($queryUrl(['status' => $status, 'page' => 1]), 'attr') ?>">
            <span><?= esc(lang($statusKey($status))) ?></span>
            <strong><?= esc((string) $listing['counts'][$status]) ?></strong>
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <form method="get" action="/admin/notifications" class="filter-bar notification-filters">
        <label><span><?= esc(lang('Admin.status')) ?></span>
            <select name="status">
                <?php foreach (['all', 'queued', 'processing', 'retry', 'sent', 'failed', 'cancelled'] as $value): ?>
                    <option value="<?= esc($value, 'attr') ?>" <?= $listing['status'] === $value ? 'selected' : '' ?>><?= esc(lang($statusKey($value))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span><?= esc(lang('Admin.notificationAudience')) ?></span>
            <select name="audience">
                <?php foreach (['all', 'citizen', 'administrator', 'field', 'system'] as $value): ?>
                    <option value="<?= esc($value, 'attr') ?>" <?= $listing['audience'] === $value ? 'selected' : '' ?>><?= esc(lang('Admin.notificationAudience' . ucfirst($value))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span><?= esc(lang('Admin.notificationChannel')) ?></span>
            <select name="channel">
                <?php foreach (['all', 'auto', 'whatsapp', 'sms', 'email'] as $value): ?>
                    <option value="<?= esc($value, 'attr') ?>" <?= $listing['channel'] === $value ? 'selected' : '' ?>><?= esc(lang('Admin.notificationChannel' . ucfirst($value))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="hidden" name="per_page" value="<?= esc((string) $listing['perPage'], 'attr') ?>">
        <button type="submit" class="btn btn-fit"><?= esc(lang('Admin.filter')) ?></button>
    </form>

    <div class="table-wrap">
        <table class="queue notification-table">
            <thead><tr>
                <th><?= esc(lang('Admin.date')) ?></th><th><?= esc(lang('Admin.event')) ?></th>
                <th><?= esc(lang('Admin.notificationRecipient')) ?></th><th><?= esc(lang('Admin.notificationChannel')) ?></th>
                <th><?= esc(lang('Admin.status')) ?></th><th><span class="sr-only"><?= esc(lang('Admin.open')) ?></span></th>
            </tr></thead>
            <tbody>
            <?php if ($listing['rows'] === []): ?>
                <tr><td colspan="6"><?= esc(lang('Admin.noNotifications')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($listing['rows'] as $row): ?>
                <tr>
                    <td><?= esc((string) $row['created_at']) ?></td>
                    <td><b><?= esc((string) $row['event_key']) ?></b><span class="table-subline"><?= esc(lang('Admin.notificationAudience' . ucfirst((string) $row['audience']))) ?></span></td>
                    <td><?= esc((string) $row['recipient_masked']) ?></td>
                    <td><?= esc(strtoupper((string) ($row['delivered_channel'] ?: $row['requested_channel']))) ?></td>
                    <td><span class="pill notification-status notification-status-<?= esc((string) $row['status'], 'attr') ?>"><?= esc(lang($statusKey((string) $row['status']))) ?></span>
                        <?php if ($row['last_error_code']): ?><span class="table-subline"><?= esc((string) $row['last_error_code']) ?></span><?php endif; ?>
                    </td>
                    <td><a href="/admin/notifications/<?= rawurlencode((string) $row['uuid']) ?>"><?= esc(lang('Admin.open')) ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($listing['pages'] > 1): ?>
        <nav class="pagination" aria-label="<?= esc(lang('Admin.pagination'), 'attr') ?>">
            <?php if ($listing['page'] > 1): ?><a href="<?= esc($queryUrl(['page' => $listing['page'] - 1]), 'attr') ?>"><?= esc(lang('Admin.previous')) ?></a><?php endif; ?>
            <span><?= esc(lang('Admin.pageOf', [$listing['page'], $listing['pages']])) ?></span>
            <?php if ($listing['page'] < $listing['pages']): ?><a href="<?= esc($queryUrl(['page' => $listing['page'] + 1]), 'attr') ?>"><?= esc(lang('Admin.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>
