(() => {
  if (window.top !== window || window.__fallGuardDashboardClock) return;
  window.__fallGuardDashboardClock = true;
  let stopped = false;

  const pulse = async () => {
    if (stopped) return;
    try {
      await chrome.runtime.sendMessage({type: 'fallguard-poll-tabs'});
    } catch {}
    setTimeout(pulse, 500);
  };

  window.addEventListener('pagehide', () => { stopped = true; }, {once: true});
  pulse();
})();

