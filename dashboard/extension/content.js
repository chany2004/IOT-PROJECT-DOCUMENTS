(() => {
  if (window.__fallGuardTinkeredCollector) {
    window.__fallGuardTinkeredCollector.poll();
    return;
  }

  const TELEMETRY = /(@@FG1:|HB uptime_s=|\[IMU\]|Fall detected:|LOCAL_ALARM_START|EMERGENCY_BUTTON_PRESSED|\[FALL\]\s*Fall signature detected|\[BUTTON\]\s*Emergency button pressed)/i;
  const SERIAL_SELECTOR = [
    '.xterm-accessibility-tree', '.xterm-rows', '[role="log"]',
    '[data-testid*="serial" i]', '[data-testid*="terminal" i]',
    '[aria-label*="serial" i]', '[aria-label*="terminal" i]',
    '[class*="serial-monitor" i]', '[class*="serialMonitor" i]'
  ].join(',');
  const roots = new Set();
  const observers = new WeakSet();
  const serialContainers = new Set();
  let previousCounts = new Map();

  if (window.top === window && !document.getElementById('fallguard-open-monitor')) {
    const openButton = document.createElement('button');
    openButton.id = 'fallguard-open-monitor';
    openButton.type = 'button';
    openButton.textContent = 'Open CareGuard Monitor';
    Object.assign(openButton.style, {
      position: 'fixed', right: '18px', bottom: '18px', zIndex: '2147483647',
      border: '1px solid #3cd8cd', borderRadius: '11px', padding: '10px 14px',
      background: '#102b43', color: '#f4fbff', font: '700 13px system-ui',
      boxShadow: '0 10px 28px rgba(0,0,0,.35)', cursor: 'pointer'
    });
    openButton.addEventListener('click', () => {
      chrome.runtime.sendMessage({type: 'fallguard-open-panel'}).catch(() => {});
    });
    document.body.appendChild(openButton);
  }

  const discoverRoots = node => {
    if (!node?.querySelectorAll) return;
    if (node.shadowRoot) observeRoot(node.shadowRoot);
    node.querySelectorAll('*').forEach(element => {
      if (element.shadowRoot) observeRoot(element.shadowRoot);
    });
  };

  const observeRoot = root => {
    if (!root || observers.has(root)) return;
    observers.add(root);
    roots.add(root);
    new MutationObserver(records => records.forEach(record => {
      record.addedNodes.forEach(discoverRoots);
    })).observe(root, {subtree: true, childList: true});
  };

  const refreshSerialContainers = () => {
    for (const container of serialContainers) {
      if (!container.isConnected) serialContainers.delete(container);
    }
    for (const root of roots) {
      root.querySelectorAll?.(SERIAL_SELECTOR).forEach(element => {
        if (!element.closest('[class*="chat" i], [data-testid*="chat" i], [aria-label*="chat" i]')) {
          serialContainers.add(element);
        }
      });
    }
    if (serialContainers.size) return;

    for (const root of roots) {
      const labels = root.querySelectorAll?.('h1,h2,h3,h4,h5,h6,button,span,p,div') || [];
      for (const label of labels) {
        if ((label.innerText || label.textContent || '').trim().toUpperCase() !== 'SERIAL MONITOR') continue;
        if (label.closest('[class*="chat" i], [data-testid*="chat" i], [aria-label*="chat" i]')) continue;
        let panel = label.parentElement;
        for (let depth = 0; panel && depth < 8; depth += 1, panel = panel.parentElement) {
          const text = panel.innerText || panel.textContent || '';
          if (text.length < 100000 && TELEMETRY.test(text)) {
            serialContainers.add(panel);
            break;
          }
        }
      }
    }
  };

  const collectSerialText = () => {
    refreshSerialContainers();
    const chunks = [];
    for (const container of serialContainers) {
      const text = container.value || container.innerText || container.textContent;
      if (text) chunks.push(text);
    }
    return chunks.join('\n');
  };

  const extractLines = text => {
    const lines = [];
    const frames = text.match(/@@FG1:\{[\s\S]*?\}:FG@@/g) || [];
    frames.forEach(frame => lines.push(frame.replace(/[\r\n]+/g, '')));
    text.split(/\r?\n/).forEach(raw => {
      const line = raw.trim();
      if (!line || !TELEMETRY.test(line) || /(?:Serial\.(?:print|printf)|console\.log)\s*\(/i.test(line)) return;
      const runtimeLine = /^(?:\[(?:INFO|WARN|ERROR|ALARM|FALL|DEBUG)\]\s*)?(?:HB\s+uptime_s=\d+|\[IMU\]\s+|Fall detected:|Fall signature detected\b|LOCAL_ALARM_START(?::|\s)|EMERGENCY_BUTTON_PRESSED\b|\[BUTTON\]\s*Emergency button pressed\b)/i.test(line);
      if (!runtimeLine) return;
      lines.push(line);
    });
    return lines;
  };

  const countLines = text => {
    const counts = new Map();
    extractLines(text).forEach(line => counts.set(line, (counts.get(line) || 0) + 1));
    return counts;
  };

  const forward = line => {
    chrome.runtime.sendMessage({type: 'fallguard-telemetry', line}).catch(() => {});
  };

  const collectPageText = () => {
    const chunks = [];
    for (const root of roots) {
      const text = root === document ? document.body?.innerText : root.textContent;
      if (text) chunks.push(text);
      root.querySelectorAll?.('textarea, input').forEach(element => {
        if (element.value) chunks.push(element.value);
      });
    }
    return chunks.join('\n');
  };

  const isSimulationRunning = () => {
    for (const root of roots) {
      for (const button of root.querySelectorAll?.('button') || []) {
        const label = (button.innerText || button.textContent || button.getAttribute('aria-label') || '').trim();
        if (/^stop(?:\s+simulation)?$/i.test(label)) return true;
      }
    }
    return false;
  };

  const readAxis = (text, name) => {
    const match = text.match(new RegExp(name + '\\s*[:=]?\\s*([-+]?\\d+(?:\\.\\d+)?)\\s*(?:g|dps|°/s)?', 'i'));
    return match ? match[1] : null;
  };

  const poll = () => {
    discoverRoots(document);
    const currentCounts = countLines(collectSerialText());
    for (const [line, count] of currentCounts) {
      if (count > (previousCounts.get(line) || 0)) forward(line);
    }
    previousCounts = currentCounts;

    if (!isSimulationRunning()) return;
    const text = collectPageText();
    const axes = {
      ax: readAxis(text, 'Accel X'), ay: readAxis(text, 'Accel Y'), az: readAxis(text, 'Accel Z'),
      gx: readAxis(text, 'Gyro X'), gy: readAxis(text, 'Gyro Y'), gz: readAxis(text, 'Gyro Z')
    };
    if (axes.ax === null || axes.ay === null || axes.az === null) return;
    const parts = [`ax=${axes.ax}`, `ay=${axes.ay}`, `az=${axes.az}`];
    if (axes.gx !== null && axes.gy !== null && axes.gz !== null) {
      parts.push(`gx=${axes.gx}`, `gy=${axes.gy}`, `gz=${axes.gz}`);
    }
    forward(`[TINKERED_UI] valid=1 ${parts.join(' ')}`);
  };

  window.__fallGuardTinkeredCollector = {poll};
  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type !== 'fallguard-poll-now') return false;
    poll();
    sendResponse({ok: true});
    return false;
  });

  observeRoot(document);
  discoverRoots(document);
  previousCounts = countLines(collectSerialText());
  poll();
  setInterval(poll, 1000);
})();
