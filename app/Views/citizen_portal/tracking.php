<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<section
    class="screen tracking-screen"
    data-tracking
    data-reference="<?= esc($reference, 'attr') ?>"
    data-strings="<?= esc(json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'attr') ?>"
>
    <p class="eyebrow"><?= esc(lang('CitizenPortal.trackingEyebrow')) ?></p>
    <h1><?= esc(lang('CitizenPortal.trackingTitle')) ?></h1>
    <p class="reference tracking-reference"><?= esc($reference) ?></p>

    <?php if (is_array($status)): ?>
        <?php
        $statusKey = match ((string) $status['status']) {
            'verified' => 'CitizenPortal.trackingStatusVerified',
            'rejected' => 'CitizenPortal.trackingStatusRejected',
            default => 'CitizenPortal.trackingStatusPending',
        };
        ?>
        <div class="card tracking-status" role="status">
            <p class="eyebrow"><?= esc(lang('CitizenPortal.trackingCurrentStatus')) ?></p>
            <h2><?= esc(lang($statusKey)) ?></h2>
            <p><?= esc(lang('CitizenPortal.trackingUpdated', [(string) $status['updated_at'] . ' UTC'])) ?></p>
        </div>
    <?php else: ?>
        <div class="card tracking-auth" data-tracking-auth>
            <div data-tracking-request>
                <h2><?= esc(lang('CitizenPortal.trackingProtectTitle')) ?></h2>
                <p><?= esc(lang('CitizenPortal.trackingProtectLead')) ?></p>
                <button type="button" class="btn" data-request-code><?= esc(lang('CitizenPortal.trackingSendCode')) ?></button>
            </div>

            <form class="is-hidden" data-tracking-code novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="challenge_uuid" value="">
                <h2><?= esc(lang('CitizenPortal.codeTitle')) ?></h2>
                <p class="lead" data-tracking-message></p>
                <div class="otp" data-otp>
                    <?php for ($digit = 1; $digit <= 6; $digit++): ?>
                        <input
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]"
                            maxlength="1"
                            autocomplete="<?= $digit === 1 ? 'one-time-code' : 'off' ?>"
                            aria-label="<?= esc(lang('CitizenPortal.codeDigit', [$digit]), 'attr') ?>"
                            required
                        >
                    <?php endfor; ?>
                </div>
                <button type="submit" class="btn"><?= esc(lang('CitizenPortal.trackingVerify')) ?></button>
            </form>
            <p class="form-error is-hidden tracking-error" data-tracking-error role="alert"></p>
        </div>
    <?php endif; ?>

    <p class="tracking-help"><?= esc(lang('CitizenPortal.trackingPrivacy')) ?></p>
    <a class="btn btn-ghost" href="/swiv?lang=<?= esc($locale, 'attr') ?>"><?= esc(lang('CitizenPortal.trackingAnother')) ?></a>
</section>
<?= $this->endSection() ?>

<?php if (! is_array($status)): ?>
    <?= $this->section('scripts') ?>
    <script src="<?= esc(versioned_asset('/assets/tracking.js'), 'attr') ?>" defer></script>
    <?= $this->endSection() ?>
<?php endif; ?>
