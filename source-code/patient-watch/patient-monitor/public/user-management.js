(() => {
    document.querySelectorAll('[data-account-modal-trigger]').forEach((trigger) => {
        const dialog = document.getElementById(trigger.getAttribute('aria-controls'));
        if (!dialog || typeof dialog.showModal !== 'function') return;

        const openDialog = () => {
            // A server-rendered open dialog is not in the modal top layer yet.
            if (dialog.open) dialog.close();
            dialog.showModal();
            const focusTarget = dialog.querySelector('.account-modal-errors')
                || dialog.querySelector('input[name="name"]');
            focusTarget?.focus();
        };

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            openDialog();
        });

        dialog.addEventListener('close', () => {
            if (!dialog.open) trigger.focus({ preventScroll: true });
        });

        // Reopen the appropriate modal after validation, or from a no-JS link.
        if (dialog.hasAttribute('open')) openDialog();
    });
})();
