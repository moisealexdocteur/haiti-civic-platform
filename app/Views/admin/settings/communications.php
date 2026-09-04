<?= $this->extend('layouts/admin') ?>

<?= $this->section('main') ?>
<section class="page-intro">
    <div>
        <h2><?= esc(lang('Admin.communicationsHeading')) ?></h2>
        <p><?= esc(lang('Admin.communicationsLead')) ?></p>
    </div>
</section>

<?php if ($saved): ?>
    <p class="alert alert-ok" role="status"><?= esc(lang('Admin.settingsSaved')) ?></p>
<?php endif; ?>
<?php if ($deleted): ?>
    <p class="alert alert-ok" role="status"><?= esc(lang('Admin.channelDeleted')) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    <p class="alert" role="alert"><?= esc($errorMessage) ?></p>
<?php endif; ?>

<aside class="channel-contract" aria-labelledby="channel-contract-title">
    <div>
        <h2 id="channel-contract-title"><?= esc(lang('Admin.citizenChannelsTitle')) ?></h2>
        <p><?= esc(lang('Admin.citizenChannelsHelp')) ?></p>
    </div>
    <ul>
        <?php foreach (['whatsapp', 'sms', 'email'] as $channel): ?>
            <?php $ready = $settings[$channel . '_enabled'] && $settings[$channel . '_validation_status'] === 'valid'; ?>
            <li
                class="<?= $ready ? 'is-ready' : '' ?>"
                data-citizen-channel="<?= esc($channel, 'attr') ?>"
            >
                <span aria-hidden="true"></span>
                <?= esc(lang(match ($channel) {
                    'whatsapp' => 'Admin.whatsappTitle',
                    'sms' => 'Admin.smsTitle',
                    default => 'Admin.emailTitle',
                })) ?>
                <small><?= esc(lang($ready ? 'Admin.visibleToCitizens' : 'Admin.hiddenFromCitizens')) ?></small>
            </li>
        <?php endforeach; ?>
    </ul>
</aside>

