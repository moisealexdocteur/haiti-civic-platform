/*
 * Portail citoyen : machine à états du parcours.
 * Un écran, une décision. Aucune chaîne de texte n'est écrite ici :
 * tout vient de la couche de langue via #wizard-strings.
 */
(function () {
    'use strict';

    var form = document.getElementById('wizard');

    if (!form) {
        return;
    }

    var T = {};

    try {
        T = JSON.parse(
            document.getElementById('wizard-strings').textContent
        );
    } catch (error) {
        T = {};
    }

    function t(key, replacement) {
        var value = typeof T[key] === 'string' ? T[key] : '';

        if (replacement !== undefined) {
            value = value.replace('{0}', replacement);
        }

        return value;
    }

    var slug = form.getAttribute('data-slug');
    var screens = Array.prototype.slice.call(
        form.querySelectorAll('.screen')
    );
    var stepBars = Array.prototype.slice.call(
        document.querySelectorAll('.steps i')
    );
    var progressLabel = document.getElementById('wizard-progress');

    var MAX_EDGE = 1600;
    var JPEG_QUALITY = 0.72;
    var MAX_ORIGINAL_BYTES = 12 * 1024 * 1024;

    var state = {
        current: 's-intro',
        challenge: null,
        channel: null,
        verified: false,
        manualPending: false,
        expiresAt: 0,
        resendAt: 0,
        pieces: {}
    };

    /* ---------------- navigation ---------------- */

    function show(id) {
        var target = document.getElementById(id);

        if (!target) {
            return;
        }

        screens.forEach(function (screen) {
            screen.classList.toggle('is-active', screen === target);
        });

        state.current = id;

        var group = parseInt(target.getAttribute('data-group') || '1', 10);

        stepBars.forEach(function (bar, index) {
            bar.classList.toggle('is-done', index + 1 < group);
            bar.classList.toggle('is-now', index + 1 === group);
        });

        if (progressLabel) {
            progressLabel.textContent = t('stepAnnounce').replace(
                '{0}',
                String(group)
            );
        }

        window.scrollTo(0, 0);

        var focusable = target.querySelector(
            'input:not([type=hidden]):not(.sr-only), select, button'
        );

        if (focusable && id !== 's-intro') {
            focusable.focus({ preventScroll: true });
        }
    }

    function setError(field, message) {
        var node = form.querySelector('[data-error-for="' + field + '"]');

        if (!node) {
            return;
        }

        node.textContent = message || '';
        node.hidden = !message;
    }

    /* ---------------- CSRF ---------------- */

    function csrfField() {
        return form.querySelector('input[name="' + T.csrfName + '"]');
    }

    function updateCsrf(payload) {
        if (!payload || !payload.csrf) {
            return;
        }

        var input = form.querySelector(
            'input[name="' + payload.csrf.name + '"]'
        );

        if (input) {
            input.value = payload.csrf.hash;
        }
    }

    function postForm(url, data) {
        var token = csrfField();
        var body = new URLSearchParams();

        if (token) {
            body.append(token.name, token.value);
        }

        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, message: t('networkError') };
            }).then(function (payload) {
                updateCsrf(payload);

                return payload;
            });
        }).catch(function () {
            return { ok: false, message: t('networkError') };
        });
    }

    /* ---------------- étape identité ---------------- */

    form.addEventListener('click', function (event) {
        var goButton = event.target.closest('[data-go]');

        if (!goButton) {
            return;
        }

        var validate = goButton.getAttribute('data-validate');

        if (validate === 'ninu' && !validateNinu()) {
            return;
        }

        if (validate === 'department' && !validateDepartment()) {
            return;
        }

        show(goButton.getAttribute('data-go'));
    });

    function validateNinu() {
        var input = document.getElementById('ninu');
        var value = input.value.replace(/\D+/g, '').slice(0, 10);

        input.value = value;

        if (!/^\d{10}$/.test(value)) {
            setError('ninu', t('ninuRequired'));
            input.focus();

            return false;
        }

        setError('ninu', '');

        return true;
    }

    document.getElementById('ninu').addEventListener('input', function (event) {
        event.target.value = event.target.value
            .replace(/\D+/g, '')
            .slice(0, 10);
        setError('ninu', '');
    });

    var departmentInput = document.getElementById('department-code');

    function validateDepartment() {
        if (departmentInput.value === '') {
            setError('department_code', t('departmentRequired'));
            departmentInput.focus();

            return false;
        }

        setError('department_code', '');

        return true;
    }

    departmentInput.addEventListener('change', function () {
        setError('department_code', '');
    });

    /* ---------------- étape téléphone ---------------- */

    var phoneLocal = document.getElementById('phone-local');
    var phoneHidden = document.getElementById('phone');
    var fallbackBox = document.getElementById('otp-fallback');
    var manualButton = document.getElementById('continue-manual');

    phoneLocal.addEventListener('input', function () {
        var digits = phoneLocal.value.replace(/\D+/g, '').slice(0, 8);

        phoneLocal.value = digits.replace(
            /(\d{2})(\d{0,2})(\d{0,2})(\d{0,2})/,
            function (whole, a, b, c, d) {
                return [a, b, c, d].filter(Boolean).join(' ');
            }
        );

        fallbackBox.hidden = true;
        state.verified = false;
        state.manualPending = false;
        state.challenge = null;
    });

    function phoneDigits() {
        return phoneLocal.value.replace(/\D+/g, '');
    }

    function maskedPhone() {
        var digits = phoneDigits();

        return '+509 ' + digits.slice(0, 2) + ' •• •• ' + digits.slice(6);
    }

    document.getElementById('send-code').addEventListener('click', function () {
        requestCode(null);
    });

    var emailField = document.getElementById('email-field');
    var emailInput = document.getElementById('email');

    Array.prototype.slice.call(
        form.querySelectorAll('[data-channel]')
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            var channel = button.getAttribute('data-channel');

            if (channel === 'email') {
                emailField.hidden = false;
                emailInput.focus();

                if (emailInput.value.trim() === '') {
                    return;
                }
            }

            requestCode(channel);
        });
    });

    function requestCode(channel) {
        var digits = phoneDigits();

        if (digits.length !== 8) {
            setError('phone-local', t('phoneRequired'));
            show('s-phone');
            phoneLocal.focus();

            return;
        }

        setError('phone-local', '');
        phoneHidden.value = '+509' + digits;
        fallbackBox.hidden = true;

        var button = document.getElementById('send-code');

        button.disabled = true;
        button.textContent = t('sendingCode');

        var payload = { phone: phoneHidden.value };

        if (channel) {
            payload.channel = channel;
        }

        if (emailInput.value.trim() !== '') {
            payload.email = emailInput.value.trim();
        }

        postForm(
            '/inscription/' + encodeURIComponent(slug) + '/otp/demander',
            payload
        ).then(function (response) {
            button.disabled = false;
            button.textContent = t('phoneSend');

            if (!response.ok) {
                setError('phone-local', response.message || t('networkError'));
                setError('code', response.message || t('networkError'));

                if (response.fallback_available === true) {
                    fallbackBox.hidden = false;
                    show('s-phone');
                }

                return;
            }

            state.challenge = response.challenge_uuid;
            state.channel = response.delivered_channel;
            state.manualPending = false;
            state.expiresAt = Date.now() + (response.ttl_seconds || 300) * 1000;
            state.resendAt = Date.now() + 60000;

            document.getElementById('code-lead').textContent = t('codeLead')
                .replace('{0}', channelName(response.delivered_channel))
                .replace('{1}', maskedPhone());

            setError('code', '');
            clearCode();
            show('s-code');
            tick();
        });
    }

    manualButton.addEventListener('click', function () {
        if (phoneHidden.value === '') {
            setError('phone-local', t('phoneRequired'));

            return;
        }

        manualButton.disabled = true;
        manualButton.textContent = t('manualSending');

        postForm(
            '/inscription/' + encodeURIComponent(slug)
                + '/otp/continuer-sans-code',
            { phone: phoneHidden.value }
        ).then(function (response) {
            manualButton.disabled = false;
            manualButton.textContent = t('manualAction');

            if (!response.ok) {
                setError(
                    'phone-local',
                    response.message || t('manualUnavailable')
                );

                return;
            }

            state.verified = false;
            state.manualPending = true;
            state.expiresAt = 0;
            fallbackBox.hidden = true;
            document.getElementById('verified-title').textContent =
                t('manualTitle');
            document.getElementById('verified-phone').textContent =
                t('manualAccepted').replace('{0}', maskedPhone());
            show('s-verified');
        });
    });

    function channelName(channel) {
        if (channel === 'sms') {
            return t('channelSms');
        }

        if (channel === 'email') {
            return t('channelEmail');
        }

        return t('channelWhatsApp');
    }

    /* ---------------- code à 6 chiffres ---------------- */

    var codeInputs = Array.prototype.slice.call(
        document.querySelectorAll('#otp-inputs input')
    );

    codeInputs.forEach(function (input, index) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D+/g, '').slice(0, 1);

            if (input.value !== '' && index < codeInputs.length - 1) {
                codeInputs[index + 1].focus();
            }

            if (codeValue().length === 6) {
                verifyCode();
            }
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Backspace' && input.value === '' && index > 0) {
                codeInputs[index - 1].focus();
            }
        });

        input.addEventListener('paste', function (event) {
            var text = (event.clipboardData || window.clipboardData)
                .getData('text')
                .replace(/\D+/g, '')
                .slice(0, 6);

            if (text === '') {
                return;
            }

            event.preventDefault();

            text.split('').forEach(function (digit, position) {
                if (codeInputs[position]) {
                    codeInputs[position].value = digit;
                }
            });

            codeInputs[Math.min(text.length, 5)].focus();

            if (text.length === 6) {
                verifyCode();
            }
        });
    });

    function codeValue() {
        return codeInputs.map(function (input) {
            return input.value;
        }).join('');
    }

    function clearCode() {
        codeInputs.forEach(function (input) {
            input.value = '';
        });
    }

    var expiry = document.getElementById('code-expiry');
    var resendButton = document.getElementById('code-resend');

    resendButton.addEventListener('click', function () {
        requestCode('whatsapp');
    });

    function tick() {
        if (state.expiresAt === 0) {
            return;
        }

        var left = Math.max(0, Math.round((state.expiresAt - Date.now()) / 1000));
        var minutes = Math.floor(left / 60);
        var seconds = left % 60;

        expiry.textContent = left > 0
            ? t('codeExpires').replace(
                '{0}',
                minutes + ':' + String(seconds).padStart(2, '0')
            )
            : t('codeExpired');

        var wait = Math.max(0, Math.round((state.resendAt - Date.now()) / 1000));

        resendButton.disabled = wait > 0;
        resendButton.textContent = wait > 0
            ? t('codeResendIn').replace('{0}', String(wait))
            : t('codeResend');

        window.setTimeout(tick, 1000);
    }

    document.getElementById('verify-code').addEventListener('click', verifyCode);

    var verifying = false;

    function verifyCode() {
        if (verifying) {
            return;
        }

        var code = codeValue();

        if (code.length !== 6) {
            setError('code', t('codeIncomplete'));

            return;
        }

        if (!state.challenge) {
            setError('code', t('codeExpired'));

            return;
        }

        verifying = true;
        setError('code', '');

        postForm(
            '/inscription/' + encodeURIComponent(slug) + '/otp/verifier',
            { challenge_uuid: state.challenge, code: code }
        ).then(function (response) {
            verifying = false;

            if (!response.ok) {
                setError('code', response.message || t('networkError'));
                clearCode();
                codeInputs[0].focus();

                return;
            }

            state.verified = true;
            state.manualPending = false;
            state.expiresAt = 0;
            document.getElementById('verified-title').textContent =
                t('verifiedTitle');
            document.getElementById('verified-phone').textContent = maskedPhone();
            show('s-verified');
        });
    }

    /* ---------------- pièces d'identité ---------------- */

    function pieceScreen(field) {
        return document.getElementById('s-' + field);
    }

    Array.prototype.slice.call(
        form.querySelectorAll('[data-shoot], [data-pick]')
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            var field = button.getAttribute('data-shoot')
                || button.getAttribute('data-pick');
            var input = document.getElementById('file-' + field);

            if (button.hasAttribute('data-pick')) {
                input.removeAttribute('capture');
            } else if (field === 'portrait') {
                input.setAttribute('capture', 'user');
            } else {
                input.setAttribute('capture', 'environment');
            }

            input.click();
        });
    });

    Array.prototype.slice.call(
        form.querySelectorAll('input[type="file"]')
    ).forEach(function (input) {
        input.addEventListener('change', function () {
            var field = input.name;
            var file = input.files && input.files[0];

            if (!file) {
                return;
            }

            setError(field, '');

            if (!/^image\//.test(file.type)) {
                setError(field, t('fileNotImage'));

                return;
            }

            if (file.size > MAX_ORIGINAL_BYTES) {
                setError(field, t('fileTooLarge'));

                return;
            }

            compress(file).then(function (blob) {
                showPreview(field, blob || file);
            }).catch(function () {
                showPreview(field, file);
            });
        });
    });

    function compress(file) {
        if (!window.HTMLCanvasElement || !HTMLCanvasElement.prototype.toBlob) {
            return Promise.resolve(null);
        }

        return loadBitmap(file).then(function (source) {
            var width = source.width;
            var height = source.height;
            var scale = Math.min(1, MAX_EDGE / Math.max(width, height));
            var canvas = document.createElement('canvas');

            canvas.width = Math.round(width * scale);
            canvas.height = Math.round(height * scale);

            canvas
                .getContext('2d')
                .drawImage(source, 0, 0, canvas.width, canvas.height);

            if (source.close) {
                source.close();
            }

            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) {
                    resolve(blob);
                }, 'image/jpeg', JPEG_QUALITY);
            });
        });
    }

    function loadBitmap(file) {
        if (window.createImageBitmap) {
            return createImageBitmap(file, { imageOrientation: 'from-image' })
                .catch(function () {
                    return loadImageElement(file);
                });
        }

        return loadImageElement(file);
    }

    function loadImageElement(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var image = new Image();

            image.onload = function () {
                URL.revokeObjectURL(url);
                resolve(image);
            };

            image.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('image'));
            };

            image.src = url;
        });
    }

    function showPreview(field, blob) {
        var screen = pieceScreen(field);
        var preview = screen.querySelector('[data-role="preview"]');
        var image = screen.querySelector('[data-role="preview-image"]');
        var previous = image.getAttribute('src');

        if (previous) {
            URL.revokeObjectURL(previous);
        }

        image.src = URL.createObjectURL(blob);
        preview.hidden = false;
        screen.querySelector('[data-role="capture"]').hidden = true;
        screen.querySelector('[data-role="capture-actions"]').hidden = true;
        screen.querySelector('[data-role="review-actions"]').hidden = false;
        screen.querySelector('[data-role="title"]').textContent = t('reviewTitle');

        state.pieces[field] = {
            blob: blob,
            name: field + '.jpg',
            size: blob.size
        };
    }

    Array.prototype.slice.call(
        form.querySelectorAll('[data-retake]')
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            var field = button.getAttribute('data-retake');

            resetPiece(field);
            document.getElementById('file-' + field).click();
        });
    });

    function resetPiece(field) {
        var screen = pieceScreen(field);

        screen.querySelector('[data-role="preview"]').hidden = true;
        screen.querySelector('[data-role="capture"]').hidden = false;
        screen.querySelector('[data-role="capture-actions"]').hidden = false;
        screen.querySelector('[data-role="review-actions"]').hidden = true;
        screen.querySelector('[data-role="title"]').textContent =
            t('title_' + field);

        delete state.pieces[field];
        markChecks();
    }

    Array.prototype.slice.call(
        form.querySelectorAll('[data-accept]')
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            var field = button.getAttribute('data-accept');
            var screen = pieceScreen(field);

            markChecks();
            updateSummary();
            show(screen.getAttribute('data-next'));
        });
    });

    function markChecks() {
        Array.prototype.slice.call(
            form.querySelectorAll('[data-piece-check]')
        ).forEach(function (node) {
            node.classList.toggle(
                'is-done',
                Boolean(state.pieces[node.getAttribute('data-piece-check')])
            );
        });
    }

    function updateSummary() {
        var count = Object.keys(state.pieces).length;

        document.getElementById('summary-ninu').textContent =
            '•• •• •• ' + document.getElementById('ninu').value.slice(-2);
        document.getElementById('summary-phone').textContent = maskedPhone();
        document.getElementById('summary-department').textContent =
            departmentInput.options[departmentInput.selectedIndex].text;
        document.getElementById('summary-photos').textContent =
            t('photosCount').replace('{0}', String(count));
    }

    /* ---------------- envoi ---------------- */

    var consentOne = document.getElementById('consent-1');
    var consentTwo = document.getElementById('consent-2');
    var consentValue = document.getElementById('consent-value');
    var progressBox = document.getElementById('upload-progress');
    var progressFill = document.getElementById('upload-fill');
    var progressPercent = document.getElementById('upload-percent');
    var submitButton = document.getElementById('submit-file');

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!state.verified && !state.manualPending) {
            setError('phone-local', t('contactVerificationRequired'));
            show('s-phone');

            return;
        }

        if (departmentInput.value === '') {
            show('s-department');
            validateDepartment();

            return;
        }

        if (!consentOne.checked || !consentTwo.checked) {
            setError('consent', t('consentRequired'));

            return;
        }

        setError('consent', '');
        consentValue.value = '1';

        var missing = ['cin_front', 'cin_back', 'portrait'].filter(
            function (field) {
                return !state.pieces[field];
            }
        );

        if (missing.length > 0) {
            setError('consent', t('piecesMissing'));
            show('s-' + missing[0]);

            return;
        }

        sendDossier();
    });

    function sendDossier() {
        var token = csrfField();
        var data = new FormData();

        if (token) {
            data.append(token.name, token.value);
        }

        data.append('consent', '1');
        data.append('ninu', document.getElementById('ninu').value);
        data.append('phone', phoneHidden.value);
        data.append('department_code', departmentInput.value);

        if (emailInput.value.trim() !== '') {
            data.append('email', emailInput.value.trim());
        }

        Object.keys(state.pieces).forEach(function (field) {
            var piece = state.pieces[field];

            data.append(field, piece.blob, piece.name);
        });

        var request = new XMLHttpRequest();

        request.open('POST', form.getAttribute('action'), true);
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('Accept', 'application/json');

        submitButton.disabled = true;
        progressBox.hidden = false;

        request.upload.onprogress = function (event) {
            if (!event.lengthComputable) {
                return;
            }

            var percent = Math.round((event.loaded / event.total) * 100);

            progressFill.style.width = percent + '%';
            progressPercent.textContent = percent + ' %';
        };

        request.onload = function () {
            var payload = null;

            try {
                payload = JSON.parse(request.responseText);
            } catch (error) {
                payload = null;
            }

            if (payload && payload.ok && payload.redirect) {
                window.location.assign(payload.redirect);

                return;
            }

            submitButton.disabled = false;
            progressBox.hidden = true;
            progressFill.style.width = '0%';
            updateCsrf(payload);
            setError(
                'consent',
                (payload && payload.message) || t('networkError')
            );
        };

        request.onerror = function () {
            submitButton.disabled = false;
            progressBox.hidden = true;
            progressFill.style.width = '0%';
            setError('consent', t('networkError'));
        };

        request.send(data);
    }

    /* ---------------- garde de sortie ---------------- */

    window.addEventListener('beforeunload', function (event) {
        if (state.current === 's-intro' || state.current === 's-ninu') {
            return;
        }

        if (submitButton.disabled) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    document.documentElement.classList.add('has-js');
    show('s-intro');
}());
