<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<?php $statusKey = 'Admin.notificationStatus' . ucfirst((string) $message['status']); ?>
<a class="back-link" href="/admin/notifications">← <?= esc(lang('Admin.backToNotifications')) ?></a>

<section class="panel notification-detail">
    <div class="detail-heading">
        <div>
            <p class="eyebrow"><?= esc((string) $message['event_key']) ?></p>
            <h2><?= esc((string) $message['subject']) ?></h2>
        </div>
        <span class="pill notification-status notification-status-<?= esc((string) $message['status'], 'attr') ?>"><?= esc(lang($statusKey)) ?></span>
    </div>
    <dl class="metadata-grid">
        <div><dt><?= esc(lang('Admin.notificationRecipient')) ?></dt><dd><?= esc((string) $message['recipient_masked']) ?></dd></div>
        <div><dt><?= esc(lang('Admin.notificationAudience')) ?></dt><dd><?= esc(lang('Admin.notificationAudience' . ucfirst((string) $message['audience']))) ?></dd></div>
        <div><dt><?= esc(lang('Admin.notificationChannel')) ?></dt><dd><?= esc(strtoupper((string) ($message['delivered_channel'] ?: $message['requested_channel']))) ?></dd></div>
        <div><dt><?= esc(lang('Admin.date')) ?></dt><dd><?= esc((string) $message['created_at']) ?></dd></div>
    </dl>
    <h3><?= esc(lang('Admin.notificationMessage')) ?></h3>
    <pre class="notification-body"><?= esc((string) $message['body']) ?></pre>

    <?php if ($canManage && in_array($message['status'], ['failed', 'cancelled', 'retry'], true)): ?>
        <form method="post" action="/admin/notifications/<?= rawurlencode((string) $message['uuid']) ?>/relancer" class="inline-form">
            <?= csrf_field() ?><button type="submit" class="btn btn-fit"><?= esc(lang('Admin.retryNotification')) ?></button>
        </form>
    <?php endif; ?>
    <?php if ($canManage && in_array($message['status'], ['queued', 'retry'], true)): ?>
        <form method="post" action="/admin/notifications/<?= rawurlencode((string) $message['uuid']) ?>/annuler" class="inline-form">
            <?= csrf_field() ?><button type="submit" class="btn btn-danger-quiet"><?= esc(lang('Admin.cancelNotification')) ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="panel">
    <h2><?= esc(lang('Admin.notificationAttempts')) ?></h2>
    <div class="table-wrap"><table class="queue"><thead><tr>
        <th>#</th><th><?= esc(lang('Admin.notificationChannel')) ?></th><th><?= esc(lang('Admin.status')) ?></th>
        <th><?= esc(lang('Admin.notificationProviderResponse')) ?></th><th><?= esc(lang('Admin.date')) ?></th>
    </tr></thead><tbody>
    <?php if ($message['attempts'] === []): ?><tr><td colspan="5"><?= esc(lang('Admin.noNotificationAttempts')) ?></td></tr><?php endif; ?>
    <?php foreach ($message['attempts'] as $attempt): ?><tr>
        <td><?= esc((string) $attempt['attempt_number']) ?></td><td><?= esc(strtoupper((string) $attempt['channel'])) ?></td>
        <td><?= esc(lang('Admin.notificationAttempt' . ucfirst((string) $attempt['status']))) ?></td>
        <td><?= esc((string) ($attempt['error_code'] ?: $attempt['provider_message_id'] ?: '-')) ?><span class="table-subline"><?= esc((string) ($attempt['error_detail'] ?? '')) ?></span></td>
        <td><?= esc((string) $attempt['attempted_at']) ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?= $this->endSection() ?>
