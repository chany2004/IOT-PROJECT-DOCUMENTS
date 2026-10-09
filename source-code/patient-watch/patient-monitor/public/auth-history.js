(() => {
    let refreshing = false;

    // A browser may restore an authenticated page from its back/forward cache
    // without asking the server. Force a fresh request so the auth middleware
    // can redirect to login after sign-out or session expiry.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted || refreshing) return;
        refreshing = true;
        window.location.reload();
    });
})();
