(() => {
  if (chrome.runtime.getManifest().version !== '1.2.0') {
    chrome.runtime.reload();
    return;
  }

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

