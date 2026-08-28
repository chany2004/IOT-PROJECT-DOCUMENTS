const TINKERED_URLS = ['https://tinkered.ai/*', 'https://*.tinkered.ai/*'];
const DASHBOARD_URLS = ['http://127.0.0.1/*'];
const delivered = new Map();
const pending = new Map();
const postsInFlight = new Map();
let pollInFlight = null;

const configureSidePanel = () => {
  chrome.sidePanel.setPanelBehavior({openPanelOnActionClick: true}).catch(() => {});
};

const isCritical = line => /Fall detected:|LOCAL_ALARM_START|EMERGENCY_BUTTON_PRESSED|\[FALL\]\s*Fall signature detected|\[BUTTON\]\s*Emergency button pressed|"fall"\s*:\s*true/i.test(line);

const trimQueues = () => {
  const now = Date.now();
  if (delivered.size > 800) {
    for (const [line, sentAt] of delivered) {
      if (now - sentAt > 10000) delivered.delete(line);
    }
  }
  while (pending.size > 128) {
    const disposable = [...pending].find(([, item]) => !item.critical);
    if (!disposable) break;
    pending.delete(disposable[0]);
  }
};

async function postPending(line) {
  const item = pending.get(line);
  if (!item) return true;
  if (!item.critical && Date.now() - item.queuedAt > 3000) {
    pending.delete(line);
    return false;
  }
  if (postsInFlight.has(line)) return postsInFlight.get(line);
  if (Date.now() < item.nextAttempt) return false;

  const request = (async () => {
    try {
      const response = await fetch('http://127.0.0.1:8765/ingest', {
        method: 'POST',
        headers: {'Content-Type': 'text/plain'},
        body: line
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      pending.delete(line);
      delivered.set(line, Date.now());
      trimQueues();
      return true;
    } catch {
      const queued = pending.get(line);
      if (queued) {
        queued.attempts += 1;
        queued.nextAttempt = Date.now() + Math.min(5000, 250 * (2 ** Math.min(queued.attempts, 5)));
      }
      return false;
    } finally {
      postsInFlight.delete(line);
    }
  })();
  postsInFlight.set(line, request);
  return request;
}

async function forwardTelemetry(line) {
  const clean = String(line || '').trim().slice(0, 16384);
  if (!clean) return false;

  const sentAt = delivered.get(clean) || 0;
  if (Date.now() - sentAt < 750) return true;
  if (!pending.has(clean)) {
    pending.set(clean, {critical: isCritical(clean), attempts: 0, nextAttempt: 0, queuedAt: Date.now()});
    trimQueues();
  }
  return postPending(clean);
}

async function flushPending() {
  await Promise.all([...pending.keys()].map(postPending));
}

async function injectCollector(tabId) {
  try {
    await chrome.scripting.executeScript({
      target: {tabId, allFrames: true},
      files: ['content.js']
    });
    return true;
  } catch {
    try {
      await chrome.scripting.executeScript({target: {tabId}, files: ['content.js']});
      return true;
    } catch {
      return false;
    }
  }
}

async function injectDashboardClock(tabId) {
  try {
    await chrome.scripting.executeScript({target: {tabId}, files: ['dashboard-clock.js']});
    return true;
  } catch {
    return false;
  }
}

async function bootstrapExistingTabs() {
  try {
    const [tinkeredTabs, dashboardTabs] = await Promise.all([
      chrome.tabs.query({url: TINKERED_URLS}),
      chrome.tabs.query({url: DASHBOARD_URLS})
    ]);
    await Promise.all([
      ...tinkeredTabs.filter(tab => tab.id).map(tab => injectCollector(tab.id)),
      ...dashboardTabs.filter(tab => tab.id).map(tab => injectDashboardClock(tab.id))
    ]);
  } catch {}
}

async function pollTinkeredTabs() {
  if (pollInFlight) return pollInFlight;
  pollInFlight = (async () => {
    const tabs = await chrome.tabs.query({url: TINKERED_URLS});
    let reached = 0;

    await Promise.all(tabs.map(async tab => {
      if (!tab.id) return;
      try {
        await chrome.tabs.sendMessage(tab.id, {type: 'fallguard-poll-now'});
        reached += 1;
      } catch {
        if (await injectCollector(tab.id)) {
          try {
            await chrome.tabs.sendMessage(tab.id, {type: 'fallguard-poll-now'});
            reached += 1;
          } catch {}
        }
      }
    }));
    if (reached > 0) await forwardTelemetry('[TINKERED_BRIDGE] collector_reached=true');
    await flushPending();
    return {ok: true, tabs: tabs.length, reached};
  })().catch(() => ({ok: false, tabs: 0, reached: 0})).finally(() => {
    pollInFlight = null;
  });
  return pollInFlight;
}

configureSidePanel();
bootstrapExistingTabs();
chrome.runtime.onInstalled.addListener(() => {
  configureSidePanel();
  bootstrapExistingTabs();
});
chrome.runtime.onStartup.addListener(() => {
  configureSidePanel();
  bootstrapExistingTabs();
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message?.type === 'fallguard-open-panel' && sender.tab?.id) {
    const opening = chrome.sidePanel.open({tabId: sender.tab.id});
    injectCollector(sender.tab.id);
    opening.then(() => sendResponse({ok: true})).catch(() => sendResponse({ok: false}));
    return true;
  }
  if (message?.type === 'fallguard-poll-tabs') {
    pollTinkeredTabs().then(sendResponse);
    return true;
  }
  if (message?.type === 'fallguard-telemetry' && typeof message.line === 'string') {
    forwardTelemetry(message.line).then(ok => sendResponse({ok}));
    return true;
  }
  return false;
});
