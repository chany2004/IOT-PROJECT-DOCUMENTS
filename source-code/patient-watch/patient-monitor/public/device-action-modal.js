(() => {
    const workspace = document.querySelector('.device-workspace');
    if (!workspace) return;

    const dialogs = [...workspace.querySelectorAll('.device-action-dialog')];
    const triggers = [...workspace.querySelectorAll('[data-open-device-action]')];
    const lastTrigger = new WeakMap();
    if (!dialogs.length) return;

    const openDialog = dialog => {
        if (!dialog || typeof dialog.showModal !== 'function') return false;
        if (dialog.open) dialog.close();
        dialog.showModal();
        (dialog.querySelector('.device-action-errors')
            || dialog.querySelector('[name="patient_id"], [name="code"], [name="confirm"]')
            || dialog.querySelector('.device-action-close'))?.focus({ preventScroll: true });
        return true;
    };

    triggers.forEach(trigger => {
        const dialog = document.getElementById(trigger.getAttribute('aria-controls'));
        if (!dialog) return;
        trigger.addEventListener('click', event => {
            lastTrigger.set(dialog, trigger);
            if (openDialog(dialog)) event.preventDefault();
        });
    });

    dialogs.forEach(dialog => {
        dialog.addEventListener('close', () => {
            if (dialog.open) return;
            const url = new URL(window.location.href);
            if (url.searchParams.has('device_action') || url.hash === '#' + dialog.id) {
                url.searchParams.delete('device_action');
                if (url.hash === '#' + dialog.id) url.hash = '';
                window.history.replaceState(null, '', url);
            }
            const trigger = lastTrigger.get(dialog)
                || triggers.find(link => link.getAttribute('aria-controls') === dialog.id);
            trigger?.focus({ preventScroll: true });
        });
        dialog.addEventListener('click', event => {
            if (event.target === dialog) dialog.close();
        });
    });

    const initial = dialogs.find(dialog => dialog.hasAttribute('open'))
        || dialogs.find(dialog => window.location.hash === '#' + dialog.id);
    if (initial) {
        dialogs.filter(dialog => dialog !== initial && dialog.open).forEach(dialog => dialog.close());
        openDialog(initial);
    }
})();
