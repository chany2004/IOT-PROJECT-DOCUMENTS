(() => {
    const form = document.getElementById('login-form');
    const password = document.getElementById('login-password');
    const toggle = document.getElementById('login-password-toggle');
    const submit = document.getElementById('login-submit');
    const loading = document.getElementById('login-loading');
    if (!form || !password || !toggle || !submit || !loading) return;

    toggle.addEventListener('click', () => {
        const showing = password.type === 'password';
        password.type = showing ? 'text' : 'password';
        const action = showing ? 'Hide password' : 'Show password';
        toggle.setAttribute('aria-label', action);
        toggle.title = action;
        toggle.setAttribute('aria-pressed', String(showing));
        password.focus({ preventScroll: true });
    });

    let submitting = false;
    let submitTimer;

    const resetSubmit = () => {
        window.clearTimeout(submitTimer);
        submitting = false;
        form.removeAttribute('aria-busy');
        submit.disabled = false;
        loading.hidden = true;
    };

    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (submitting) return;

        // This event only fires after the browser's required-field validation passes.
        submitting = true;
        form.setAttribute('aria-busy', 'true');
        submit.disabled = true;
        loading.hidden = false;

        // Show a brief sign-in transition before the normal POST navigation.
        submitTimer = window.setTimeout(() => {
            HTMLFormElement.prototype.submit.call(form);
        }, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 850);
    });

    // A back navigation may restore this page with its submit button disabled.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) resetSubmit();
    });
})();
