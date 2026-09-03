(function () {
    'use strict';

    var root = document.querySelector('[data-tracking]');
    if (!root) return;

    var reference = root.getAttribute('data-reference') || '';
    var strings = JSON.parse(root.getAttribute('data-strings') || '{}');
    var requestPanel = root.querySelector('[data-tracking-request]');
    var form = root.querySelector('[data-tracking-code]');
    var requestButton = root.querySelector('[data-request-code]');
    var message = root.querySelector('[data-tracking-message]');
    var error = root.querySelector('[data-tracking-error]');
    var challenge = form ? form.querySelector('[name="challenge_uuid"]') : null;
    var digits = form ? Array.prototype.slice.call(form.querySelectorAll('[data-otp] input')) : [];
    var requestButtonLabel = requestButton ? requestButton.textContent : '';

    function csrf() {
        var field = form.querySelector('input[type="hidden"]:not([name="challenge_uuid"])');
        return field ? {name: field.name, hash: field.value} : null;
    }

    function updateCsrf(payload) {
        if (!payload || !payload.csrf) return;
        var field = form.querySelector('input[name="' + payload.csrf.name + '"]');
        if (field) field.value = payload.csrf.hash;
    }

    function body(extra) {
        var data = new URLSearchParams();
        var token = csrf();
        if (token) data.set(token.name, token.hash);
        Object.keys(extra || {}).forEach(function (key) { data.set(key, extra[key]); });
        return data;
    }

    function showError(text) {
        error.textContent = text;
        error.classList.toggle('is-hidden', !text);
    }

    digits.forEach(function (input, index) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D/g, '').slice(-1);
            if (input.value && digits[index + 1]) digits[index + 1].focus();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Backspace' && !input.value && digits[index - 1]) digits[index - 1].focus();
        });
        input.addEventListener('paste', function (event) {
            var pasted = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
            if (!pasted) return;
            event.preventDefault();
            digits.forEach(function (field, position) { field.value = pasted[position] || ''; });
            digits[Math.min(pasted.length, 6) - 1].focus();
        });
    });

    requestButton.addEventListener('click', function () {
        requestButton.disabled = true;
        requestButton.textContent = strings.sending || requestButton.textContent;
        showError('');

        fetch('/swiv/' + encodeURIComponent(reference) + '/code/demander', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: body({})
        }).then(function (response) {
            return response.json().then(function (payload) { return {ok: response.ok, payload: payload}; });
        }).then(function (result) {
            updateCsrf(result.payload);
            if (!result.ok || !result.payload.ok) throw new Error(result.payload.message || strings.unavailable);
            challenge.value = result.payload.challenge_uuid;
            message.textContent = result.payload.message || '';
            requestPanel.classList.add('is-hidden');
            form.classList.remove('is-hidden');
            digits[0].focus();
        }).catch(function (failure) {
            showError(failure.message || strings.unavailable || '');
            requestButton.disabled = false;
            requestButton.textContent = requestButtonLabel;
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        showError('');
        var code = digits.map(function (input) { return input.value; }).join('');
        if (!/^\d{6}$/.test(code)) {
            showError(strings.invalid || '');
            return;
        }

        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        fetch('/swiv/' + encodeURIComponent(reference) + '/code/verifier', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: body({challenge_uuid: challenge.value, code: code})
        }).then(function (response) {
            return response.json().then(function (payload) { return {ok: response.ok, payload: payload}; });
        }).then(function (result) {
            updateCsrf(result.payload);
            if (!result.ok || !result.payload.ok) throw new Error(result.payload.message || strings.invalid);
            window.location.assign(result.payload.redirect);
        }).catch(function (failure) {
            showError(failure.message || strings.invalid || '');
            submit.disabled = false;
        });
    });
}());
