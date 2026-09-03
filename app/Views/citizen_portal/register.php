<?= $this->extend('layouts/public') ?>

<?= $this->section('main') ?>
<?php
$slug = rawurlencode((string) $tenant['slug']);
?>
<noscript>
    <div class="alert" role="alert"><?= esc(lang('CitizenPortal.noscriptText')) ?></div>
</noscript>

<?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    <div class="alert" role="alert"><?= esc($errorMessage) ?></div>
<?php endif; ?>

<div class="steps" aria-hidden="true">
    <i data-step="1"></i><i data-step="2"></i><i data-step="3"></i><i data-step="4"></i>
</div>
<p class="sr-only" id="wizard-progress" aria-live="polite"></p>

<form
    id="wizard"
    class="wizard"
    method="post"
    action="/inscription/<?= $slug ?>"
    enctype="multipart/form-data"
    novalidate
    data-slug="<?= esc((string) $tenant['slug'], 'attr') ?>"
    data-locale="<?= esc($locale, 'attr') ?>"
>
    <?= csrf_field() ?>
    <input type="hidden" name="consent" id="consent-value" value="">

    <!-- 01 · entrée -->
    <section class="screen is-active screen-center" id="s-intro" data-group="1">
        <div class="spacer"></div>
        <h1><?= esc(lang('CitizenPortal.introTitle')) ?></h1>
        <p class="lead"><?= esc(lang('CitizenPortal.introLead')) ?></p>
        <ul class="trust">
            <li><?= view('partials/icon_check') ?><span><?= esc(lang('CitizenPortal.introPoint1')) ?></span></li>
            <li><?= view('partials/icon_check') ?><span><?= esc(lang('CitizenPortal.introPoint2')) ?></span></li>
            <li><?= view('partials/icon_check') ?><span><?= esc(lang('CitizenPortal.introPoint3')) ?></span></li>
        </ul>
        <div class="spacer"></div>
        <button type="button" class="btn" data-go="s-ninu"><?= esc(lang('CitizenPortal.introStart')) ?></button>
    </section>

    <!-- 02 · identité -->
    <section class="screen" id="s-ninu" data-group="1">
        <p class="eyebrow"><?= esc(lang('CitizenPortal.stepOfFour', [1])) ?></p>
        <h1><?= esc(lang('CitizenPortal.ninuTitle')) ?></h1>
        <p class="lead"><?= esc(lang('CitizenPortal.ninuLead')) ?></p>

        <div class="field">
            <label for="ninu"><?= esc(lang('CitizenPortal.ninuLabel')) ?></label>
            <input
                id="ninu"
                name="ninu"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                maxlength="40"
                aria-describedby="ninu-hint"
            >
            <p class="hint" id="ninu-hint"><?= esc(lang('CitizenPortal.ninuHint')) ?></p>
            <p class="field-error" data-error-for="ninu" hidden></p>
        </div>

        <div class="spacer"></div>
        <button type="button" class="btn" data-validate="ninu" data-go="s-phone"><?= esc(lang('CitizenPortal.continue')) ?></button>
    </section>

    <!-- 03 · contact -->
    <section class="screen" id="s-phone" data-group="2">
        <p class="eyebrow"><?= esc(lang('CitizenPortal.stepOfFour', [2])) ?></p>
        <h1><?= esc(lang('CitizenPortal.phoneTitle')) ?></h1>
        <p class="lead"><?= esc(lang('CitizenPortal.phoneLead')) ?></p>

        <div class="field">
            <label for="phone-local"><?= esc(lang('CitizenPortal.phoneLabel')) ?></label>
            <div class="telrow">
                <span class="prefix" aria-hidden="true">+509</span>
                <input
                    id="phone-local"
                    type="tel"
                    inputmode="numeric"
                    autocomplete="tel-national"
                    maxlength="11"
                    aria-describedby="phone-hint"
                >
            </div>
            <input type="hidden" name="phone" id="phone">
            <p class="hint" id="phone-hint"><?= esc(lang('CitizenPortal.phoneHint')) ?></p>
            <p class="field-error" data-error-for="phone-local" hidden></p>
        </div>

        <div class="spacer"></div>
        <div class="btn-row">
            <button type="button" class="btn btn-ghost" data-go="s-ninu"><?= esc(lang('CitizenPortal.back')) ?></button>
            <button type="button" class="btn" id="send-code"><?= esc(lang('CitizenPortal.phoneSend')) ?></button>
        </div>
    </section>

    <!-- 04 · code -->
    <section class="screen" id="s-code" data-group="2">
        <p class="eyebrow" id="code-eyebrow"><?= esc(lang('CitizenPortal.stepOfFour', [2])) ?></p>
        <h1><?= esc(lang('CitizenPortal.codeTitle')) ?></h1>
        <p class="lead" id="code-lead"></p>

        <div class="otp" id="otp-inputs">
            <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="1" aria-label="<?= esc(lang('CitizenPortal.codeDigit', [1]), 'attr') ?>">
            <input type="text" inputmode="numeric" maxlength="1" aria-label="<?= esc(lang('CitizenPortal.codeDigit', [2]), 'attr') ?>">
            <input type="text" inputmode="numeric" maxlength="1" aria-label="<?= esc(lang('CitizenPortal.codeDigit', [3]), 'attr') ?>">
            <input type="text" inputmode="numeric" maxlength="1" aria-label="<?= esc(lang('CitizenPortal.codeDigit', [4]), 'attr') ?>">
            <input type="text" inputmode="numeric" maxlength="1" aria-label="<?= esc(lang('CitizenPortal.codeDigit', [5]), 'attr') ?>">
            <input type="text" inputmode="numeric" maxlength="1" aria-label="<?= esc(lang('CitizenPortal.codeDigit', [6]), 'attr') ?>">
        </div>

        <div class="otpmeta">
            <span id="code-expiry"></span>
            <button type="button" id="code-resend" disabled></button>
        </div>

        <p class="field-error" data-error-for="code" role="alert" hidden></p>
        <span class="note"><?= esc(lang('CitizenPortal.codeNote')) ?></span>

        <details class="field">
            <summary><?= esc(lang('CitizenPortal.codeMissingTitle')) ?></summary>
            <div class="channels">
                <button type="button" data-channel="whatsapp"><?= esc(lang('CitizenPortal.codeResendWhatsApp')) ?></button>
                <button type="button" data-channel="email"><?= esc(lang('CitizenPortal.codeUseEmail')) ?></button>
            </div>
            <div class="field" id="email-field" hidden>
                <label for="email"><?= esc(lang('CitizenPortal.emailLabel')) ?></label>
                <input id="email" name="email" type="email" autocomplete="email" maxlength="191">
            </div>
        </details>

        <div class="spacer"></div>
        <div class="btn-row">
            <button type="button" class="btn btn-ghost" data-go="s-phone"><?= esc(lang('CitizenPortal.back')) ?></button>
            <button type="button" class="btn" id="verify-code"><?= esc(lang('CitizenPortal.continue')) ?></button>
        </div>
    </section>

    <!-- 05 · contact confirmé -->
    <section class="screen screen-center" id="s-verified" data-group="2">
        <div class="tick" aria-hidden="true"><?= view('partials/icon_tick') ?></div>
        <h1><?= esc(lang('CitizenPortal.verifiedTitle')) ?></h1>
        <p class="lead" id="verified-phone"></p>
        <div class="spacer"></div>
        <button type="button" class="btn" data-go="s-why"><?= esc(lang('CitizenPortal.continue')) ?></button>
    </section>

    <!-- 06 · pourquoi les pièces -->
    <section class="screen" id="s-why" data-group="3">
        <p class="eyebrow"><?= esc(lang('CitizenPortal.stepOfFour', [3])) ?></p>
        <h1><?= esc(lang('CitizenPortal.whyTitle')) ?></h1>
        <p class="lead"><?= esc(lang('CitizenPortal.whyLead')) ?></p>

        <div class="checks">
            <p class="checkline" data-piece-check="cin_front"><span class="box" aria-hidden="true"></span><span><?= esc(lang('CitizenPortal.pieceFront')) ?></span></p>
            <p class="checkline" data-piece-check="cin_back"><span class="box" aria-hidden="true"></span><span><?= esc(lang('CitizenPortal.pieceBack')) ?></span></p>
            <p class="checkline" data-piece-check="portrait"><span class="box" aria-hidden="true"></span><span><?= esc(lang('CitizenPortal.piecePortrait')) ?></span></p>
        </div>

        <span class="note"><?= esc(lang('CitizenPortal.whyNote')) ?></span>
        <div class="spacer"></div>
        <button type="button" class="btn" data-go="s-cin_front"><?= esc(lang('CitizenPortal.whyStart')) ?></button>
    </section>

    <?php
    $pieces = [
        'cin_front' => ['step' => 1, 'title' => 'frontTitle', 'guide' => 'frontGuide', 'art' => 'card', 'next' => 's-cin_back'],
        'cin_back' => ['step' => 2, 'title' => 'backTitle', 'guide' => 'backGuide', 'art' => 'card', 'next' => 's-portrait'],
        'portrait' => ['step' => 3, 'title' => 'portraitTitle', 'guide' => 'portraitGuide', 'art' => 'face', 'next' => 's-consent'],
    ];
    ?>
    <?php foreach ($pieces as $field => $piece): ?>
        <!-- pièce <?= esc($field) ?> -->
        <section class="screen" id="s-<?= esc($field) ?>" data-group="3" data-piece="<?= esc($field, 'attr') ?>" data-next="<?= esc($piece['next'], 'attr') ?>">
            <p class="eyebrow"><?= esc(lang('CitizenPortal.pieceOfThree', [$piece['step']])) ?></p>
            <h1 data-role="title"><?= esc(lang('CitizenPortal.' . $piece['title'])) ?></h1>

            <div data-role="capture">
                <div class="cardart<?= $piece['art'] === 'face' ? ' is-portrait' : '' ?>" aria-hidden="true">
                    <?= view($piece['art'] === 'face' ? 'partials/icon_face' : 'partials/icon_card') ?>
                    <span><?= esc(lang('CitizenPortal.' . $piece['guide'])) ?></span>
                </div>
                <?php if ($field === 'portrait'): ?>
                    <div class="checks">
                        <p class="checkline is-tip"><span class="box" aria-hidden="true"></span><span><?= esc(lang('CitizenPortal.selfieTip1')) ?></span></p>
                        <p class="checkline is-tip"><span class="box" aria-hidden="true"></span><span><?= esc(lang('CitizenPortal.selfieTip2')) ?></span></p>
                        <p class="checkline is-tip"><span class="box" aria-hidden="true"></span><span><?= esc(lang('CitizenPortal.selfieTip3')) ?></span></p>
                    </div>
                <?php endif; ?>
            </div>

            <figure class="shot" data-role="preview" hidden>
                <figcaption><?= esc(lang('CitizenPortal.' . $piece['title'])) ?></figcaption>
                <img alt="" data-role="preview-image">
            </figure>

            <p class="field-error" data-error-for="<?= esc($field, 'attr') ?>" role="alert" hidden></p>

            <input
                type="file"
                class="sr-only"
                name="<?= esc($field, 'attr') ?>"
                id="file-<?= esc($field, 'attr') ?>"
                accept="image/jpeg,image/png,image/webp"
                <?= $field === 'portrait' ? 'capture="user"' : 'capture="environment"' ?>
            >

            <div class="spacer"></div>

            <div class="btn-stack" data-role="capture-actions">
                <button type="button" class="btn" data-shoot="<?= esc($field, 'attr') ?>"><?= esc(lang('CitizenPortal.takePhoto')) ?></button>
                <button type="button" class="btn btn-ghost" data-pick="<?= esc($field, 'attr') ?>"><?= esc(lang('CitizenPortal.choosePhoto')) ?></button>
            </div>

            <div class="btn-row" data-role="review-actions" hidden>
                <button type="button" class="btn btn-ghost" data-retake="<?= esc($field, 'attr') ?>"><?= esc(lang('CitizenPortal.retake')) ?></button>
                <button type="button" class="btn" data-accept="<?= esc($field, 'attr') ?>"><?= esc(lang('CitizenPortal.usePhoto')) ?></button>
            </div>
        </section>
    <?php endforeach; ?>

    <!-- 11 · consentement et envoi -->
    <section class="screen" id="s-consent" data-group="4">
        <p class="eyebrow"><?= esc(lang('CitizenPortal.stepOfFour', [4])) ?></p>
        <h1><?= esc(lang('CitizenPortal.consentTitle')) ?></h1>

        <dl class="kv">
            <dt><?= esc(lang('CitizenPortal.summaryNumber')) ?></dt>
            <dd id="summary-ninu"><?= esc(lang('CitizenPortal.summaryEmpty')) ?></dd>
            <dt><?= esc(lang('CitizenPortal.summaryPhone')) ?></dt>
            <dd id="summary-phone"><?= esc(lang('CitizenPortal.summaryEmpty')) ?></dd>
            <dt><?= esc(lang('CitizenPortal.summaryPhotos')) ?></dt>
            <dd id="summary-photos"><?= esc(lang('CitizenPortal.summaryNoPhoto')) ?></dd>
        </dl>

        <label class="consent">
            <input type="checkbox" id="consent-1">
            <span><?= esc(lang('CitizenPortal.consentOne')) ?></span>
        </label>
        <label class="consent">
            <input type="checkbox" id="consent-2">
            <span><?= esc(lang('CitizenPortal.consentTwo')) ?></span>
        </label>

        <p class="field-error" data-error-for="consent" role="alert" hidden></p>

        <div class="progress" id="upload-progress" hidden>
            <div class="progress-head">
                <span><?= esc(lang('CitizenPortal.sending')) ?></span>
                <span id="upload-percent">0 %</span>
            </div>
            <div class="progress-track"><div class="progress-fill" id="upload-fill"></div></div>
        </div>

        <div class="spacer"></div>
        <div class="btn-row">
            <button type="button" class="btn btn-ghost" data-go="s-portrait"><?= esc(lang('CitizenPortal.back')) ?></button>
            <button type="submit" class="btn" id="submit-file"><?= esc(lang('CitizenPortal.submitSend')) ?></button>
        </div>
    </section>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script type="application/json" id="wizard-strings"><?= json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= esc(versioned_asset('/assets/portal-wizard.js'), 'attr') ?>" defer></script>
<?= $this->endSection() ?>
