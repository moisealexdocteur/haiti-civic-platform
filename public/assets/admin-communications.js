(function () {
    'use strict';

    var script = document.currentScript;
    var form = document.querySelector('.settings-form');
    var testDialog = document.getElementById('channel-test-dialog');
    var deleteDialog = document.getElementById('channel-delete-dialog');
    if (!form || !testDialog || !deleteDialog || !script) return;

    var validLabel = script.getAttribute('data-valid-label') || '';
    var failedLabel = script.getAttribute('data-failed-label') || '';
    var testingLabel = script.getAttribute('data-testing-label') || '';
    var untestedLabel = script.getAttribute('data-untested-label') || '';
    var visibleLabel = script.getAttribute('data-visible-label') || '';
    var hiddenLabel = script.getAttribute('data-hidden-label') || '';
    var deleteTemplate = script.getAttribute('data-delete-template') || '{channel}';
    var activeChannel = '';

    function updateCsrf(payload) {
        if (!payload || !payload.csrf) return;
        document.querySelectorAll('input[name="' + payload.csrf.name + '"]').forEach(function (field) {
            field.value = payload.csrf.hash;
        });
    }

    function setStatus(channel, state, label) {
        var badge = document.querySelector('[data-channel-status="' + channel + '"]');
        if (!badge) return;
        badge.classList.remove('is-valid', 'is-failed', 'is-untested', 'is-testing');
        badge.classList.add('is-' + state);
        badge.textContent = label;
    }

    function setCitizenVisibility(channel, ready) {
        var item = document.querySelector(
            '[data-citizen-channel="' + channel + '"]'
        );

        if (!item) return;

        item.classList.toggle('is-ready', ready);
        var label = item.querySelector('small');

        if (label) {
            label.textContent = ready ? visibleLabel : hiddenLabel;
        }
    }

    function resetTestDialog(button) {
        activeChannel = button.getAttribute('data-test-channel') || '';
        testDialog.querySelector('[data-test-entry]').classList.remove('is-hidden');
        testDialog.querySelector('[data-test-result]').classList.add('is-hidden');
        testDialog.querySelector('[data-test-title]').textContent = '';
        testDialog.querySelector('[data-test-message]').textContent = '';
        testDialog.querySelector('[data-provider-response]').classList.add('is-hidden');
        testDialog.querySelector('[data-test-advice-wrap]').classList.add('is-hidden');
        var destination = testDialog.querySelector('#test-destination');
        destination.value = '';
        destination.type = activeChannel === 'email' ? 'email' : 'tel';
        destination.inputMode = activeChannel === 'email' ? 'email' : 'tel';
        testDialog.querySelector('[data-test-destination-label]').textContent = button.getAttribute('data-destination-label') || '';
        testDialog.querySelector('[data-test-description]').textContent = button.getAttribute('data-channel-label') || '';
    }

    function showResult(payload) {
        testDialog.querySelector('[data-test-entry]').classList.add('is-hidden');
        var result = testDialog.querySelector('[data-test-result]');
        result.classList.remove('is-hidden', 'is-success', 'is-error');
        result.classList.add(payload.ok ? 'is-success' : 'is-error');
        result.querySelector('[data-test-mark]').textContent = payload.ok ? 'OK' : '!';
        result.querySelector('[data-test-title]').textContent = payload.title || '';
        result.querySelector('[data-test-message]').textContent = payload.message || '';

        var provider = result.querySelector('[data-provider-response]');
        if (payload.provider_detail) {
            provider.classList.remove('is-hidden');
            provider.querySelector('[data-provider-detail]').textContent = payload.provider_detail;
        }

        var advice = result.querySelector('[data-test-advice-wrap]');
        if (payload.advice) {
            advice.classList.remove('is-hidden');
            advice.querySelector('[data-test-advice]').textContent = payload.advice;
        }

        setStatus(activeChannel, payload.ok ? 'valid' : 'failed', payload.ok ? validLabel : failedLabel);

        if (payload.ok) {
            setCitizenVisibility(activeChannel, true);
        }

        if (payload.ok) {
            var enabledName = activeChannel === 'email' ? 'email_enabled' : activeChannel + '_enabled';
            var toggle = form.querySelector('[name="' + enabledName + '"]');
            if (toggle) toggle.checked = true;
            var secretName = activeChannel === 'whatsapp' ? 'whatsapp_access_token' : (activeChannel === 'sms' ? 'twilio_auth_token' : 'smtp_password');
            var secret = form.querySelector('[name="' + secretName + '"]');
            if (secret) secret.value = '';
            var deleteButton = form.querySelector('[data-delete-channel="' + activeChannel + '"]');
            if (deleteButton) deleteButton.hidden = false;
        }
    }

    document.querySelectorAll('[data-test-channel]').forEach(function (button) {
        button.addEventListener('click', function () {
            resetTestDialog(button);
            testDialog.showModal();
            testDialog.querySelector('#test-destination').focus();
        });
    });

    testDialog.querySelector('[data-test-submit]').addEventListener('click', function () {
        var destination = testDialog.querySelector('#test-destination');
        if (!destination.reportValidity()) return;

        var submit = testDialog.querySelector('[data-test-submit]');
        submit.disabled = true;
        submit.textContent = testingLabel;
        setStatus(activeChannel, 'testing', testingLabel);
        var payload = new FormData(form);
        payload.set('test_destination', destination.value);

        fetch('/admin/communications/' + encodeURIComponent(activeChannel) + '/tester', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            body: payload
        }).then(function (response) {
            return response.json().then(function (body) { return {response: response, body: body}; });
        }).then(function (result) {
            updateCsrf(result.body);
            showResult(result.body);
        }).catch(function () {
            showResult({ok: false, title: failedLabel, message: '', advice: ''});
        }).finally(function () {
            submit.disabled = false;
            submit.textContent = script.getAttribute('data-run-label') || testingLabel;
        });
    });

    document.querySelectorAll('[data-delete-channel]').forEach(function (button) {
        button.addEventListener('click', function () {
            var channel = button.getAttribute('data-delete-channel') || '';
            var label = button.getAttribute('data-channel-label') || channel;
            var deleteForm = deleteDialog.querySelector('[data-delete-form]');
            deleteForm.action = '/admin/communications/' + encodeURIComponent(channel) + '/supprimer';
            deleteDialog.querySelector('[data-delete-message]').textContent = deleteTemplate.replace('{channel}', label);
            deleteDialog.showModal();
        });
    });

    document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (dialog) dialog.close();
        });
    });

    form.querySelectorAll('.settings-card input, .settings-card select').forEach(function (field) {
        field.addEventListener('input', function () {
            var card = field.closest('.settings-card');
            var button = card ? card.querySelector('[data-test-channel]') : null;
            var channel = button ? button.getAttribute('data-test-channel') : '';
            if (channel) {
                setStatus(channel, 'untested', untestedLabel);
                setCitizenVisibility(channel, false);
            }
        });
    });
}());