<form method="post" action="/admin/communications" class="settings-form" autocomplete="off">
    <?= csrf_field() ?>
    <section class="settings-card">
        <header class="settings-card-head">
            <div>
                <h2><?= esc(lang('Admin.whatsappTitle')) ?></h2>
                <p><?= esc(lang('Admin.whatsappHelp')) ?></p>
            </div>
            <div class="channel-head-tools">
                <span class="channel-state <?= $settings['whatsapp_validation_status'] === 'valid' ? 'is-valid' : 'is-untested' ?>" data-channel-status="whatsapp">
                    <?= esc(lang($settings['whatsapp_validation_status'] === 'valid' ? 'Admin.channelValid' : 'Admin.channelUntested')) ?>
                </span>
                <label class="switch-row">
                    <input type="checkbox" name="whatsapp_enabled" value="1" <?= $settings['whatsapp_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                    <span><?= esc(lang('Admin.useWhatsapp')) ?></span>
                </label>
            </div>
        </header>
        <div class="form-grid">
            <div class="field">
                <label for="graph-version"><?= esc(lang('Admin.graphVersion')) ?></label>
                <input id="graph-version" name="whatsapp_graph_version" type="text" value="<?= esc($settings['whatsapp_graph_version'], 'attr') ?>" maxlength="20" placeholder="v26.0" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="wa-phone-id"><?= esc(lang('Admin.phoneNumberId')) ?></label>
                <input id="wa-phone-id" name="whatsapp_phone_number_id" type="text" value="<?= esc($settings['whatsapp_phone_number_id'], 'attr') ?>" maxlength="30" inputmode="numeric" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field span-two">
                <label for="wa-token"><?= esc(lang('Admin.accessToken')) ?></label>
                <input id="wa-token" name="whatsapp_access_token" type="password" maxlength="4096" autocomplete="new-password" <?= $canManage ? '' : 'disabled' ?>>
                <p class="hint"><?= esc(lang($settings['whatsapp_secret_set'] ? 'Admin.secretPresent' : 'Admin.secretMissing')) ?> <?= esc(lang('Admin.secretKept')) ?></p>
            </div>
            <div class="field">
                <label for="wa-template"><?= esc(lang('Admin.templateName')) ?></label>
                <input id="wa-template" name="whatsapp_template_name" type="text" value="<?= esc($settings['whatsapp_template_name'], 'attr') ?>" maxlength="512" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="wa-language"><?= esc(lang('Admin.templateLanguage')) ?></label>
                <input id="wa-language" name="whatsapp_template_language" type="text" value="<?= esc($settings['whatsapp_template_language'], 'attr') ?>" maxlength="10" placeholder="ht" <?= $canManage ? '' : 'disabled' ?>>
            </div>
        </div>
        <?php if ($canManage): ?>
            <div class="channel-actions">
                <button type="button" class="btn btn-ghost" data-test-channel="whatsapp" data-channel-label="<?= esc(lang('Admin.whatsappTitle'), 'attr') ?>" data-destination-label="<?= esc(lang('Admin.testPhoneLabel'), 'attr') ?>"><?= esc(lang('Admin.testAndValidate')) ?></button>
                <button type="button" class="btn btn-danger-quiet" data-delete-channel="whatsapp" data-channel-label="<?= esc(lang('Admin.whatsappTitle'), 'attr') ?>" <?= $settings['whatsapp_configured'] ? '' : 'hidden' ?>><?= esc(lang('Admin.deleteChannel')) ?></button>
            </div>
        <?php endif; ?>
    </section>

    <section class="settings-card">
        <header class="settings-card-head">
            <div>
                <h2><?= esc(lang('Admin.smsTitle')) ?></h2>
                <p><?= esc(lang('Admin.smsHelp')) ?></p>
            </div>
            <div class="channel-head-tools">
                <span class="channel-state <?= $settings['sms_validation_status'] === 'valid' ? 'is-valid' : 'is-untested' ?>" data-channel-status="sms">
                    <?= esc(lang($settings['sms_validation_status'] === 'valid' ? 'Admin.channelValid' : 'Admin.channelUntested')) ?>
                </span>
                <label class="switch-row">
                    <input type="checkbox" name="sms_enabled" value="1" <?= $settings['sms_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                    <span><?= esc(lang('Admin.useSms')) ?></span>
                </label>
            </div>
        </header>
        <div class="form-grid">
            <div class="field">
                <label for="twilio-account"><?= esc(lang('Admin.accountSid')) ?></label>
                <input id="twilio-account" name="twilio_account_sid" type="text" value="<?= esc($settings['twilio_account_sid'], 'attr') ?>" maxlength="40" placeholder="AC..." <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="twilio-token"><?= esc(lang('Admin.authToken')) ?></label>
                <input id="twilio-token" name="twilio_auth_token" type="password" maxlength="256" autocomplete="new-password" <?= $canManage ? '' : 'disabled' ?>>
                <p class="hint"><?= esc(lang($settings['twilio_secret_set'] ? 'Admin.secretPresent' : 'Admin.secretMissing')) ?> <?= esc(lang('Admin.secretKept')) ?></p>
            </div>
            <div class="field">
                <label for="twilio-from"><?= esc(lang('Admin.fromNumber')) ?></label>
                <input id="twilio-from" name="twilio_from_number" type="tel" value="<?= esc($settings['twilio_from_number'], 'attr') ?>" maxlength="20" placeholder="+15551234567" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="twilio-service"><?= esc(lang('Admin.messagingServiceSid')) ?></label>
                <input id="twilio-service" name="twilio_messaging_service_sid" type="text" value="<?= esc($settings['twilio_messaging_service_sid'], 'attr') ?>" maxlength="40" placeholder="MG..." <?= $canManage ? '' : 'disabled' ?>>
            </div>
        </div>
        <p class="form-note"><?= esc(lang('Admin.senderChoiceHelp')) ?></p>
        <?php if ($canManage): ?>
            <div class="channel-actions">
                <button type="button" class="btn btn-ghost" data-test-channel="sms" data-channel-label="<?= esc(lang('Admin.smsTitle'), 'attr') ?>" data-destination-label="<?= esc(lang('Admin.testPhoneLabel'), 'attr') ?>"><?= esc(lang('Admin.testAndValidate')) ?></button>
                <button type="button" class="btn btn-danger-quiet" data-delete-channel="sms" data-channel-label="<?= esc(lang('Admin.smsTitle'), 'attr') ?>" <?= $settings['sms_configured'] ? '' : 'hidden' ?>><?= esc(lang('Admin.deleteChannel')) ?></button>
            </div>
        <?php endif; ?>
    </section>

    <section class="settings-card">
        <header class="settings-card-head">
            <div>
                <h2><?= esc(lang('Admin.emailTitle')) ?></h2>
                <p><?= esc(lang('Admin.emailHelp')) ?></p>
            </div>
            <div class="channel-head-tools">
                <span class="channel-state <?= $settings['email_validation_status'] === 'valid' ? 'is-valid' : 'is-untested' ?>" data-channel-status="email">
                    <?= esc(lang($settings['email_validation_status'] === 'valid' ? 'Admin.channelValid' : 'Admin.channelUntested')) ?>
                </span>
                <label class="switch-row">
                    <input type="checkbox" name="email_enabled" value="1" <?= $settings['email_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                    <span><?= esc(lang('Admin.useEmail')) ?></span>
                </label>
            </div>
        </header>
        <div class="form-grid">
            <div class="field">
                <label for="smtp-host"><?= esc(lang('Admin.smtpHost')) ?></label>
                <input id="smtp-host" name="smtp_host" type="text" value="<?= esc($settings['smtp_host'], 'attr') ?>" maxlength="253" placeholder="mail.example.com" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field field-pair">
                <span>
                    <label for="smtp-port"><?= esc(lang('Admin.smtpPort')) ?></label>
                    <input id="smtp-port" name="smtp_port" type="number" min="1" max="65535" value="<?= esc((string) $settings['smtp_port'], 'attr') ?>" <?= $canManage ? '' : 'disabled' ?>>
                </span>
                <span>
                    <label for="smtp-crypto"><?= esc(lang('Admin.smtpSecurity')) ?></label>
                    <select id="smtp-crypto" name="smtp_crypto" <?= $canManage ? '' : 'disabled' ?>>
                        <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', '' => lang('Admin.disabled')] as $value => $label): ?>
                            <option value="<?= esc($value, 'attr') ?>" <?= $settings['smtp_crypto'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </div>
            <div class="field">
                <label for="smtp-user"><?= esc(lang('Admin.smtpUser')) ?></label>
                <input id="smtp-user" name="smtp_user" type="text" value="<?= esc($settings['smtp_user'], 'attr') ?>" maxlength="254" autocomplete="username" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="smtp-password"><?= esc(lang('Admin.smtpPassword')) ?></label>
                <input id="smtp-password" name="smtp_password" type="password" maxlength="4096" autocomplete="new-password" <?= $canManage ? '' : 'disabled' ?>>
                <p class="hint"><?= esc(lang($settings['smtp_secret_set'] ? 'Admin.secretPresent' : 'Admin.secretMissing')) ?> <?= esc(lang('Admin.secretKept')) ?></p>
            </div>
            <div class="field">
                <label for="from-address"><?= esc(lang('Admin.fromAddress')) ?></label>
                <input id="from-address" name="email_from_address" type="email" value="<?= esc($settings['email_from_address'], 'attr') ?>" maxlength="254" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="from-name"><?= esc(lang('Admin.fromName')) ?></label>
                <input id="from-name" name="email_from_name" type="text" value="<?= esc($settings['email_from_name'], 'attr') ?>" maxlength="160" <?= $canManage ? '' : 'disabled' ?>>
            </div>
        </div>
        <?php if ($canManage): ?>
            <div class="channel-actions">
                <button type="button" class="btn btn-ghost" data-test-channel="email" data-channel-label="<?= esc(lang('Admin.emailTitle'), 'attr') ?>" data-destination-label="<?= esc(lang('Admin.testEmailLabel'), 'attr') ?>"><?= esc(lang('Admin.testAndValidate')) ?></button>
                <button type="button" class="btn btn-danger-quiet" data-delete-channel="email" data-channel-label="<?= esc(lang('Admin.emailTitle'), 'attr') ?>" <?= $settings['email_configured'] ? '' : 'hidden' ?>><?= esc(lang('Admin.deleteChannel')) ?></button>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
        <div class="sticky-submit">
            <button type="submit" class="btn"><?= esc(lang('Admin.saveChannels')) ?></button>
        </div>
    <?php endif; ?>
</form>

<?php if ($canManage): ?>
    <dialog class="admin-dialog" id="channel-test-dialog" aria-labelledby="channel-test-title">
        <div class="dialog-panel">
            <div data-test-entry>
                <p class="eyebrow"><?= esc(lang('Admin.channelTestEyebrow')) ?></p>
                <h2 id="channel-test-title"><?= esc(lang('Admin.channelTestTitle')) ?></h2>
                <p class="dialog-lead" data-test-description><?= esc(lang('Admin.channelTestLead')) ?></p>
                <div class="field">
                    <label for="test-destination" data-test-destination-label><?= esc(lang('Admin.testDestination')) ?></label>
                    <input id="test-destination" type="text" name="test_destination" autocomplete="off" required>
                    <p class="hint"><?= esc(lang('Admin.channelTestCodeHelp')) ?></p>
                </div>
                <div class="dialog-actions">
                    <button type="button" class="btn btn-ghost" data-dialog-close><?= esc(lang('Admin.cancel')) ?></button>
                    <button type="button" class="btn" data-test-submit><?= esc(lang('Admin.runTest')) ?></button>
                </div>
            </div>
            <div class="is-hidden" data-test-result role="status">
                <span class="dialog-result-mark" data-test-mark aria-hidden="true"></span>
                <h2 data-test-title></h2>
                <p data-test-message></p>
                <div class="provider-response is-hidden" data-provider-response>
                    <b><?= esc(lang('Admin.providerResponse')) ?></b>
                    <code data-provider-detail></code>
                </div>
                <div class="dialog-advice is-hidden" data-test-advice-wrap>
                    <b><?= esc(lang('Admin.whatToChange')) ?></b>
                    <p data-test-advice></p>
                </div>
                <button type="button" class="btn" data-dialog-close><?= esc(lang('Admin.close')) ?></button>
            </div>
        </div>
    </dialog>

    <dialog class="admin-dialog" id="channel-delete-dialog" aria-labelledby="channel-delete-title">
        <form method="post" class="dialog-panel" data-delete-form>
            <?= csrf_field() ?>
            <p class="eyebrow"><?= esc(lang('Admin.deleteChannelEyebrow')) ?></p>
            <h2 id="channel-delete-title"><?= esc(lang('Admin.deleteChannelTitle')) ?></h2>
            <p class="dialog-lead" data-delete-message></p>
            <p class="danger-note"><?= esc(lang('Admin.deleteChannelWarning')) ?></p>
            <div class="dialog-actions">
                <button type="button" class="btn btn-ghost" data-dialog-close><?= esc(lang('Admin.cancel')) ?></button>
                <button type="submit" class="btn btn-danger"><?= esc(lang('Admin.confirmDeleteChannel')) ?></button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
<?= $this->endSection() ?>

<?php if ($canManage): ?>
    <?= $this->section('scripts') ?>
    <script
        src="<?= esc(versioned_asset('/assets/admin-communications.js'), 'attr') ?>"
        data-valid-label="<?= esc(lang('Admin.channelValid'), 'attr') ?>"
        data-failed-label="<?= esc(lang('Admin.channelFailed'), 'attr') ?>"
        data-testing-label="<?= esc(lang('Admin.channelTesting'), 'attr') ?>"
        data-untested-label="<?= esc(lang('Admin.channelUntested'), 'attr') ?>"
        data-visible-label="<?= esc(lang('Admin.visibleToCitizens'), 'attr') ?>"
        data-hidden-label="<?= esc(lang('Admin.hiddenFromCitizens'), 'attr') ?>"
        data-run-label="<?= esc(lang('Admin.runTest'), 'attr') ?>"
        data-delete-template="<?= esc(lang('Admin.deleteChannelConfirm', ['{channel}']), 'attr') ?>"
        defer
    ></script>
    <?= $this->endSection() ?>
<?php endif; ?>
