(() => {
    const dialog = document.getElementById('register-device');
    const form = dialog?.querySelector('.device-registration-form');
    const submit = document.getElementById('device-registration-submit');
    const loading = document.getElementById('device-registration-loading');
    if (!dialog || !form || !submit || !loading) return;

    let submitting = false;
    let submitTimer;
    const reset = () => {
        window.clearTimeout(submitTimer);
        submitting = false;
        form.removeAttribute('aria-busy');
        submit.disabled = false;
        loading.hidden = true;
    };

    form.addEventListener('submit', event => {
        if (event.defaultPrevented || event.submitter?.formMethod === 'dialog') return;
        event.preventDefault();
        if (submitting) return;

        // A submit event is only fired after native required-field validation succeeds.
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
        if (event.persisted) reset();
    });
})();
