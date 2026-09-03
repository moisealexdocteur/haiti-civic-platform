<?= $this->extend('layouts/admin') ?>

<?= $this->section('topActions') ?>
<a class="btn btn-ghost" href="/admin/identites"><?= esc(lang('Admin.backToQueue')) ?></a>
<?= $this->endSection() ?>

<?= $this->section('main') ?>
<?php
$statusLabels = [
    'pending' => lang('Admin.statusPending'),
    'verified' => lang('Admin.statusVerified'),
    'rejected' => lang('Admin.statusRejected'),
];

$contactLabels = [
    'otp_verified' => lang('Admin.contactOtpVerified'),
    'manual_review' => lang('Admin.contactManualReview'),
];

$documentLabels = [
    'cin_front' => lang('Admin.cinFront'),
    'cin_back' => lang('Admin.cinBack'),
    'portrait' => lang('Admin.portrait'),
];

$eventLabels = [
    'identity.public_submitted' => lang('Admin.eventIdentitySubmitted'),
    'identity.submitted' => lang('Admin.eventIdentitySubmitted'),
    'identity.verified' => lang('Admin.eventIdentityVerified'),
    'identity.rejected' => lang('Admin.eventIdentityRejected'),
    'identity.reopened' => lang('Admin.eventIdentityReopened'),
    'identity.status_changed' => lang('Admin.eventIdentityStatus'),
    'document.uploaded' => lang('Admin.eventDocumentUploaded'),
    'document.revised' => lang('Admin.eventDocumentRevised'),
];

$reasonCodes = [
    'document_illisible' => lang('Admin.reasonDocumentUnreadable'),
    'photo_floue' => lang('Admin.reasonBlurryPhoto'),
    'carte_incomplete' => lang('Admin.reasonIncompleteCard'),
    'portrait_non_conforme' => lang('Admin.reasonPortrait'),
    'information_incoherente' => lang('Admin.reasonMismatch'),
    'autre' => lang('Admin.reasonOther'),
];

$byType = [];

foreach ($identity['documents'] as $document) {
    $byType[(string) $document['document_type']] = $document;
}

$uuid = rawurlencode((string) $identity['uuid']);
$status = (string) $identity['verification_status'];
$ninu = (string) $identity['ninu'];
$phone = (string) ($identity['phone'] ?? '');
$contactStatus = (string) $identity['contact_verification_status'];
?>

<?php if ($decisionOk): ?>
    <p class="alert alert-ok"><?= esc(lang('Admin.decisionSaved')) ?></p>
<?php endif; ?>

<?php if (is_string($decisionError) && $decisionError !== ''): ?>
    <p class="alert" role="alert"><?= esc($decisionError) ?></p>
<?php endif; ?>

