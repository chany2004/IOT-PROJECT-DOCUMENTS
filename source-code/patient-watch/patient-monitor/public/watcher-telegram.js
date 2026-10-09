(() => {
    'use strict';
    const panel = document.querySelector('[data-watcher-connect]');
    if (!panel) return;
    const connectionStatus = panel.querySelector('[data-connection-status]');
    const copyStatus = panel.querySelector('[data-copy-status]');
    let timer;
    let checking = false;
    let stopped = false;

    panel.querySelectorAll('[data-copy-target]').forEach(button => {
        button.addEventListener('click', async () => {
            const input = panel.querySelector(button.dataset.copyTarget);
            if (!input) return;
            try {
                if (!navigator.clipboard || !window.isSecureContext) throw new Error('Manual copy required');
                await navigator.clipboard.writeText(input.value);
                copyStatus.textContent = 'Copied. Share only with the intended watcher.';
            } catch {
                input.focus();
                input.select();
                copyStatus.textContent = 'The text is selected. Use Copy, then paste it into Telegram.';
            }
        });
    });

    const checkConnection = async () => {
        clearTimeout(timer);
        if (stopped || checking || document.hidden) return;
        checking = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(panel.dataset.statusUrl, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if ([401, 403, 404].includes(response.status) || response.redirected) {
                stopped = true;
                connectionStatus.textContent = 'Refresh this page and sign in to check the watcher connection.';
                return;
            }
            if (!response.ok) throw new Error('Status unavailable');
            const status = await response.json();
            if (status.connected) {
                stopped = true;
                connectionStatus.textContent = 'Watcher connected. Updating this profile…';
                window.location.reload();
                return;
            }
            if (!status.invitation_active) {
                stopped = true;
                connectionStatus.textContent = 'This link expired or was replaced. Create a new private link and press Start.';
                return;
            }
            connectionStatus.textContent = 'Waiting for Start in Telegram. If already pressed, use the private Start command above.';
        } catch {
            connectionStatus.textContent = 'Could not check the connection. Retrying; keep this profile open.';
        } finally {
            clearTimeout(timeout);
            checking = false;
            if (!stopped && !document.hidden) timer = setTimeout(checkConnection, 3000);
        }
    };
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else checkConnection();
    });
    checkConnection();
})();
