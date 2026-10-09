(() => {
    const trigger = document.getElementById('add-patient-trigger');
    const dialog = document.getElementById('create-patient');
    const codeField = document.getElementById('new-patient-code');
    if (!trigger || !dialog || !codeField) return;

    const openDialog = () => {
        if (dialog.open) dialog.close();
        dialog.showModal();
        (dialog.querySelector('.patient-modal-errors') || codeField).focus();
    };

    trigger.addEventListener('click', (event) => {
        if (typeof dialog.showModal !== 'function') return;
        event.preventDefault();
        openDialog();
    });

    dialog.addEventListener('close', () => {
        if (!dialog.open) trigger.focus({preventScroll: true});
    });

    // A failed submission or a link opened without JavaScript uses this dialog.
    if (dialog.hasAttribute('open') && typeof dialog.showModal === 'function') {
        openDialog();
    }

    const form = dialog.querySelector('.patient-modal-form');
    const submit = document.getElementById('patient-modal-submit');
    const loading = document.getElementById('patient-modal-loading');
    if (!form || !submit || !loading) return;

    let submitting = false;
    let submitTimer;
    const resetSubmit = () => {
        window.clearTimeout(submitTimer);
        submitting = false;
        form.removeAttribute('aria-busy');
        submit.disabled = false;
        loading.hidden = true;
    };

    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (submitting) return;

        // Native required-field checks complete before this submit event fires.
        submitting = true;
        form.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        loading.hidden = false;
        submitTimer = window.setTimeout(() => {
            HTMLFormElement.prototype.submit.call(form);
        }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 850);
    });

    dialog.addEventListener('cancel', event => {
        if (submitting) event.preventDefault();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) resetSubmit();
    });
})();
