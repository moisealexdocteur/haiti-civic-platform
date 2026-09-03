(() => {
    'use strict';

    const root = document.querySelector('[data-otp-root]');
    const form = document.querySelector('#citizen-registration-form');

    if (!root || !form) {
        return;
    }

    const phone = form.querySelector('#phone');
    const email = form.querySelector('#email');
    const csrf = form.querySelector('input[name="csrf_test_name"]');
    const requestButton = root.querySelector('[data-otp-request]');
    const codePanel = root.querySelector('[data-otp-code-panel]');
    const codeInput = root.querySelector('[data-otp-code]');
    const verifyButton = root.querySelector('[data-otp-verify]');
    const status = root.querySelector('[data-otp-status]');

    if (
        !phone || !email || !csrf || !requestButton || !codePanel ||
        !codeInput || !verifyButton || !status
    ) {
        return;
    }

    let challengeUuid = '';
    let verifiedContact = '';

    const contactSignature = () => {
        return `${phone.value.trim()}\u0000${email.value.trim().toLowerCase()}`;
    };

    const setBusy = (busy) => {
        requestButton.disabled = busy;
        verifyButton.disabled = busy;
    };

    const setStatus = (message, state = '') => {
        status.textContent = message || '';
        status.dataset.state = state;
    };

    const updateCsrf = (payload) => {
        if (
            payload && payload.csrf &&
            typeof payload.csrf.name === 'string' &&
            typeof payload.csrf.hash === 'string' &&
            payload.csrf.name !== '' && payload.csrf.hash !== ''
        ) {
            csrf.name = payload.csrf.name;
            csrf.value = payload.csrf.hash;
        }
    };

    const post = async (url, fields) => {
        const body = new URLSearchParams();
        body.set(csrf.name, csrf.value);

        Object.entries(fields).forEach(([key, value]) => {
            body.set(key, value);
        });

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            },
            body: body.toString(),
        });

        let payload = null;

        try {
            payload = await response.json();
        } catch (_) {
            payload = null;
        }

        updateCsrf(payload);

        return {response, payload};
    };

    const resetChallenge = () => {
        if (
            verifiedContact !== ''
            && contactSignature() !== verifiedContact
        ) {
            verifiedContact = '';
            setStatus('');
        }

        challengeUuid = '';
        codeInput.value = '';
        codePanel.hidden = true;
    };

    phone.addEventListener('input', resetChallenge);
    email.addEventListener('input', resetChallenge);

    requestButton.addEventListener('click', async () => {
        const phoneValue = phone.value.trim();
        const emailValue = email.value.trim();

        if (phoneValue === '') {
            phone.focus();
            return;
        }

        if (emailValue !== '' && !email.checkValidity()) {
            email.focus();
            return;
        }

        setBusy(true);
        setStatus('…');

        try {
            const {response, payload} = await post(
                root.dataset.requestUrl,
                {
                    phone: phoneValue,
                    email: emailValue,
                }
            );

            if (!response.ok || !payload || payload.ok !== true) {
                challengeUuid = '';
                codePanel.hidden = true;
                setStatus(
                    payload && payload.message ? payload.message : 'Erreur.',
                    'error'
                );
                return;
            }

            challengeUuid = payload.challenge_uuid || '';
            verifiedContact = '';
            codePanel.hidden = challengeUuid === '';
            codeInput.value = '';
            setStatus(payload.message || '', 'sent');

            if (challengeUuid !== '') {
                codeInput.focus();
            }
        } catch (_) {
            challengeUuid = '';
            codePanel.hidden = true;
            setStatus('Erreur.', 'error');
        } finally {
            setBusy(false);
        }
    });

    verifyButton.addEventListener('click', async () => {
        const code = codeInput.value.trim();

        if (!/^[0-9]{6}$/.test(code) || challengeUuid === '') {
            codeInput.focus();
            return;
        }

        setBusy(true);

        try {
            const {response, payload} = await post(
                root.dataset.verifyUrl,
                {
                    challenge_uuid: challengeUuid,
                    code,
                }
            );

            codeInput.value = '';

            if (!response.ok || !payload || payload.ok !== true) {
                setStatus(
                    payload && payload.message ? payload.message : 'Erreur.',
                    'error'
                );
                return;
            }

            verifiedContact = contactSignature();
            challengeUuid = '';
            codePanel.hidden = true;
            setStatus(payload.message || '', 'verified');
        } catch (_) {
            setStatus('Erreur.', 'error');
        } finally {
            setBusy(false);
        }
    });
})();
