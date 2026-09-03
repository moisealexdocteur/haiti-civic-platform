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
<?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    <p class="alert" role="alert"><?= esc($errorMessage) ?></p>
<?php endif; ?>

<form method="post" action="/admin/communications" class="settings-form" autocomplete="off">
    <?= csrf_field() ?>
    <section class="settings-card">
        <header class="settings-card-head">
            <div>
                <h2><?= esc(lang('Admin.whatsappTitle')) ?></h2>
                <p><?= esc(lang('Admin.whatsappHelp')) ?></p>
            </div>
            <label class="switch-row">
                <input type="checkbox" name="whatsapp_enabled" value="1" <?= $settings['whatsapp_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                <span><?= esc(lang('Admin.useWhatsapp')) ?></span>
            </label>
        </header>
        <div class="form-grid">
            <div class="field">
                <label for="graph-version"><?= esc(lang('Admin.graphVersion')) ?></label>
                <input id="graph-version" name="whatsapp_graph_version" value="<?= esc($settings['whatsapp_graph_version'], 'attr') ?>" maxlength="20" placeholder="v26.0" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="wa-phone-id"><?= esc(lang('Admin.phoneNumberId')) ?></label>
                <input id="wa-phone-id" name="whatsapp_phone_number_id" value="<?= esc($settings['whatsapp_phone_number_id'], 'attr') ?>" maxlength="30" inputmode="numeric" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field span-two">
                <label for="wa-token"><?= esc(lang('Admin.accessToken')) ?></label>
                <input id="wa-token" name="whatsapp_access_token" type="password" maxlength="4096" autocomplete="new-password" <?= $canManage ? '' : 'disabled' ?>>
                <p class="hint"><?= esc(lang($settings['whatsapp_secret_set'] ? 'Admin.secretPresent' : 'Admin.secretMissing')) ?> <?= esc(lang('Admin.secretKept')) ?></p>
            </div>
            <div class="field">
                <label for="wa-template"><?= esc(lang('Admin.templateName')) ?></label>
                <input id="wa-template" name="whatsapp_template_name" value="<?= esc($settings['whatsapp_template_name'], 'attr') ?>" maxlength="512" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="wa-language"><?= esc(lang('Admin.templateLanguage')) ?></label>
                <input id="wa-language" name="whatsapp_template_language" value="<?= esc($settings['whatsapp_template_language'], 'attr') ?>" maxlength="10" placeholder="ht" <?= $canManage ? '' : 'disabled' ?>>
            </div>
        </div>
    </section>

    <section class="settings-card">
        <header class="settings-card-head">
            <div>
                <h2><?= esc(lang('Admin.smsTitle')) ?></h2>
                <p><?= esc(lang('Admin.smsHelp')) ?></p>
            </div>
            <label class="switch-row">
                <input type="checkbox" name="sms_enabled" value="1" <?= $settings['sms_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                <span><?= esc(lang('Admin.useSms')) ?></span>
            </label>
        </header>
        <div class="form-grid">
            <div class="field">
                <label for="twilio-account"><?= esc(lang('Admin.accountSid')) ?></label>
                <input id="twilio-account" name="twilio_account_sid" value="<?= esc($settings['twilio_account_sid'], 'attr') ?>" maxlength="40" placeholder="AC..." <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="twilio-token"><?= esc(lang('Admin.authToken')) ?></label>
                <input id="twilio-token" name="twilio_auth_token" type="password" maxlength="256" autocomplete="new-password" <?= $canManage ? '' : 'disabled' ?>>
                <p class="hint"><?= esc(lang($settings['twilio_secret_set'] ? 'Admin.secretPresent' : 'Admin.secretMissing')) ?> <?= esc(lang('Admin.secretKept')) ?></p>
            </div>
            <div class="field">
                <label for="twilio-from"><?= esc(lang('Admin.fromNumber')) ?></label>
                <input id="twilio-from" name="twilio_from_number" value="<?= esc($settings['twilio_from_number'], 'attr') ?>" maxlength="20" placeholder="+15551234567" <?= $canManage ? '' : 'disabled' ?>>
            </div>
            <div class="field">
                <label for="twilio-service"><?= esc(lang('Admin.messagingServiceSid')) ?></label>
                <input id="twilio-service" name="twilio_messaging_service_sid" value="<?= esc($settings['twilio_messaging_service_sid'], 'attr') ?>" maxlength="40" placeholder="MG..." <?= $canManage ? '' : 'disabled' ?>>
            </div>
        </div>
        <p class="form-note"><?= esc(lang('Admin.senderChoiceHelp')) ?></p>
    </section>

    <section class="settings-card">
        <header class="settings-card-head">
            <div>
                <h2><?= esc(lang('Admin.emailTitle')) ?></h2>
                <p><?= esc(lang('Admin.emailHelp')) ?></p>
            </div>
            <label class="switch-row">
                <input type="checkbox" name="email_enabled" value="1" <?= $settings['email_enabled'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
                <span><?= esc(lang('Admin.useEmail')) ?></span>
            </label>
        </header>
        <div class="form-grid">
            <div class="field">
                <label for="smtp-host"><?= esc(lang('Admin.smtpHost')) ?></label>
                <input id="smtp-host" name="smtp_host" value="<?= esc($settings['smtp_host'], 'attr') ?>" maxlength="253" placeholder="mail.example.com" <?= $canManage ? '' : 'disabled' ?>>
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
                <input id="smtp-user" name="smtp_user" value="<?= esc($settings['smtp_user'], 'attr') ?>" maxlength="254" autocomplete="username" <?= $canManage ? '' : 'disabled' ?>>
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
                <input id="from-name" name="email_from_name" value="<?= esc($settings['email_from_name'], 'attr') ?>" maxlength="160" <?= $canManage ? '' : 'disabled' ?>>
            </div>
        </div>
    </section>

    <?php if ($canManage): ?>
        <div class="sticky-submit">
            <button type="submit" class="btn"><?= esc(lang('Admin.saveChannels')) ?></button>
        </div>
    <?php endif; ?>
</form>
<?= $this->endSection() ?>
