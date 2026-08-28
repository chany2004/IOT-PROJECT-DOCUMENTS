(function () {
  "use strict";

  const POLL_INTERVAL_MS = 500;
  const MAX_CHART_POINTS = 30;
  const $ = (selector) => document.querySelector(selector);

  const elements = {
    clock: $("#dashboard-clock"), connectionBadge: $("#connection-badge"), connectionLabel: $("#connection-label"),
    soundToggle: $("#sound-toggle"), soundLabel: $("#sound-label"), statusPanel: $("#status-panel"),
    statusCode: $("#status-code"), statusTitle: $("#status-title"), statusDescription: $("#status-description"),
    stateSymbol: $("#state-symbol"), riskLabel: $("#risk-label"), lastUpdateLabel: $("#last-update-label"),
    mpuStatus: $("#mpu-status"), statusAcknowledge: $("#status-acknowledge"), urgentAnnouncer: $("#urgent-announcer"),
    politeAnnouncer: $("#polite-announcer"), accelMetric: $("#accel-metric"), accelValue: $("#accel-value"),
    accelState: $("#accel-state"), accelProgress: $("#accel-progress"), accelProgressFill: $("#accel-progress-fill"),
    gyroMetric: $("#gyro-metric"), gyroValue: $("#gyro-value"), gyroState: $("#gyro-state"),
    gyroProgress: $("#gyro-progress"), gyroProgressFill: $("#gyro-progress-fill"), secondaryEyebrow: $("#secondary-eyebrow"),
    secondaryTitle: $("#secondary-title"), secondaryUnit: $("#secondary-unit"), secondaryThreshold: $("#secondary-threshold"),
    secondaryNodeLabel: $("#secondary-node-label"), decisionModeBadge: $("#decision-mode-badge"), accelNode: $("#accel-node"),
    gyroNode: $("#gyro-node"), correlationCopy: $("#correlation-copy"), cooldownCopy: $("#cooldown-copy"),
    bridgeBadge: $("#bridge-badge"), bridgeHelp: $("#bridge-help"), serverStep: $("#server-step"),
    extensionStep: $("#extension-step"), telemetryStep: $("#telemetry-step"), serverState: $("#server-state"),
    extensionState: $("#extension-state"), telemetryState: $("#telemetry-state"), checkConnection: $("#check-connection"),
    deviceBadge: $("#device-badge"), dataSource: $("#data-source"), transportSummary: $("#transport-summary"),
    sessionUptime: $("#session-uptime"), accelChart: $("#accel-chart"), gyroChart: $("#gyro-chart"),
    secondaryChartTitle: $("#secondary-chart-title"), accelChartSummary: $("#accel-chart-summary"),
    gyroChartSummary: $("#gyro-chart-summary"), eventList: $("#event-list"), emptyEvents: $("#empty-events"),
    accelCurrentStat: $("#accel-current-stat"), accelPeakStat: $("#accel-peak-stat"),
    accelThresholdStat: $("#accel-threshold-stat"), secondaryCurrentStat: $("#secondary-current-stat"),
    secondaryPeakStat: $("#secondary-peak-stat"), secondaryThresholdStat: $("#secondary-threshold-stat"),
    exportEvents: $("#export-events"), clearEvents: $("#clear-events"), sampleAge: $("#sample-age"),
    axisAx: $("#axis-ax"), axisAy: $("#axis-ay"), axisAz: $("#axis-az"), axisGx: $("#axis-gx"),
    axisGy: $("#axis-gy"), axisGz: $("#axis-gz"), alertOverlay: $("#alert-overlay"), alertDialog: $("#alert-dialog"),
    alertDialogKicker: $("#alert-dialog-kicker"), alertDialogTitle: $("#alert-dialog-title"),
    alertDialogDescription: $("#alert-dialog-description"), alertEvidence: $("#alert-evidence"), alertAccel: $("#alert-accel"),
    alertGyro: $("#alert-gyro"), alertSecondaryLabel: $("#alert-secondary-label"), alertTime: $("#alert-time"),
    deliveryState: $("#delivery-state"), dialogAcknowledge: $("#dialog-acknowledge"), dialogDismiss: $("#dialog-dismiss"),
    fallCount: $("#fall-count"), emergencyCount: $("#emergency-count"), activeSource: $("#active-source"),
    lastAlert: $("#last-alert"), lastAlertTime: $("#last-alert-time"),
  };

  let latestData = null;
  let latestEvents = [];
  let lastTelemetryStamp = 0;
  let accelHistory = [];
  let secondaryHistory = [];
  let secondaryChartConfig = { threshold: 220, unit: "°/s", label: "Rotation" };
  let pollTimer = 0;
  let pollInFlight = false;
  let soundEnabled = false;
  let audioContext = null;
  let alertOpen = false;
  let shownAlertKey = "";
  let lastFocusedElement = null;
  let lastAlarmPulseAt = 0;

  const numeric = (value, fallback = 0) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  };
  const positive = (value, fallback) => {
    const parsed = numeric(value, fallback);
    return parsed > 0 ? parsed : fallback;
  };
  const textValue = (value, fallback = "") => {
    const text = String(value == null ? "" : value).trim();
    return text || fallback;
  };

  function getSecondaryConfig(data) {
    const mode = textValue(data.decision_mode, "FIRMWARE").toUpperCase();
    const vectorMode = mode.startsWith("TINKERED_ACCEL");
    const label = textValue(data.secondary_label, vectorMode ? "Posture change" : "Rapid rotation");
    const unit = textValue(data.secondary_unit, vectorMode ? "deg" : "°/s").replace("deg/s", "°/s");
    const threshold = positive(data.secondary_threshold, vectorMode ? 35 : positive(data.gyro_threshold_dps, 220));
    const value = numeric(data.secondary_value, vectorMode ? 0 : numeric(data.gyro));
    return { mode, vectorMode, label, unit, threshold, value };
  }

  function getIncidentType(data) {
    const explicit = textValue(data.incident_type, "NONE").toUpperCase();
    if (explicit === "EMERGENCY_BUTTON" || data.emergency_active) return "EMERGENCY";
    if (explicit === "FALL_DETECTED" || data.fall_detected) return "FALL";
    return "NONE";
  }

  function incidentActive(data) {
    return getIncidentType(data) !== "NONE";
  }

  function render(data) {
    latestData = data;
    latestEvents = Array.isArray(data.events) ? data.events : [];
    const connected = Boolean(data.connected);
    const bridgeConnected = Boolean(data.bridge_connected);
    const incidentType = getIncidentType(data);
    const incident = incidentType !== "NONE";
    const secondary = getSecondaryConfig(data);
    secondaryChartConfig = { threshold: secondary.threshold, unit: secondary.unit, label: secondary.label };
    renderConnection(bridgeConnected, connected, data);
    renderStatus(data, secondary, bridgeConnected, connected, incidentType);
    renderMetrics(data, secondary, connected, incidentType === "FALL");
    renderDevice(data, secondary, bridgeConnected, connected, incident);
    renderAxes(data, connected);
    renderEvents(latestEvents, incident);
    renderSessionSummary(data, secondary, latestEvents);
    updateHistory(data, secondary, connected);
    renderChartStats(data, secondary, connected);
    drawCharts();
    handleIncomingAlert(data, secondary, incidentType);
    sustainActiveAlarm(incidentType);
  }

  function renderConnection(bridgeConnected, connected, data) {
    const connectionState = connected ? "connected" : bridgeConnected ? "waiting" : "disconnected";
    setConnectionState(elements.connectionBadge, connectionState);
    setConnectionState(elements.bridgeBadge, connectionState);
    setConnectionState(elements.deviceBadge, connectionState);
    document.body.dataset.telemetry = connected ? "live" : bridgeConnected ? "ready" : "offline";
    elements.serverStep.classList.add("is-complete");
    elements.serverState.textContent = "Online";
    elements.extensionStep.classList.toggle("is-complete", bridgeConnected);
    elements.telemetryStep.classList.toggle("is-complete", connected);
    elements.extensionState.textContent = bridgeConnected ? "Connected" : "Waiting";
    elements.telemetryState.textContent = connected ? "Streaming" : "Waiting";
    if (connected) {
      elements.connectionLabel.textContent = "Tinkered live";
      elements.bridgeBadge.innerHTML = '<i aria-hidden="true"></i> Live telemetry';
      elements.deviceBadge.innerHTML = '<i aria-hidden="true"></i> Online';
      elements.bridgeHelp.textContent = "The dashboard is receiving real sensor values from the running Tinkered simulation.";
    } else if (bridgeConnected) {
      elements.connectionLabel.textContent = "Bridge ready";
      elements.bridgeBadge.innerHTML = '<i aria-hidden="true"></i> Waiting for data';
      elements.deviceBadge.innerHTML = '<i aria-hidden="true"></i> Waiting';
      elements.bridgeHelp.textContent = "The browser bridge is connected. Start the Tinkered simulation and keep its Serial Monitor open.";
    } else {
      elements.connectionLabel.textContent = "Tinkered offline";
      elements.bridgeBadge.innerHTML = '<i aria-hidden="true"></i> Bridge offline';
      elements.deviceBadge.innerHTML = '<i aria-hidden="true"></i> Offline';
      elements.bridgeHelp.textContent = "Open this monitor from the button inside Tinkered, then start the simulation.";
    }
    const stamp = numeric(data.telemetry_updated_ms);
    elements.lastUpdateLabel.textContent = connected && stamp ? `Updated ${formatAge(stamp)}` : "No live packet";
    elements.sampleAge.textContent = connected && stamp ? formatAge(stamp) : "Waiting";
  }

  function setConnectionState(element, state) {
    element.classList.remove("is-connected", "is-waiting", "is-disconnected");
    element.classList.add(`is-${state}`);
  }

  function renderStatus(data, secondary, bridgeConnected, connected, incidentType) {
    const fallState = textValue(data.fall_state, "IDLE").toUpperCase();
    const impactMet = Boolean(data.impact_met);
    const secondaryMet = Boolean(data.secondary_met);
    const needsArming = secondary.vectorMode && data.sim_armed === false;
    const incident = incidentType !== "NONE";
    elements.statusAcknowledge.hidden = !incident;
    elements.mpuStatus.textContent = connected && data.mpu ? "MPU6050 live" : "MPU6050 waiting";
    if (incidentType === "EMERGENCY") {
      setStatus({state: "emergency", code: "Emergency button", symbol: "!", title: "Immediate assistance was requested",
        description: textValue(data.incident_reason, "The patient-end emergency button was pressed in the live simulator."), risk: "Critical"});
    } else if (incidentType === "FALL") {
      setStatus({state: "fall", code: "Fall detected", symbol: "!", title: "A live fall signature was detected",
        description: textValue(data.fall_reason, `${numeric(data.accel).toFixed(2)} g and ${secondary.value.toFixed(1)} ${secondary.unit} crossed the active decision thresholds.`), risk: "Urgent"});
    } else if (!bridgeConnected) {
      setStatus({state: "offline", code: "Bridge offline", symbol: "–", title: "Open the monitor from Tinkered",
        description: "The dashboard server is ready, but the browser bridge has not reached it yet.", risk: "Offline"});
    } else if (!connected) {
      setStatus({state: "offline", code: "Waiting for telemetry", symbol: "…", title: "Start the Tinkered simulation",
        description: "The bridge is connected and waiting for the Serial Monitor or MPU6050 controls to produce a live packet.", risk: "Waiting"});
    } else if (needsArming) {
      setStatus({state: "pending", code: "Calibrating live baseline", symbol: "…", title: "Hold the simulated sensor near normal gravity",
        description: "Keep the Tinkered accelerometer near 1 g and steady briefly so the live fall detector can arm safely.", risk: "Arming"});
    } else if (impactMet || secondaryMet || fallState.startsWith("WAIT_")) {
      setStatus({state: "pending", code: "Possible fall · validating", symbol: "?", title: "One live fall signal was detected",
        description: `The monitor is checking ${impactMet ? secondary.label.toLowerCase() : "the G-force impact"} before confirming a fall.`, risk: "Elevated"});
    } else if (fallState === "COOLDOWN") {
      setStatus({state: "cooldown", code: "Monitoring · cooldown", symbol: "…", title: "Live monitoring continues",
        description: "A short cooldown is suppressing duplicate detections from the same movement.", risk: "Guarded"});
    } else {
      setStatus({state: "monitoring", code: "Tinkered live", symbol: "✓", title: "No fall detected",
        description: "Live movement is within the configured fall-detection thresholds.", risk: "Low"});
    }
  }

  function setStatus(status) {
    elements.statusPanel.dataset.state = status.state;
    elements.statusCode.textContent = status.code;
    elements.stateSymbol.textContent = status.symbol;
    elements.statusTitle.textContent = status.title;
    elements.statusDescription.textContent = status.description;
    elements.riskLabel.textContent = status.risk;
    document.title = status.state === "fall" || status.state === "emergency"
      ? `ALERT - ${status.title}`
      : "LihokSafe - Fall Detection Dashboard";
  }

  function renderMetrics(data, secondary, connected, fallIncident) {
    const accelThreshold = positive(data.impact_threshold_g, 2.2);
    const liveAccel = numeric(data.accel);
    const liveSecondaryValue = secondary.value;
    const accel = fallIncident ? positive(data.fall_impact_g, liveAccel) : liveAccel;
    const secondaryValue = fallIncident ? positive(data.fall_secondary_value, liveSecondaryValue) : liveSecondaryValue;
    const accelMet = fallIncident || Boolean(data.impact_met) || accel >= accelThreshold;
    const secondaryMet = fallIncident || Boolean(data.secondary_met) || secondaryValue >= secondary.threshold;
    const matched = fallIncident ? 2 : Number(Boolean(data.impact_met)) + Number(Boolean(data.secondary_met));
    elements.accelValue.textContent = connected ? accel.toFixed(2) : "—";
    elements.gyroValue.textContent = connected ? secondaryValue.toFixed(secondary.unit === "g" ? 2 : 1) : "—";
    elements.accelState.textContent = connected ? (accelMet ? "Threshold crossed" : "Normal") : "Waiting";
    elements.gyroState.textContent = connected ? (secondaryMet ? "Threshold crossed" : "Normal") : "Waiting";
    elements.accelMetric.classList.toggle("is-over", connected && accelMet);
    elements.gyroMetric.classList.toggle("is-over", connected && secondaryMet);
    elements.accelMetric.style.setProperty("--gauge-value", `${connected ? Math.min(100, (accel / accelThreshold) * 100) : 0}%`);
    elements.gyroMetric.style.setProperty("--gauge-value", `${connected ? Math.min(100, (secondaryValue / secondary.threshold) * 100) : 0}%`);
    elements.accelProgressFill.style.width = `${connected ? Math.min(100, (accel / accelThreshold) * 100) : 0}%`;
    elements.gyroProgressFill.style.width = `${connected ? Math.min(100, (secondaryValue / secondary.threshold) * 100) : 0}%`;
    elements.accelProgress.setAttribute("aria-valuemax", String(accelThreshold));
    elements.accelProgress.setAttribute("aria-valuenow", String(Math.min(accel, accelThreshold)));
    elements.accelProgress.setAttribute("aria-valuetext", connected ? `${accel.toFixed(2)} g` : "Waiting for live data");
    elements.gyroProgress.setAttribute("aria-valuemax", String(secondary.threshold));
    elements.gyroProgress.setAttribute("aria-valuenow", String(Math.min(secondaryValue, secondary.threshold)));
    elements.gyroProgress.setAttribute("aria-valuetext", connected ? `${secondaryValue.toFixed(1)} ${secondary.unit}` : "Waiting for live data");
    elements.secondaryEyebrow.textContent = secondary.vectorMode ? "Accelerometer vector" : "Rotational velocity";
    elements.secondaryTitle.textContent = secondary.label;
    elements.secondaryUnit.textContent = secondary.unit;
    elements.secondaryThreshold.textContent = formatReading(secondary.threshold, secondary.unit);
    elements.secondaryNodeLabel.textContent = `${secondary.label} signal`;
    elements.secondaryChartTitle.textContent = secondary.label;
    elements.decisionModeBadge.textContent = secondary.vectorMode ? "Tinkered vector logic" : "Firmware rotation logic";
    elements.accelNode.classList.toggle("is-matched", fallIncident || Boolean(data.impact_met));
    elements.gyroNode.classList.toggle("is-matched", fallIncident || Boolean(data.secondary_met));
    elements.correlationCopy.textContent = connected ? (fallIncident ? "2 of 2 live signals confirmed" : `${matched} of 2 live signals matched`) : "Waiting for live signals";
    elements.cooldownCopy.textContent = textValue(data.fall_state, data.sim_armed ? "Armed" : "Waiting").replaceAll("_", " ");
    elements.accelChartSummary.textContent = connected ? `Live G-force is ${liveAccel >= accelThreshold ? "above" : "below"} the ${accelThreshold.toFixed(2)} g impact threshold.` : "Waiting for live Tinkered G-force data.";
    elements.gyroChartSummary.textContent = connected ? `Live ${secondary.label.toLowerCase()} is ${liveSecondaryValue >= secondary.threshold ? "above" : "below"} the ${formatReading(secondary.threshold, secondary.unit)} threshold.` : "Waiting for live secondary evidence.";
    elements.accelChart.setAttribute("aria-label", connected ? `Live G-force ${liveAccel.toFixed(2)} g; threshold ${accelThreshold.toFixed(2)} g` : "Waiting for live G-force data");
    elements.gyroChart.setAttribute("aria-label", connected ? `Live ${secondary.label} ${liveSecondaryValue.toFixed(1)} ${secondary.unit}; threshold ${formatReading(secondary.threshold, secondary.unit)}` : "Waiting for live secondary evidence");
  }

  function renderDevice(data, secondary, bridgeConnected, connected, incident) {
    elements.dataSource.textContent = secondary.vectorMode ? "Tinkered sensor controls" : "Firmware Serial Monitor";
    elements.transportSummary.textContent = incident ? "Care-station alarm latched" : connected ? "Live monitoring active" : bridgeConnected ? "Bridge ready · no packet" : "Waiting for browser bridge";
    elements.sessionUptime.textContent = formatDuration(numeric(data.uptime_ms));
  }

  function renderAxes(data, connected) {
    [elements.axisAx, elements.axisAy, elements.axisAz].forEach((element, index) => {
      element.textContent = connected ? numeric(data[["ax", "ay", "az"][index]]).toFixed(2) : "—";
    });
    [elements.axisGx, elements.axisGy, elements.axisGz].forEach((element, index) => {
      element.textContent = connected ? numeric(data[["gx", "gy", "gz"][index]]).toFixed(1) : "—";
    });
  }

  function updateHistory(data, secondary, connected) {
    const stamp = numeric(data.telemetry_updated_ms);
    if (!connected || !stamp || stamp === lastTelemetryStamp) return;
    lastTelemetryStamp = stamp;
    accelHistory.push(numeric(data.accel));
    secondaryHistory.push(secondary.value);
    if (accelHistory.length > MAX_CHART_POINTS) accelHistory.shift();
    if (secondaryHistory.length > MAX_CHART_POINTS) secondaryHistory.shift();
  }

  function renderChartStats(data, secondary, connected) {
    const accelThreshold = positive(data.impact_threshold_g, 2.2);
    const accelNow = numeric(data.accel);
    const accelPeak = accelHistory.length ? Math.max.apply(null, accelHistory) : accelNow;
    const secondaryNow = secondary.value;
    const secondaryPeak = secondaryHistory.length ? Math.max.apply(null, secondaryHistory) : secondaryNow;
    elements.accelCurrentStat.textContent = connected ? `${accelNow.toFixed(2)} g` : "-- g";
    elements.accelPeakStat.textContent = connected ? `${accelPeak.toFixed(2)} g` : "-- g";
    elements.accelThresholdStat.textContent = `${accelThreshold.toFixed(2)} g`;
    elements.secondaryCurrentStat.textContent = connected ? formatReading(secondaryNow, secondary.unit) : "--";
    elements.secondaryPeakStat.textContent = connected ? formatReading(secondaryPeak, secondary.unit) : "--";
    elements.secondaryThresholdStat.textContent = formatReading(secondary.threshold, secondary.unit);
  }

  function renderSessionSummary(data, secondary, events) {
    const fallCount = Math.max(0, Math.floor(numeric(data.fall_count)));
    const emergencyCount = events.filter((event) => textValue(event.type).toUpperCase() === "EMERGENCY_BUTTON").length;
    const latest = events[0];
    const latestEmergency = latest && textValue(latest.type).toUpperCase() === "EMERGENCY_BUTTON";
    elements.fallCount.textContent = String(fallCount);
    elements.emergencyCount.textContent = String(emergencyCount);
    elements.activeSource.textContent = data.connected
      ? (secondary.vectorMode ? "Posture vector" : "Gyro + impact")
      : "No live source";
    elements.lastAlert.textContent = latest ? (latestEmergency ? "Emergency SOS" : "Fall detected") : "None";
    const timestamp = numeric(latest && latest.time_ms);
    elements.lastAlertTime.textContent = timestamp ? formatTime(new Date(timestamp)) : "No event recorded";
  }

  function renderEvents(events, activeIncident) {
    elements.eventList.querySelectorAll(".event-item").forEach((item) => item.remove());
    elements.emptyEvents.hidden = events.length > 0;
    elements.exportEvents.disabled = events.length === 0;
    elements.clearEvents.disabled = events.length === 0 || activeIncident;
    elements.clearEvents.title = activeIncident
      ? "Acknowledge the active alarm before clearing history"
      : events.length ? "Clear this session's event history" : "No events to clear";
    events.forEach((event) => {
      const emergency = textValue(event.type).toUpperCase() === "EMERGENCY_BUTTON";
      const item = document.createElement("li"); item.className = `event-item${emergency ? " is-emergency" : ""}`;
      const icon = document.createElement("span"); icon.className = "event-icon"; icon.setAttribute("aria-hidden", "true"); icon.textContent = emergency ? "!" : "↘";
      const main = document.createElement("div"); main.className = "event-main";
      const title = document.createElement("b"); title.textContent = emergency ? "Emergency button" : "Fall detected";
      const detail = document.createElement("small"); detail.textContent = textValue(event.reason, emergency ? "Manual SOS from the simulated wearable" : "Live fall evidence confirmed");
      main.append(title, detail);
      const meta = document.createElement("div"); meta.className = "event-meta";
      const time = document.createElement("time"); const timestamp = numeric(event.time_ms);
      if (timestamp) time.dateTime = new Date(timestamp).toISOString();
      time.textContent = timestamp ? formatTime(new Date(timestamp)) : "Live session";
      const source = document.createElement("span"); source.className = "event-delivery is-delivered"; source.textContent = textValue(event.source, "TINKERED").replaceAll("_", " ");
      meta.append(time, source); item.append(icon, main, meta); elements.eventList.appendChild(item);
    });
  }

  function handleIncomingAlert(data, secondary, incidentType) {
    const events = Array.isArray(data.events) ? data.events : [];
    const incident = incidentType !== "NONE";
    const wantedEventType = incidentType === "EMERGENCY" ? "EMERGENCY_BUTTON" : "FALL_DETECTED";
    const event = events.find((entry) => textValue(entry.type).toUpperCase() === wantedEventType) || events[0];
    const timestamp = numeric(data.incident_time_ms, numeric(event && event.time_ms, numeric(data.last_fall_ms)));
    const alertKey = incident ? `${incidentType}-${timestamp || textValue(data.incident_reason, "active")}` : "";
    if (incident && !alertOpen && alertKey !== shownAlertKey) {
      shownAlertKey = alertKey;
      openAlertDialog(incidentType === "EMERGENCY" ? "emergency" : "fall", data, secondary, event);
    } else if (!incident && alertOpen) {
      closeAlertDialog(false);
    }
  }

  function sustainActiveAlarm(incidentType) {
    if (incidentType === "NONE") {
      lastAlarmPulseAt = 0;
      return;
    }
    const now = Date.now();
    if (now - lastAlarmPulseAt < 4200) return;
    lastAlarmPulseAt = now;
    playAlarm(incidentType === "EMERGENCY" ? "emergency" : "fall");
  }

  function openAlertDialog(type, data, secondary, event) {
    alertOpen = true;
    lastFocusedElement = document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : elements.statusAcknowledge;
    const emergency = type === "emergency";
    elements.alertDialog.classList.toggle("is-emergency", emergency);
    elements.alertDialogKicker.textContent = emergency ? "ROBOT CARE ALERT - SOS" : "ROBOT CARE ALERT - FALL";
    elements.alertDialogTitle.textContent = emergency ? "Emergency Signal Locked" : "Fall Event Confirmed";
    elements.alertDialogDescription.textContent = emergency
      ? textValue(data.incident_reason, "The simulated wearable emergency button requested immediate assistance.")
      : textValue(data.incident_reason, textValue(data.fall_reason, "Both live fall-decision factors crossed their configured thresholds."));
    elements.alertEvidence.hidden = emergency;
    const impact = positive(data.fall_impact_g, numeric(data.accel));
    const secondaryValue = positive(data.fall_secondary_value, secondary.value);
    const secondaryLabel = textValue(data.fall_secondary_label, secondary.label);
    const secondaryUnit = textValue(data.fall_secondary_unit, secondary.unit).replace("deg/s", "°/s");
    elements.alertAccel.textContent = `${impact.toFixed(2)} g`;
    elements.alertGyro.textContent = `${secondaryValue.toFixed(secondaryUnit === "g" ? 2 : 1)} ${secondaryUnit}`;
    elements.alertSecondaryLabel.textContent = secondaryLabel;
    const timestamp = numeric(data.incident_time_ms, numeric(event && event.time_ms, numeric(data.last_fall_ms)));
    elements.alertTime.textContent = timestamp ? formatTime(new Date(timestamp)) : "Now";
    elements.deliveryState.classList.add("is-delivered");
    elements.deliveryState.querySelector("span").textContent = "Alert locked until operator acknowledgement";
    elements.alertOverlay.hidden = false; document.body.style.overflow = "hidden"; setBackgroundInert(true);
    elements.urgentAnnouncer.textContent = emergency ? "Emergency button pressed in Tinkered. Immediate assistance requested." : "Live Tinkered fall detected. Immediate assistance required.";
    playAlarm(emergency ? "emergency" : "fall");
    requestAnimationFrame(() => elements.dialogAcknowledge.focus());
  }

  function closeAlertDialog(restoreFocus = true) {
    alertOpen = false; elements.alertOverlay.hidden = true; document.body.style.overflow = ""; setBackgroundInert(false);
    if (restoreFocus && lastFocusedElement && lastFocusedElement.isConnected) lastFocusedElement.focus();
    lastFocusedElement = null;
  }
  function setBackgroundInert(inert) { $(".topbar").inert = inert; $("#main-content").inert = inert; }

  function dismissAlertDialog() {
    closeAlertDialog(true);
    elements.politeAnnouncer.textContent = "Alert dialog closed. The alarm remains active until acknowledged.";
  }

  async function acknowledgeAlert() {
    const buttons = [elements.dialogAcknowledge, elements.statusAcknowledge];
    buttons.forEach((button) => { button.disabled = true; });
    elements.dialogAcknowledge.textContent = "Resetting live alarm...";
    elements.statusAcknowledge.textContent = "Resetting alarm...";
    try {
      const response = await fetch("/api/acknowledge", { method: "POST" });
      if (!response.ok) throw new Error("acknowledge failed");
      if (alertOpen) closeAlertDialog(true);
      await pollLive(false);
      elements.politeAnnouncer.textContent = "Live alarm acknowledged. Event history was preserved.";
    } catch (_error) {
      elements.dialogAcknowledge.textContent = "Retry acknowledgement";
      elements.statusAcknowledge.textContent = "Retry acknowledgement";
      elements.urgentAnnouncer.textContent = "The live alarm could not be reset. Please retry.";
    } finally {
      buttons.forEach((button) => { button.disabled = false; });
      if (elements.dialogAcknowledge.textContent !== "Retry acknowledgement") {
        elements.dialogAcknowledge.textContent = "Acknowledge and reset alarm";
        elements.statusAcknowledge.textContent = "Acknowledge alert";
      }
    }
  }

  async function clearEventHistory() {
    if (!latestEvents.length) return;
    if (latestData && incidentActive(latestData)) {
      elements.politeAnnouncer.textContent = "Acknowledge the active alarm before clearing event history.";
      return;
    }
    if (!window.confirm("Clear this live monitoring session's event history?")) return;
    try {
      const response = await fetch("/api/events/clear", { method: "POST" });
      if (response.status === 409) throw new Error("active incident");
      if (!response.ok) throw new Error("clear failed");
      await pollLive(false);
      elements.politeAnnouncer.textContent = "Live event history cleared.";
    } catch (_error) { elements.politeAnnouncer.textContent = "Could not clear the live event history. Try again."; }
  }

  async function pollLive(immediate = false) {
    if (pollInFlight) return;
    window.clearTimeout(pollTimer); pollInFlight = true;
    try {
      const response = await fetch("/api/live", { cache: "no-store" });
      if (!response.ok) throw new Error("bridge unavailable");
      render(await response.json());
      if (immediate) elements.politeAnnouncer.textContent = "Live Tinkered connection checked.";
    } catch (_error) { renderServerOffline(); }
    finally { pollInFlight = false; pollTimer = window.setTimeout(pollLive, POLL_INTERVAL_MS); }
  }

  function renderServerOffline() {
    document.body.dataset.telemetry = "offline";
    setConnectionState(elements.connectionBadge, "disconnected"); elements.connectionLabel.textContent = "Monitor offline";
    elements.serverStep.classList.remove("is-complete"); elements.extensionStep.classList.remove("is-complete"); elements.telemetryStep.classList.remove("is-complete");
    elements.serverState.textContent = "Offline"; elements.extensionState.textContent = "Unknown"; elements.telemetryState.textContent = "Unavailable";
    setConnectionState(elements.bridgeBadge, "disconnected"); elements.bridgeBadge.innerHTML = '<i aria-hidden="true"></i> Monitor offline';
    elements.bridgeHelp.textContent = "Start the live dashboard bridge, then reopen the monitor from Tinkered.";
    setConnectionState(elements.deviceBadge, "disconnected"); elements.deviceBadge.innerHTML = '<i aria-hidden="true"></i> Offline';
    setStatus({state: "offline", code: "Local monitor offline", symbol: "–", title: "The live bridge is not running", description: "Start the dashboard bridge to receive Tinkered telemetry in real time.", risk: "Offline"});
    const staleData = latestData || {};
    const secondary = getSecondaryConfig(staleData);
    elements.statusAcknowledge.hidden = true;
    elements.mpuStatus.textContent = "MPU6050 waiting";
    elements.lastUpdateLabel.textContent = "Live data unavailable";
    elements.sampleAge.textContent = "Offline";
    renderMetrics(staleData, secondary, false, false);
    renderAxes(staleData, false);
    renderDevice(staleData, secondary, false, false, false);
    renderSessionSummary({...staleData, connected: false}, secondary, latestEvents);
    renderChartStats(staleData, secondary, false);
    renderEvents(latestEvents, false);
    elements.clearEvents.disabled = true;
    elements.clearEvents.title = "Start the local monitor before clearing history";
  }

  function drawCharts() {
    drawChart(elements.accelChart, accelHistory, {threshold: latestData ? positive(latestData.impact_threshold_g, 2.2) : 2.2, minimumMax: 3, unit: "g", decimals: 1});
    drawChart(elements.gyroChart, secondaryHistory, {threshold: secondaryChartConfig.threshold, minimumMax: secondaryChartConfig.unit === "g" ? 1.2 : secondaryChartConfig.unit === "deg" ? 55 : 300, unit: secondaryChartConfig.unit, decimals: secondaryChartConfig.unit === "g" ? 1 : 0});
  }

  function drawChart(canvas, values, options) {
    const rect = canvas.getBoundingClientRect(); if (rect.width < 1 || rect.height < 1) return;
    const ratio = Math.min(window.devicePixelRatio || 1, 2), width = Math.round(rect.width), height = Math.round(rect.height);
    canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
    const context = canvas.getContext("2d"); context.setTransform(ratio, 0, 0, ratio, 0, 0); context.clearRect(0, 0, width, height);
    const padding = {top: 16, right: 56, bottom: 28, left: 12}, plotWidth = width - padding.left - padding.right, plotHeight = height - padding.top - padding.bottom;
    const dataMaximum = values.length ? Math.max.apply(null, values) : 0;
    const chartMaximum = Math.max(options.minimumMax, options.threshold * 1.25, dataMaximum * 1.12);
    const pointX = index => padding.left + ((MAX_CHART_POINTS - values.length + index) / (MAX_CHART_POINTS - 1)) * plotWidth;
    const pointY = value => padding.top + plotHeight - (Math.max(0, value) / chartMaximum) * plotHeight;
    const thresholdY = pointY(options.threshold);
    const dark = getComputedStyle(document.body).getPropertyValue("--chart-mode").trim() === "dark";
    const colors = dark
      ? {
          background: "#0b1118", dangerBand: "rgba(255, 69, 77, 0.13)",
          axis: "#22303d", grid: "rgba(148, 163, 184, 0.13)", text: "#90a4b5",
          threshold: "#ff454d", danger: "#ff454d", live: "#22d3ee",
          liveFillTop: "rgba(34, 211, 238, 0.26)", liveFillBottom: "rgba(34, 211, 238, 0.02)",
          dangerFillTop: "rgba(255, 69, 77, 0.30)", dangerFillBottom: "rgba(255, 69, 77, 0.03)",
          pointStroke: "#0b1118", labelText: "#061018"
        }
      : {
          background: "#fbfdff", dangerBand: "rgba(212, 71, 56, 0.075)",
          axis: "#bccad5", grid: "#e8eef3", text: "#5d7074",
          threshold: "#d44738", danger: "#d44738", live: "#188568",
          liveFillTop: "rgba(24, 133, 104, 0.22)", liveFillBottom: "rgba(24, 133, 104, 0.02)",
          dangerFillTop: "rgba(212, 71, 56, 0.24)", dangerFillBottom: "rgba(212, 71, 56, 0.02)",
          pointStroke: "#ffffff", labelText: "#ffffff"
        };
    context.fillStyle = colors.background;
    context.fillRect(padding.left, padding.top, plotWidth, plotHeight);
    context.fillStyle = colors.dangerBand;
    context.fillRect(padding.left, padding.top, plotWidth, Math.max(0, thresholdY - padding.top));
    context.font = '10px "Segoe UI", system-ui, sans-serif'; context.textAlign = "right"; context.textBaseline = "middle";
    for (let step = 0; step <= 4; step += 1) {
      const value = (chartMaximum / 4) * step, y = pointY(value); context.beginPath(); context.moveTo(padding.left, y); context.lineTo(width - padding.right, y);
      context.strokeStyle = step === 0 ? colors.axis : colors.grid; context.lineWidth = 1; context.stroke(); context.fillStyle = colors.text;
      context.fillText(`${value.toFixed(options.decimals)} ${options.unit}`, width - 2, y);
    }
    for (let step = 1; step < 4; step += 1) {
      const x = padding.left + (plotWidth / 4) * step;
      context.beginPath(); context.moveTo(x, padding.top); context.lineTo(x, padding.top + plotHeight);
      context.strokeStyle = colors.grid; context.lineWidth = 1; context.stroke();
    }
    context.save(); context.setLineDash([6, 5]); context.strokeStyle = colors.threshold; context.lineWidth = 1.5; context.beginPath(); context.moveTo(padding.left, thresholdY); context.lineTo(width - padding.right, thresholdY); context.stroke(); context.restore();
    context.font = '700 10px "Segoe UI", system-ui, sans-serif';
    context.textAlign = "left"; context.textBaseline = "bottom";
    context.fillStyle = colors.threshold;
    context.fillText("limit", padding.left + 4, thresholdY - 4);
    if (values.length) {
      const latest = values[values.length - 1];
      const overLimit = latest >= options.threshold;
      const gradient = context.createLinearGradient(0, padding.top, 0, padding.top + plotHeight);
      gradient.addColorStop(0, overLimit ? colors.dangerFillTop : colors.liveFillTop);
      gradient.addColorStop(1, overLimit ? colors.dangerFillBottom : colors.liveFillBottom);
      context.beginPath();
      values.forEach((value, index) => { const x = pointX(index), y = pointY(value); if (index === 0) context.moveTo(x, y); else context.lineTo(x, y); });
      context.lineTo(pointX(values.length - 1), padding.top + plotHeight);
      context.lineTo(pointX(0), padding.top + plotHeight);
      context.closePath();
      context.fillStyle = gradient;
      context.fill();
      context.beginPath(); values.forEach((value, index) => { const x = pointX(index), y = pointY(value); if (index === 0) context.moveTo(x, y); else context.lineTo(x, y); });
      context.strokeStyle = overLimit ? colors.danger : colors.live; context.lineWidth = 2.75; context.lineJoin = "round"; context.lineCap = "round"; context.stroke();
      const firstPoint = Math.max(0, values.length - 6);
      for (let index = firstPoint; index < values.length; index += 1) {
        const value = values[index];
        context.beginPath(); context.arc(pointX(index), pointY(value), value >= options.threshold ? 3.2 : 2.4, 0, Math.PI * 2);
        context.fillStyle = value >= options.threshold ? colors.danger : colors.live; context.fill();
        context.lineWidth = 1.5; context.strokeStyle = colors.pointStroke; context.stroke();
      }
      const last = values.length - 1;
      const label = `${latest.toFixed(options.decimals)} ${options.unit}`;
      const labelWidth = Math.min(86, Math.max(46, context.measureText(label).width + 14));
      const labelX = Math.min(width - padding.right - labelWidth, Math.max(padding.left, pointX(last) - labelWidth - 8));
      const labelY = Math.max(padding.top + 6, Math.min(padding.top + plotHeight - 24, pointY(latest) - 14));
      context.fillStyle = overLimit ? colors.danger : colors.live;
      context.beginPath();
      context.roundRect(labelX, labelY, labelWidth, 22, 6);
      context.fill();
      context.fillStyle = colors.labelText;
      context.font = '750 10px "Segoe UI", system-ui, sans-serif';
      context.textAlign = "center"; context.textBaseline = "middle";
      context.fillText(label, labelX + labelWidth / 2, labelY + 11);
    }
    context.fillStyle = colors.text; context.font = '10px "Segoe UI", system-ui, sans-serif'; context.textBaseline = "bottom"; context.textAlign = "left"; context.fillText("15s ago", padding.left, height - 3);
    context.textAlign = "center"; context.fillText("10s", padding.left + plotWidth / 3, height - 3); context.fillText("5s", padding.left + (plotWidth * 2) / 3, height - 3); context.textAlign = "right"; context.fillText("now", width - padding.right, height - 3);
  }

  function playAlarm(type) {
    if (!soundEnabled) return;
    try {
      audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)(); const start = audioContext.currentTime;
      const pulses = type === "emergency" ? [0, 0.46, 0.92] : [0, 0.25, 0.62];
      pulses.forEach((offset, index) => { const oscillator = audioContext.createOscillator(), gain = audioContext.createGain(); oscillator.type = "sine"; oscillator.frequency.value = type === "emergency" ? 760 : index === 2 ? 650 : 880;
        gain.gain.setValueAtTime(0.0001, start + offset); gain.gain.exponentialRampToValueAtTime(0.16, start + offset + 0.015); gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.18);
        oscillator.connect(gain); gain.connect(audioContext.destination); oscillator.start(start + offset); oscillator.stop(start + offset + 0.2); });
    } catch (_error) { soundEnabled = false; updateSoundButton(); }
  }

  function updateSoundButton() {
    elements.soundToggle.setAttribute("aria-pressed", String(soundEnabled)); elements.soundToggle.title = soundEnabled ? "Live alert sounds are on" : "Live alert sounds are off"; elements.soundLabel.textContent = soundEnabled ? "Sound on" : "Sound off";
  }

  function exportEvents() {
    if (!latestEvents.length) return;
    const rows = [["event", "detected_at", "source", "reason"], ...latestEvents.map(event => [textValue(event.type, "EVENT"), numeric(event.time_ms) ? new Date(numeric(event.time_ms)).toISOString() : "", textValue(event.source, "TINKERED"), textValue(event.reason)])];
    const csv = rows.map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob([csv], {type: "text/csv;charset=utf-8"})), link = document.createElement("a");
    link.href = url; link.download = `lihoksafe-live-events-${new Date().toISOString().slice(0, 10)}.csv`; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
  }

  function updateClock() {
    const now = new Date(); elements.clock.textContent = formatTime(now); elements.clock.dateTime = now.toISOString();
    if (document.body.dataset.telemetry === "offline") {
      elements.lastUpdateLabel.textContent = "Live data unavailable";
      elements.sampleAge.textContent = "Offline";
    } else if (latestData) {
      const stamp = numeric(latestData.telemetry_updated_ms);
      elements.lastUpdateLabel.textContent = latestData.connected && stamp ? `Updated ${formatAge(stamp)}` : "No live packet";
      elements.sampleAge.textContent = latestData.connected && stamp ? formatAge(stamp) : "Waiting";
    }
  }
  function formatReading(value, unit) { return `${numeric(value).toFixed(unit === "g" ? 2 : Number.isInteger(numeric(value)) ? 0 : 1)} ${unit}`; }
  function formatAge(timestamp) { const ageMs = Math.max(0, Date.now() - numeric(timestamp)); return ageMs < 1200 ? "now" : `${Math.floor(ageMs / 1000)}s ago`; }
  function formatTime(date) { return date.toLocaleTimeString([], {hour: "2-digit", minute: "2-digit", second: "2-digit"}); }
  function formatDuration(milliseconds) { const totalSeconds = Math.max(0, Math.floor(numeric(milliseconds) / 1000)); return `${String(Math.floor(totalSeconds / 3600)).padStart(2, "0")}:${String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, "0")}:${String(totalSeconds % 60).padStart(2, "0")}`; }

  elements.checkConnection.addEventListener("click", () => pollLive(true));
  elements.statusAcknowledge.addEventListener("click", acknowledgeAlert); elements.dialogAcknowledge.addEventListener("click", acknowledgeAlert);
  elements.dialogDismiss.addEventListener("click", dismissAlertDialog);
  elements.exportEvents.addEventListener("click", exportEvents); elements.clearEvents.addEventListener("click", clearEventHistory);
  elements.soundToggle.addEventListener("click", async () => { soundEnabled = !soundEnabled; if (soundEnabled) { try { audioContext = audioContext || new (window.AudioContext || window.webkitAudioContext)(); await audioContext.resume(); } catch (_error) { soundEnabled = false; } } updateSoundButton(); });
  document.addEventListener("keydown", event => {
    if (!alertOpen) return;
    if (event.key === "Tab") {
      const focusable = [elements.dialogAcknowledge, elements.dialogDismiss];
      const currentIndex = focusable.indexOf(document.activeElement);
      event.preventDefault();
      const nextIndex = event.shiftKey
        ? (currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1)
        : (currentIndex + 1) % focusable.length;
      focusable[nextIndex].focus();
    } else if (event.key === "Escape") {
      event.preventDefault();
      dismissAlertDialog();
    }
  });
  window.addEventListener("resize", drawCharts);
  updateSoundButton(); updateClock(); window.setInterval(updateClock, 1000); drawCharts(); pollLive();
})();