<section class="panel">
    <h2><?= esc(lang('Admin.compareTitle')) ?></h2>
    <p class="panel-note"><?= esc(lang('Admin.compareHelp')) ?></p>

    <div class="compare">
        <?php foreach (['cin_front', 'portrait', 'cin_back'] as $type): ?>
            <figure>
                <figcaption><?= esc($documentLabels[$type]) ?></figcaption>
                <?php if (isset($byType[$type])): ?>
                    <img
                        src="/admin/identites/<?= $uuid ?>/documents/<?= rawurlencode((string) $byType[$type]['uuid']) ?>"
                        alt="<?= esc($documentLabels[$type], 'attr') ?>"
                        loading="lazy"
                    >
                    <a
                        href="/admin/identites/<?= $uuid ?>/documents/<?= rawurlencode((string) $byType[$type]['uuid']) ?>"
                        target="_blank"
                        rel="noopener"
                    ><?= esc(lang('Admin.openLarge')) ?></a>
                <?php else: ?>
                    <p class="missing"><?= esc(lang('Admin.missingDocument')) ?></p>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid-two">
    <section class="panel">
        <h2><?= esc(lang('Admin.identityTitle')) ?> <span class="pill pill-<?= esc($status, 'attr') ?>"><?= esc($statusLabels[$status] ?? $status) ?></span></h2>
        <p class="panel-note"><?= esc(lang('Admin.sensitiveHelp')) ?></p>

        <dl class="details">
            <div>
                <dt><?= esc(lang('Admin.reference')) ?></dt>
                <dd class="masked"><?= esc((string) $identity['public_reference']) ?></dd>
            </div>
            <div>
                <dt><?= esc(lang('Admin.identityNumber')) ?></dt>
                <dd>
                    <span class="masked">••• ••• <?= esc(substr($ninu, -3)) ?></span>
                    <details class="sensitive">
                        <summary><?= esc(lang('Admin.show')) ?></summary>
                        <span class="value"><?= esc($ninu) ?></span>
                    </details>
                </dd>
            </div>
            <div>
                <dt><?= esc(lang('Admin.phone')) ?></dt>
                <dd>
                    <?php if ($phone === ''): ?>
                        <span class="masked"><?= esc(lang('Admin.notProvided')) ?></span>
                    <?php else: ?>
                        <span class="masked">+509 •• •• •• <?= esc(substr($phone, -2)) ?></span>
                        <details class="sensitive">
                            <summary><?= esc(lang('Admin.show')) ?></summary>
                            <span class="value"><?= esc($phone) ?></span>
                        </details>
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt><?= esc(lang('Admin.contactVerification')) ?></dt>
                <dd><?= esc($contactLabels[$contactStatus] ?? $contactStatus) ?></dd>
            </div>
            <div>
                <dt><?= esc(lang('Admin.department')) ?></dt>
                <dd><?= esc($departments[(string) ($identity['department_code'] ?? '')] ?? lang('Admin.notProvided')) ?></dd>
            </div>
            <div>
                <dt><?= esc(lang('Admin.consent')) ?></dt>
                <dd><?= esc((string) $identity['consented_at']) ?> UTC<br><span class="masked"><?= esc((string) $identity['consent_version']) ?></span></dd>
            </div>
            <div>
                <dt><?= esc(lang('Admin.verifiedAt')) ?></dt>
                <dd><?= esc((string) ($identity['verified_at'] ?? lang('Admin.never'))) ?></dd>
            </div>
        </dl>
    </section>

    <section class="panel">
        <h2><?= esc(lang('Admin.decisionTitle')) ?></h2>

        <?php if (! $canManage): ?>
            <p class="empty"><?= esc(lang('Admin.viewOnly')) ?></p>
        <?php elseif ($status === 'pending'): ?>
            <form method="post" action="/admin/identites/<?= $uuid ?>/statut" class="decision">
                <?= csrf_field() ?>

                <fieldset>
                    <legend><?= esc(lang('Admin.decisionQuestion')) ?></legend>

                    <label class="choice">
                        <input type="radio" name="to_status" value="verified" required>
                        <span><?= esc(lang('Admin.approveDecision')) ?></span>
                    </label>

                    <label class="choice">
                        <input type="radio" name="to_status" value="rejected">
                        <span><?= esc(lang('Admin.rejectDecision')) ?></span>
                    </label>
                </fieldset>

                <div class="field">
                    <label for="reason_code"><?= esc(lang('Admin.rejectionReason')) ?></label>
                    <select id="reason_code" name="reason_code">
                        <option value=""></option>
                        <?php foreach ($reasonCodes as $code => $label): ?>
                            <option value="<?= esc($code, 'attr') ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint"><?= esc(lang('Admin.reasonOnlyForReject')) ?></p>
                </div>

                <label class="confirm">
                    <input type="checkbox" required>
                    <span><?= esc(lang('Admin.confirmDecision')) ?></span>
                </label>

                <?php if ($contactStatus === 'manual_review'): ?>
                    <label class="confirm">
                        <input type="checkbox" name="contact_reviewed" value="1" required>
                        <span><?= esc(lang('Admin.confirmManualContact')) ?></span>
                    </label>
                    <p class="hint"><?= esc(lang('Admin.contactManualReviewHelp')) ?></p>
                <?php endif; ?>

                <button type="submit" class="btn"><?= esc(lang('Admin.saveDecision')) ?></button>
            </form>
        <?php elseif ($status === 'rejected'): ?>
            <form method="post" action="/admin/identites/<?= $uuid ?>/statut" class="decision">
                <?= csrf_field() ?>
                <input type="hidden" name="to_status" value="pending">
                <p><?= esc(lang('Admin.reopenHelp')) ?></p>
                <button type="submit" class="btn btn-ghost"><?= esc(lang('Admin.reopen')) ?></button>
            </form>
        <?php else: ?>
            <p class="empty"><?= esc(lang('Admin.verifiedFinal')) ?></p>
        <?php endif; ?>
    </section>
</div>

<div class="grid-two">
    <section class="panel">
        <h2><?= esc(lang('Admin.historyTitle')) ?></h2>
        <?php if ($identity['events'] === []): ?>
            <p class="empty"><?= esc(lang('Admin.noEvent')) ?></p>
        <?php else: ?>
            <ol class="timeline">
                <?php foreach ($identity['events'] as $event): ?>
                    <?php $type = (string) $event['event_type']; ?>
                    <li>
                        <b><?= esc($eventLabels[$type] ?? $type) ?></b>
                        <span><?= esc((string) $event['occurred_at']) ?> UTC</span>
                        <?php if ($event['from_status'] !== null || $event['to_status'] !== null): ?>
                            <span>
                                <?= esc($statusLabels[(string) $event['from_status']] ?? lang('Admin.notProvided')) ?>
                                <?= esc(lang('Admin.toStatus')) ?>
                                <?= esc($statusLabels[(string) $event['to_status']] ?? lang('Admin.notProvided')) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($event['reason_code'] !== null): ?>
                            <span><?= esc(lang('Admin.rejectionReason')) ?>: <?= esc($reasonCodes[(string) $event['reason_code']] ?? lang('Admin.reasonOther')) ?></span>
                        <?php endif; ?>
                        <span><?= esc(lang('Admin.by')) ?> <?= esc((string) ($event['actor_display_name'] ?? lang('Admin.citizen'))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2><?= esc(lang('Admin.traceTitle')) ?></h2>
        <?php if ($identity['audit'] === []): ?>
            <p class="empty"><?= esc(lang('Admin.noAudit')) ?></p>
        <?php else: ?>
            <ol class="timeline">
                <?php foreach ($identity['audit'] as $audit): ?>
                    <li>
                        <b><?= esc((string) $audit['event']) ?></b>
                        <span><?= esc((string) $audit['occurred_at']) ?> UTC</span>
                        <span><?= esc(lang('Admin.actor')) ?>: <?= esc((string) $audit['actor_type']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>
<?= $this->endSection() ?>
