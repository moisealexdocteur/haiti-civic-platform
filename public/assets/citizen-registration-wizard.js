(() => {
    'use strict';

    const form = document.querySelector('#citizen-registration-form');

    if (!form) {
        return;
    }

    document.body.classList.add('wizard-ready');

    const steps = Array.from(form.querySelectorAll('[data-wizard-step]'));
    const progressSteps = Array.from(document.querySelectorAll('[data-progress-step]'));
    const progressFill = document.querySelector('[data-progress-fill]');
    const progressPercent = document.querySelector('[data-progress-percent]');
    const progressLabel = document.querySelector('[data-progress-label]');
    const ninu = form.querySelector('#ninu');
    const phone = form.querySelector('#phone');
    const email = form.querySelector('#email');
    const consent = form.querySelector('#consent');
    const submitButton = form.querySelector('[data-final-submit]');
    const documentsContinue = form.querySelector('[data-documents-continue]');
    const contactContinue = form.querySelector('[data-contact-continue]');
    const identityStatus = form.querySelector('[data-identity-status]');
    const contactStatus = form.querySelector('[data-contact-step-status]');
    const documentStatus = form.querySelector('[data-document-step-status]');
    const fileInputs = [
        form.querySelector('#cin_front'),
        form.querySelector('#cin_back'),
        form.querySelector('#portrait'),
    ].filter(Boolean);

    let currentStep = 1;
    let contactVerified = false;

    const getStep = (number) => steps.find(
        (step) => Number(step.dataset.wizardStep) === number
    );

    const setStatus = (node, message, state = '') => {
        if (!node) {
            return;
        }

        node.textContent = message || '';
        node.dataset.state = state;
    };

    const identityComplete = () => ninu && ninu.value.trim() !== '';
    const documentsComplete = () => fileInputs.length === 3 && fileInputs.every(
        (input) => input.files && input.files.length === 1
    );

    const completionUnits = () => {
        let complete = 0;

        if (identityComplete()) complete += 1;
        if (contactVerified) complete += 1;
        fileInputs.forEach((input) => {
            if (input.files && input.files.length === 1) complete += 1;
        });
        if (consent && consent.checked) complete += 1;

        return complete;
    };

    const updateProgress = () => {
        const units = completionUnits();
        const percent = Math.round((units / 6) * 100);

        if (progressFill) {
            progressFill.style.width = `${percent}%`;
        }

        if (progressPercent) {
            progressPercent.textContent = `${percent}%`;
        }

        if (progressLabel) {
            progressLabel.textContent = progressLabel.dataset.template
                .replace('{current}', String(currentStep))
                .replace('{total}', '4');
        }

        progressSteps.forEach((item) => {
            const number = Number(item.dataset.progressStep);
            item.classList.toggle('is-current', number === currentStep);
            item.classList.toggle('is-complete', number < currentStep);
        });
    };

    const showStep = (number, focus = true) => {
        const target = getStep(number);

        if (!target) {
            return;
        }

        currentStep = number;

        steps.forEach((step) => {
            const active = Number(step.dataset.wizardStep) === number;
            step.classList.toggle('is-current', active);
            step.setAttribute('aria-hidden', active ? 'false' : 'true');
        });

        updateProgress();

        if (focus) {
            const heading = target.querySelector('h2');
            if (heading) {
                heading.setAttribute('tabindex', '-1');
                heading.focus({preventScroll: true});
            }
            target.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    };

    const updateDocumentLocks = () => {
        fileInputs.forEach((input, index) => {
            const card = input.closest('[data-upload-card]');
            const previousComplete = index === 0 || (
                fileInputs[index - 1].files && fileInputs[index - 1].files.length === 1
            );
            const unlocked = previousComplete;
            const complete = input.files && input.files.length === 1;

            input.disabled = !unlocked;

            if (card) {
                card.classList.toggle('is-locked', !unlocked);
                card.classList.toggle('is-ready', unlocked && !complete);
                card.classList.toggle('is-complete', complete);
                card.setAttribute('aria-disabled', unlocked ? 'false' : 'true');

                const fileStatus = card.querySelector('[data-file-status]');
                if (fileStatus) {
                    fileStatus.textContent = complete
                        ? `${fileStatus.dataset.prefix} ${input.files[0].name}`
                        : '';
                }
            }
        });

        if (documentsContinue) {
            documentsContinue.disabled = !documentsComplete();
        }

        if (documentsComplete()) {
            setStatus(
                documentStatus,
                documentStatus ? documentStatus.dataset.complete : '',
                'ok'
            );
        } else {
            setStatus(documentStatus, '');
        }

        updateProgress();
    };

    const syncSubmit = () => {
        if (submitButton) {
            submitButton.disabled = !(
                identityComplete()
                && contactVerified
                && documentsComplete()
                && consent
                && consent.checked
            );
        }

        updateProgress();
    };

    form.addEventListener('click', (event) => {
        const next = event.target.closest('[data-step-next]');
        const back = event.target.closest('[data-step-back]');

        if (next) {
            const target = Number(next.dataset.stepNext);

            if (currentStep === 1) {
                if (!identityComplete()) {
                    ninu.focus();
                    setStatus(
                        identityStatus,
                        identityStatus ? identityStatus.dataset.required : '',
                        'error'
                    );
                    return;
                }

                setStatus(
                    identityStatus,
                    identityStatus ? identityStatus.dataset.complete : '',
                    'ok'
                );
            }

            if (currentStep === 2 && !contactVerified) {
                if (contactStatus) {
                    setStatus(contactStatus, contactStatus.dataset.required, 'error');
                }
                return;
            }

            if (currentStep === 3 && !documentsComplete()) {
                if (documentStatus) {
                    setStatus(documentStatus, documentStatus.dataset.required, 'error');
                }
                return;
            }

            showStep(target);
            syncSubmit();
            return;
        }

        if (back) {
            showStep(Number(back.dataset.stepBack));
        }
    });

    if (ninu) {
        ninu.addEventListener('input', () => {
            if (identityComplete()) {
                setStatus(
                    identityStatus,
                    identityStatus ? identityStatus.dataset.ready : '',
                    'ok'
                );
            } else {
                setStatus(identityStatus, '');
            }
            syncSubmit();
        });
    }

    fileInputs.forEach((input) => {
        input.addEventListener('change', () => {
            updateDocumentLocks();
            syncSubmit();
        });
    });

    if (consent) {
        consent.addEventListener('change', syncSubmit);
    }

    document.addEventListener('civic:otp-verified', () => {
        contactVerified = true;
        if (contactContinue) {
            contactContinue.disabled = false;
        }
        if (contactStatus) {
            setStatus(contactStatus, contactStatus.dataset.complete, 'ok');
        }
        syncSubmit();

        window.setTimeout(() => {
            if (currentStep === 2) {
                showStep(3);
            }
        }, 650);
    });

    document.addEventListener('civic:otp-reset', () => {
        contactVerified = false;
        if (contactContinue) {
            contactContinue.disabled = true;
        }
        if (contactStatus) {
            setStatus(contactStatus, contactStatus.dataset.required, 'error');
        }
        if (currentStep > 2) {
            showStep(2);
        }
        syncSubmit();
    });

    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.dialogOpen);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('dialog');
            if (dialog) {
                dialog.close();
            }
        });
    });

    updateDocumentLocks();
    syncSubmit();
    showStep(1, false);
})();
