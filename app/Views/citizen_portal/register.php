<!doctype html>
<html lang="<?= esc($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc(lang('CitizenPortal.registerTitle')) ?> | <?= esc($tenant['name']) ?></title>
    <link rel="stylesheet" href="/assets/citizen-portal.css">
    <link rel="stylesheet" href="/assets/citizen-portal-submit.css">
    <link rel="stylesheet" href="/assets/citizen-otp.css">
    <link rel="stylesheet" href="/assets/citizen-registration-wizard.css">
    <script defer src="/assets/citizen-otp.js"></script>
    <script defer src="/assets/citizen-registration-wizard.js"></script>
</head>
<body>
    <main class="shell shell-narrow">
        <header class="topbar">
            <a class="back-link" href="/?lang=<?= esc($locale) ?>">← <?= esc(lang('CitizenPortal.backHome')) ?></a>
            <nav class="language-switch" aria-label="Language">
                <a href="?lang=fr" class="<?= $locale === 'fr' ? 'active' : '' ?>">FR</a>
                <a href="?lang=ht" class="<?= $locale === 'ht' ? 'active' : '' ?>">HT</a>
            </nav>
        </header>

        <section class="registration-head">
            <p class="eyebrow"><?= esc(lang('CitizenPortal.registerEyebrow')) ?></p>
            <h1><?= esc(lang('CitizenPortal.registerTitle')) ?></h1>
            <p class="lead tenant-name">
                <?= esc(lang('CitizenPortal.forOrganization', [$tenant['name']])) ?>
            </p>
            <p class="field-help registration-intro">
                <?= esc(lang('CitizenPortal.registrationIntro')) ?>
            </p>
        </section>

        <section class="wizard-progress-card" aria-label="<?= esc(lang('CitizenPortal.progressionLabel')) ?>">
            <div class="wizard-progress-copy">
                <span
                    data-progress-label
                    data-template="<?= esc(lang('CitizenPortal.progressTemplate')) ?>"
                ><?= esc(lang('CitizenPortal.progressInitial')) ?></span>
                <strong data-progress-percent>0%</strong>
            </div>
            <div class="wizard-progress-track" aria-hidden="true">
                <span class="wizard-progress-fill" data-progress-fill></span>
            </div>
            <ol class="progress">
                <li data-progress-step="1"><span>1</span><?= esc(lang('CitizenPortal.progressIdentity')) ?></li>
                <li data-progress-step="2"><span>2</span><?= esc(lang('CitizenPortal.progressContact')) ?></li>
                <li data-progress-step="3"><span>3</span><?= esc(lang('CitizenPortal.progressDocuments')) ?></li>
                <li data-progress-step="4"><span>4</span><?= esc(lang('CitizenPortal.progressConfirm')) ?></li>
            </ol>
        </section>

        <?php if ($errorMessage !== null): ?>
            <div class="alert" role="alert">
                <?= esc($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div class="preview-notice" role="status">
            <?= esc(lang('CitizenPortal.secureSubmissionNotice')) ?>
        </div>

        <form
            id="citizen-registration-form"
            class="registration-card"
            aria-label="<?= esc(lang('CitizenPortal.registerTitle')) ?>"
            method="post"
            enctype="multipart/form-data"
            action="/inscription/<?= esc($tenant['slug']) ?>?lang=<?= esc($locale) ?>"
        >
            <?= csrf_field() ?>

            <section class="form-section wizard-step" data-wizard-step="1">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h2><?= esc(lang('CitizenPortal.identitySection')) ?></h2>
                        <p><?= esc(lang('CitizenPortal.identityStepLead')) ?></p>
                    </div>
                </div>

                <div class="guide-card">
                    <span class="guide-icon" aria-hidden="true">🪪</span>
                    <div>
                        <strong><?= esc(lang('CitizenPortal.identityGuideTitle')) ?></strong>
                        <p><?= esc(lang('CitizenPortal.identityGuideText')) ?></p>
                    </div>
                </div>

                <label for="ninu"><?= esc(lang('CitizenPortal.ninuLabel')) ?></label>
                <input
                    id="ninu"
                    name="ninu"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="96"
                    required
                >
                <p class="field-help"><?= esc(lang('CitizenPortal.ninuHelp')) ?></p>
                <p
                    class="step-validation"
                    data-identity-status
                    data-required="<?= esc(lang('CitizenPortal.identityRequiredStatus')) ?>"
                    data-ready="<?= esc(lang('CitizenPortal.identityReadyStatus')) ?>"
                    data-complete="<?= esc(lang('CitizenPortal.identityCompleteStatus')) ?>"
                    role="status"
                ></p>

                <div class="step-actions">
                    <span></span>
                    <button type="button" class="primary-button" data-step-next="2">
                        <?= esc(lang('CitizenPortal.nextStep')) ?>
                    </button>
                </div>
            </section>

            <section
                class="form-section wizard-step"
                data-wizard-step="2"
                data-otp-root
                data-request-url="/inscription/<?= esc($tenant['slug']) ?>/otp/demander?lang=<?= esc($locale) ?>"
                data-verify-url="/inscription/<?= esc($tenant['slug']) ?>/otp/verifier?lang=<?= esc($locale) ?>"
            >
                <div class="step-heading">
                    <span class="step-number">2</span>
                    <div>
                        <h2><?= esc(lang('CitizenPortal.contactSection')) ?></h2>
                        <p><?= esc(lang('CitizenPortal.contactStepLead')) ?></p>
                    </div>
                </div>

                <div class="guide-card">
                    <span class="guide-icon" aria-hidden="true">🔐</span>
                    <div>
                        <strong><?= esc(lang('CitizenPortal.contactGuideTitle')) ?></strong>
                        <p><?= esc(lang('CitizenPortal.contactGuideText')) ?></p>
                    </div>
                </div>

                <button type="button" class="help-link" data-dialog-open="why-contact-dialog">
                    ⓘ <?= esc(lang('CitizenPortal.contactWhyButton')) ?>
                </button>

                <label for="phone"><?= esc(lang('CitizenPortal.phoneLabel')) ?></label>
                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    maxlength="24"
                    autocomplete="tel"
                    placeholder="<?= esc(lang('CitizenPortal.phonePlaceholder')) ?>"
                    required
                >

                <label for="email"><?= esc(lang('CitizenPortal.emailLabel')) ?></label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    maxlength="254"
                    autocomplete="email"
                    placeholder="<?= esc(lang('CitizenPortal.emailPlaceholder')) ?>"
                >

                <p class="field-help"><?= esc(lang('CitizenPortal.contactVerificationHelp')) ?></p>

                <div class="otp-actions">
                    <button
                        type="button"
                        class="secondary-button"
                        data-otp-request
                    >
                        <?= esc(lang('CitizenPortal.requestOtp')) ?>
                    </button>
                </div>

                <div class="otp-code-panel" data-otp-code-panel hidden>
                    <label for="otp_code"><?= esc(lang('CitizenPortal.otpCodeLabel')) ?></label>
                    <div class="otp-code-row">
                        <input
                            id="otp_code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            data-otp-code
                        >
                        <button
                            type="button"
                            class="secondary-button"
                            data-otp-verify
                        >
                            <?= esc(lang('CitizenPortal.verifyOtp')) ?>
                        </button>
                    </div>
                </div>

                <p
                    class="otp-status"
                    data-otp-status
                    role="status"
                    aria-live="polite"
                ></p>
                <p
                    class="step-validation"
                    data-contact-step-status
                    data-required="<?= esc(lang('CitizenPortal.contactRequiredStatus')) ?>"
                    data-complete="<?= esc(lang('CitizenPortal.contactCompleteStatus')) ?>"
                    role="status"
                ></p>

                <div class="step-actions">
                    <button type="button" class="secondary-button" data-step-back="1">
                        <?= esc(lang('CitizenPortal.backStep')) ?>
                    </button>
                    <button type="button" class="primary-button" data-step-next="3" data-contact-continue disabled>
                        <?= esc(lang('CitizenPortal.nextStep')) ?>
                    </button>
                </div>
            </section>

            <section class="form-section wizard-step" data-wizard-step="3">
                <div class="step-heading">
                    <span class="step-number">3</span>
                    <div>
                        <h2><?= esc(lang('CitizenPortal.documentsSection')) ?></h2>
                        <p><?= esc(lang('CitizenPortal.documentsStepLead')) ?></p>
                    </div>
                </div>

                <div class="guide-card">
                    <span class="guide-icon" aria-hidden="true">📷</span>
                    <div>
                        <strong><?= esc(lang('CitizenPortal.documentsGuideTitle')) ?></strong>
                        <p><?= esc(lang('CitizenPortal.documentsGuideText')) ?></p>
                    </div>
                </div>

                <button type="button" class="help-link" data-dialog-open="why-documents-dialog">
                    ⓘ <?= esc(lang('CitizenPortal.documentsWhyButton')) ?>
                </button>

                <div class="guided-upload-list">
                    <label class="guided-upload" for="cin_front" data-upload-card>
                        <span class="upload-step-number">1</span>
                        <span class="upload-copy">
                            <strong><?= esc(lang('CitizenPortal.cinFront')) ?></strong>
                            <p><?= esc(lang('CitizenPortal.cinFrontPurpose')) ?></p>
                            <span>JPG · PNG · PDF · <?= esc(lang('CitizenPortal.maxFiveMb')) ?></span>
                            <input
                                id="cin_front"
                                name="cin_front"
                                type="file"
                                accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf"
                                required
                            >
                            <span
                                class="upload-file-status"
                                data-file-status
                                data-prefix="<?= esc(lang('CitizenPortal.fileSelectedPrefix')) ?>"
                            ></span>
                        </span>
                    </label>

                    <label class="guided-upload" for="cin_back" data-upload-card>
                        <span class="upload-step-number">2</span>
                        <span class="upload-copy">
                            <strong><?= esc(lang('CitizenPortal.cinBack')) ?></strong>
                            <p><?= esc(lang('CitizenPortal.cinBackPurpose')) ?></p>
                            <span>JPG · PNG · PDF · <?= esc(lang('CitizenPortal.maxFiveMb')) ?></span>
                            <input
                                id="cin_back"
                                name="cin_back"
                                type="file"
                                accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf"
                                required
                            >
                            <span
                                class="upload-file-status"
                                data-file-status
                                data-prefix="<?= esc(lang('CitizenPortal.fileSelectedPrefix')) ?>"
                            ></span>
                        </span>
                    </label>

                    <label class="guided-upload" for="portrait" data-upload-card>
                        <span class="upload-step-number">3</span>
                        <span class="upload-copy">
                            <strong><?= esc(lang('CitizenPortal.portrait')) ?></strong>
                            <p><?= esc(lang('CitizenPortal.portraitPurpose')) ?></p>
                            <span>JPG · PNG · <?= esc(lang('CitizenPortal.maxFiveMb')) ?></span>
                            <input
                                id="portrait"
                                name="portrait"
                                type="file"
                                accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                required
                            >
                            <span
                                class="upload-file-status"
                                data-file-status
                                data-prefix="<?= esc(lang('CitizenPortal.fileSelectedPrefix')) ?>"
                            ></span>
                        </span>
                    </label>
                </div>

                <p class="field-help"><?= esc(lang('CitizenPortal.portraitHelp')) ?></p>
                <p
                    class="step-validation"
                    data-document-step-status
                    data-required="<?= esc(lang('CitizenPortal.documentsRequiredStatus')) ?>"
                    data-complete="<?= esc(lang('CitizenPortal.documentsCompleteStatus')) ?>"
                    role="status"
                ></p>

                <div class="step-actions">
                    <button type="button" class="secondary-button" data-step-back="2">
                        <?= esc(lang('CitizenPortal.backStep')) ?>
                    </button>
                    <button type="button" class="primary-button" data-step-next="4" data-documents-continue disabled>
                        <?= esc(lang('CitizenPortal.nextStep')) ?>
                    </button>
                </div>
            </section>

            <section class="form-section wizard-step" data-wizard-step="4">
                <div class="step-heading">
                    <span class="step-number">4</span>
                    <div>
                        <h2><?= esc(lang('CitizenPortal.reviewSection')) ?></h2>
                        <p><?= esc(lang('CitizenPortal.reviewStepLead')) ?></p>
                    </div>
                </div>

                <div class="review-list">
                    <div class="review-item">
                        <span><?= esc(lang('CitizenPortal.reviewIdentity')) ?></span>
                        <span>✓ <?= esc(lang('CitizenPortal.reviewReady')) ?></span>
                    </div>
                    <div class="review-item">
                        <span><?= esc(lang('CitizenPortal.reviewContact')) ?></span>
                        <span>✓ <?= esc(lang('CitizenPortal.reviewReady')) ?></span>
                    </div>
                    <div class="review-item">
                        <span><?= esc(lang('CitizenPortal.reviewDocuments')) ?></span>
                        <span>✓ <?= esc(lang('CitizenPortal.reviewReady')) ?></span>
                    </div>
                </div>

                <label class="consent-box consent-live" for="consent">
                    <input
                        id="consent"
                        name="consent"
                        type="checkbox"
                        value="1"
                        required
                    >
                    <div>
                        <strong><?= esc(lang('CitizenPortal.consentTitle')) ?></strong>
                        <p><?= esc(lang('CitizenPortal.consentText')) ?></p>
                    </div>
                </label>

                <div class="step-actions">
                    <button type="button" class="secondary-button" data-step-back="3">
                        <?= esc(lang('CitizenPortal.backStep')) ?>
                    </button>
                    <button type="submit" class="primary-button" data-final-submit disabled>
                        <?= esc(lang('CitizenPortal.submitSecure')) ?>
                    </button>
                </div>
            </section>
        </form>

        <footer>
            <?= esc(lang('CitizenPortal.securityFootnote')) ?>
        </footer>
    </main>

    <dialog class="wizard-dialog" id="why-contact-dialog">
        <div class="dialog-content">
            <h2><?= esc(lang('CitizenPortal.contactDialogTitle')) ?></h2>
            <p><?= esc(lang('CitizenPortal.contactDialogTextOne')) ?></p>
            <p><?= esc(lang('CitizenPortal.contactDialogTextTwo')) ?></p>
            <div class="dialog-actions">
                <button type="button" class="secondary-button" data-dialog-close>
                    <?= esc(lang('CitizenPortal.closeDialog')) ?>
                </button>
            </div>
        </div>
    </dialog>

    <dialog class="wizard-dialog" id="why-documents-dialog">
        <div class="dialog-content">
            <h2><?= esc(lang('CitizenPortal.documentsDialogTitle')) ?></h2>
            <p><?= esc(lang('CitizenPortal.documentsDialogIntro')) ?></p>
            <ol>
                <li><?= esc(lang('CitizenPortal.documentsDialogFront')) ?></li>
                <li><?= esc(lang('CitizenPortal.documentsDialogBack')) ?></li>
                <li><?= esc(lang('CitizenPortal.documentsDialogPortrait')) ?></li>
            </ol>
            <div class="dialog-actions">
                <button type="button" class="secondary-button" data-dialog-close>
                    <?= esc(lang('CitizenPortal.closeDialog')) ?>
                </button>
            </div>
        </div>
    </dialog>
</body>
</html>
