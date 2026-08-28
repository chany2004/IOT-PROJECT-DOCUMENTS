from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json
import math
import re
import socket
import threading
import time

ROOT = Path(__file__).resolve().parent
SERVER_ID = "fallguard-esp32-tinkered-workspace-1"
LOCK = threading.Lock()
SEEN = set()

# The browser bridge can read the real accelerometer controls from Tinkered,
# but the simulator currently reports no useful gyroscope motion.  These
# constants define a simulator-only second decision path: establish a stable
# gravity/posture vector first, then require both an impact and a substantial
# posture change.  No synthetic/demo sample is ever generated here.
IMPACT_THRESHOLD_G = 2.20
GYRO_THRESHOLD_DPS = 220.0
SIM_NORMAL_MIN_G = 0.75
SIM_NORMAL_MAX_G = 1.30
SIM_BASELINE_HOLD_SECONDS = 0.75
SIM_BASELINE_STABILITY_G = 0.25
SIM_POSTURE_THRESHOLD_DEG = 35.0
SIM_UNARMED_IMPACT_THRESHOLD_G = 2.80
DEFAULT_VECTOR_THRESHOLD_G = 0.80
SIM_GYRO_ZERO_MAX_DPS = 1.0
FALL_LATCH_SECONDS = 15.0
FALL_EVENT_DEBOUNCE_SECONDS = 2.0
SIM_REARM_COOLDOWN_SECONDS = 15.0
SIM_DEFAULT_UPRIGHT_VECTOR = (0.0, 0.0, 1.0)

STATE = {
    "last_seen": 0.0,
    "bridge_seen": 0.0,
    "bridge_protocol": "NONE",
    "telemetry_updated_ms": 0,
    "structured_seen": 0.0,
    "fall_until": 0.0,
    "fall_event_at": 0.0,
    "fall_active": False,
    "emergency_active": False,
    "incident_active": False,
    "incident_type": "NONE",
    "incident_reason": "",
    "incident_time_ms": 0,
    "fall_count": 0,
    "device_fall_count": 0,
    "device_fall_active": False,
    "last_fall_ms": 0,
    "fall_source": "NONE",
    "fall_reason": "",
    "fall_impact_g": 0.0,
    "fall_secondary_label": "",
    "fall_secondary_unit": "",
    "fall_secondary_value": 0.0,
    "mpu": False,
    "fall_state": "IDLE",
    "network_mode": "TINKERED_BRIDGE",
    "alarm": "Idle",
    "uptime_ms": 0,
    "accel": 0.0,
    "gyro": 0.0,
    "ax": 0.0, "ay": 0.0, "az": 0.0,
    "gx": 0.0, "gy": 0.0, "gz": 0.0,
    "decision_mode": "FIRMWARE",
    "evidence": "NONE",
    "vector_delta_g": 0.0,
    "impact_threshold_g": IMPACT_THRESHOLD_G,
    "sim_normal_min_g": SIM_NORMAL_MIN_G,
    "sim_normal_max_g": SIM_NORMAL_MAX_G,
    "sim_baseline_hold_ms": int(SIM_BASELINE_HOLD_SECONDS * 1000),
    "sim_unarmed_impact_threshold_g": SIM_UNARMED_IMPACT_THRESHOLD_G,
    "secondary_label": "Rapid rotation",
    "secondary_unit": "deg/s",
    "secondary_threshold": GYRO_THRESHOLD_DPS,
    "secondary_value": 0.0,
    "impact_met": False,
    "secondary_met": False,
    "sim_armed": False,
    "sim_arm_started": 0.0,
    "sim_candidate_ax": 0.0,
    "sim_candidate_ay": 0.0,
    "sim_candidate_az": 0.0,
    "sim_baseline_ax": 0.0,
    "sim_baseline_ay": 0.0,
    "sim_baseline_az": 0.0,
    "sim_last_fall_steady": 0.0,
    "events": [],
}

HB = re.compile(
    r"HB\s+uptime_s=(\d+).*?mpu_ok=(true|false).*?"
    r"accel_mag_g=([-+\d.]+).*?gyro_mag_dps=([-+\d.]+)", re.I
)
IMU = re.compile(
    r"a\[g\]=([-+\d.]+)\s+([-+\d.]+)\s+([-+\d.]+).*?"
    r"g\[dps\]=([-+\d.]+)\s+([-+\d.]+)\s+([-+\d.]+).*?"
    r"magA=([-+\d.]+).*?magG=([-+\d.]+)", re.I
)
UI = re.compile(
    r"TINKERED_UI.*?ax=([-+\d.]+).*?ay=([-+\d.]+).*?az=([-+\d.]+)"
    r"(?:.*?gx=([-+\d.]+).*?gy=([-+\d.]+).*?gz=([-+\d.]+))?", re.I
)
UI_VALID = re.compile(r"TINKERED_UI.*?\bvalid=1\b", re.I)
FG = re.compile(r"@@FG1:(\{.*?\}):FG@@")
FALL_STATE = re.compile(r"fall_state=([A-Z_]+)", re.I)
NETWORK_MODE = re.compile(r"network_mode=([A-Z_]+)", re.I)
RUNTIME_FALL = re.compile(
    r"^\s*(?:\[(?:WARN|ALERT|ERROR|INFO)\]\s*)?"
    r"(?:Fall detected:|LOCAL_ALARM_START:\s*FALL_DETECTED\b|"
    r"\[FALL\]\s*Fall signature detected\b)", re.I
)
RUNTIME_EMERGENCY = re.compile(
    r"^\s*(?:\[(?:WARN|ALERT|ERROR|INFO)\]\s*)?"
    r"(?:EMERGENCY_BUTTON_PRESSED\b|"
    r"\[BUTTON\]\s*Emergency button pressed\b)", re.I
)

def number(value, default=0.0):
    try:
        result = float(value)
        return result if math.isfinite(result) else default
    except (TypeError, ValueError):
        return default

def boolean(value, default=False):
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)) and math.isfinite(value):
        return value != 0
    if isinstance(value, str):
        normalized = value.strip().lower()
        if normalized in ("true", "1", "yes", "on"):
            return True
        if normalized in ("false", "0", "no", "off"):
            return False
    return default

def _vector_distance(first, second):
    return math.sqrt(sum((a - b) ** 2 for a, b in zip(first, second)))

def _vector_tilt_degrees(first, second):
    first_mag = math.sqrt(sum(value * value for value in first))
    second_mag = math.sqrt(sum(value * value for value in second))
    if first_mag < 1e-6 or second_mag < 1e-6:
        return 0.0
    cosine = sum(a * b for a, b in zip(first, second)) / (first_mag * second_mag)
    return math.degrees(math.acos(max(-1.0, min(1.0, cosine))))

def _append_fall_event_locked(now, source, reason, count=1):
    """Record a distinct fall without allowing another feed to double count it."""
    count = max(1, int(count))
    event_time_ms = int(now * 1000)
    STATE["fall_count"] += count
    STATE["fall_event_at"] = now
    STATE["last_fall_ms"] = event_time_ms
    STATE["fall_source"] = source
    STATE["fall_reason"] = reason
    STATE["incident_active"] = True
    STATE["incident_type"] = "FALL_DETECTED"
    STATE["incident_reason"] = reason
    STATE["incident_time_ms"] = event_time_ms
    STATE["events"].insert(0, {
        "type": "FALL_DETECTED",
        "time_ms": event_time_ms,
        "source": source,
        "reason": reason,
        "count": count,
    })
    STATE["events"] = STATE["events"][:8]

def _latch_fall_locked(now, steady_now, source, reason, count=1):
    """Latch an alarm and count it unless another feed just reported it."""
    distinct_event = now - STATE["fall_event_at"] > FALL_EVENT_DEBOUNCE_SECONDS
    if source == "FIRMWARE" and (
        distinct_event or STATE["fall_source"] != "TINKERED_ACCEL"
    ):
        STATE["fall_impact_g"] = STATE["accel"]
        if STATE["decision_mode"] == "TINKERED_ACCEL_VECTOR":
            STATE["fall_secondary_label"] = "Vector change"
            STATE["fall_secondary_unit"] = "g"
            STATE["fall_secondary_value"] = STATE["secondary_value"]
        else:
            STATE["fall_secondary_label"] = "Rapid rotation"
            STATE["fall_secondary_unit"] = "deg/s"
            STATE["fall_secondary_value"] = STATE["gyro"]
    STATE["fall_until"] = max(
        STATE["fall_until"], steady_now + FALL_LATCH_SECONDS
    )
    STATE["fall_active"] = True
    STATE["incident_active"] = True
    STATE["alarm"] = "Fall"
    STATE["fall_state"] = "CONFIRMED"
    if distinct_event:
        _append_fall_event_locked(now, source, reason, count)
        return True
    # Firmware confirmation is authoritative metadata even if the simulator
    # fallback observed the same physical event a moment earlier.
    if source == "FIRMWARE":
        STATE["fall_source"] = source
        STATE["fall_reason"] = reason
        if STATE["events"] and STATE["events"][0].get("type") == "FALL_DETECTED":
            STATE["events"][0]["source"] = source
            STATE["events"][0]["reason"] = reason
    return False

def _set_firmware_decision_locked(force=False):
    if (
        not force
        and STATE["decision_mode"].startswith("TINKERED_ACCEL")
        and STATE["gyro"] <= SIM_GYRO_ZERO_MAX_DPS
    ):
        # Heartbeats arrive independently of UI-axis packets.  Once a valid
        # simulator sample selected the fallback, a zero-gyro heartbeat must
        # not make the API flicker between decision modes.
        return
    STATE["decision_mode"] = "FIRMWARE"
    STATE["secondary_label"] = "Rapid rotation"
    STATE["secondary_unit"] = "deg/s"
    STATE["secondary_threshold"] = GYRO_THRESHOLD_DPS
    STATE["secondary_value"] = STATE["gyro"]
    STATE["impact_met"] = STATE["accel"] >= IMPACT_THRESHOLD_G
    STATE["secondary_met"] = STATE["gyro"] >= GYRO_THRESHOLD_DPS

def _set_structured_decision_locked(frame, fall):
    """Use optional detector evidence emitted by newer @@FG1 firmware."""
    raw_mode = str(frame.get("decision_mode", ""))[:64].upper()
    evidence = str(frame.get("evidence", "NONE"))[:64].upper()
    armed = boolean(frame.get("armed"), False)
    vector_delta = number(frame.get("vector_delta_g"), 0.0)
    accel_threshold = number(
        frame.get("accel_threshold_g"), IMPACT_THRESHOLD_G
    )
    gyro_threshold = number(
        frame.get("gyro_threshold_dps"), GYRO_THRESHOLD_DPS
    )
    vector_threshold = number(
        frame.get("vector_threshold_g"), DEFAULT_VECTOR_THRESHOLD_G
    )
    if accel_threshold <= 0:
        accel_threshold = IMPACT_THRESHOLD_G
    if gyro_threshold <= 0:
        gyro_threshold = GYRO_THRESHOLD_DPS
    if vector_threshold <= 0:
        vector_threshold = DEFAULT_VECTOR_THRESHOLD_G

    fall_accel = number(frame.get("fall_evidence_accel_g"), STATE["accel"])
    fall_gyro = number(frame.get("fall_evidence_gyro_dps"), STATE["gyro"])
    fall_vector = number(
        frame.get("fall_evidence_vector_delta_g"), vector_delta
    )

    STATE["evidence"] = evidence
    STATE["vector_delta_g"] = vector_delta
    STATE["impact_threshold_g"] = accel_threshold
    vector_mode = "VECTOR" in raw_mode and STATE["gyro"] <= SIM_GYRO_ZERO_MAX_DPS

    if vector_mode:
        # The firmware made this decision from its real MPU6050 acceleration
        # samples.  Use its own vector delta/threshold, rather than relabeling
        # a zero simulator gyro reading as rotation evidence.
        use_fall_evidence = fall and "VECTOR" in evidence
        display_accel = fall_accel if use_fall_evidence else STATE["accel"]
        display_vector = fall_vector if use_fall_evidence else vector_delta
        STATE["decision_mode"] = "TINKERED_ACCEL_VECTOR"
        STATE["secondary_label"] = "Vector change"
        STATE["secondary_unit"] = "g"
        STATE["secondary_threshold"] = vector_threshold
        STATE["secondary_value"] = display_vector
        STATE["sim_armed"] = armed
        STATE["impact_met"] = display_accel >= accel_threshold
        STATE["secondary_met"] = display_vector >= vector_threshold
        if fall:
            STATE["fall_impact_g"] = fall_accel
            STATE["fall_secondary_label"] = "Vector change"
            STATE["fall_secondary_unit"] = "g"
            STATE["fall_secondary_value"] = fall_vector
        return

    STATE["decision_mode"] = "FIRMWARE"
    STATE["secondary_label"] = "Rapid rotation"
    STATE["secondary_unit"] = "deg/s"
    STATE["secondary_threshold"] = gyro_threshold
    STATE["secondary_value"] = fall_gyro if fall else STATE["gyro"]
    STATE["sim_armed"] = False
    STATE["impact_met"] = (fall_accel if fall else STATE["accel"]) >= accel_threshold
    STATE["secondary_met"] = STATE["secondary_value"] >= gyro_threshold
    if fall:
        STATE["fall_impact_g"] = fall_accel
        STATE["fall_secondary_label"] = "Rapid rotation"
        STATE["fall_secondary_unit"] = "deg/s"
        STATE["fall_secondary_value"] = fall_gyro

def _reset_sim_arm_locked():
    STATE["sim_armed"] = False
    STATE["sim_arm_started"] = 0.0
    STATE["sim_candidate_ax"] = 0.0
    STATE["sim_candidate_ay"] = 0.0
    STATE["sim_candidate_az"] = 0.0

def _acknowledge_incident_locked():
    """Unlatch active alarms while preserving this session's audit trail."""
    STATE["fall_until"] = 0
    STATE["fall_active"] = False
    STATE["emergency_active"] = False
    STATE["incident_active"] = False
    STATE["incident_type"] = "NONE"
    STATE["incident_reason"] = ""
    STATE["incident_time_ms"] = 0
    STATE["alarm"] = "Idle"
    if STATE["fall_state"] == "CONFIRMED":
        STATE["fall_state"] = "IDLE"
    # A new emergency-button press uses the same runtime text, so it must be
    # eligible after acknowledgement. Device fall edge/counter state is kept
    # to prevent a still-asserted structured fall frame from reopening itself.
    SEEN.clear()
    _reset_sim_arm_locked()

def _clear_event_history_locked():
    """Clear retained session history only when no alarm is still active."""
    if STATE["incident_active"]:
        return False
    STATE["fall_count"] = 0
    STATE["events"] = []
    return True

def _clear_incidents_locked():
    """Backward-compatible full reset of alarms and retained session history."""
    _acknowledge_incident_locked()
    STATE["fall_count"] = 0
    STATE["fall_event_at"] = 0
    STATE["last_fall_ms"] = 0
    STATE["fall_source"] = "NONE"
    STATE["fall_reason"] = ""
    STATE["fall_impact_g"] = 0.0
    STATE["fall_secondary_label"] = ""
    STATE["fall_secondary_unit"] = ""
    STATE["fall_secondary_value"] = 0.0
    STATE["secondary_value"] = 0.0
    STATE["secondary_met"] = False
    STATE["sim_baseline_ax"] = 0.0
    STATE["sim_baseline_ay"] = 0.0
    STATE["sim_baseline_az"] = 0.0
    STATE["events"] = []

def _process_tinkered_accel_locked(values, gyro_available, now, steady_now):
    """Evaluate one valid, real Tinkered accelerometer UI sample."""
    vector = tuple(values[:3])
    accel = math.sqrt(sum(value * value for value in vector))
    gyro_zero = (not gyro_available) or STATE["gyro"] <= SIM_GYRO_ZERO_MAX_DPS
    # This function is reached only by a valid=1 TINKERED_UI packet whose
    # three acceleration axes were read from the real simulator controls.  It
    # therefore remains a safe fallback even when the Serial Monitor DOM (and
    # its SIMULATOR_SAFE heartbeat) is inaccessible to the extension.
    eligible = gyro_zero

    if (
        eligible
        and STATE["decision_mode"] == "TINKERED_ACCEL_VECTOR"
        and steady_now - STATE["structured_seen"] < 3.0
    ):
        # Fresh structured firmware evidence is more authoritative than the
        # browser-side fallback computed from the same Tinkered controls.
        return

    if not eligible:
        _reset_sim_arm_locked()
        _set_firmware_decision_locked(force=True)
        return

    STATE["decision_mode"] = "TINKERED_ACCEL"
    STATE["secondary_label"] = "Posture change"
    STATE["secondary_unit"] = "deg"
    STATE["secondary_threshold"] = SIM_POSTURE_THRESHOLD_DEG
    STATE["impact_met"] = accel >= IMPACT_THRESHOLD_G
    normal = SIM_NORMAL_MIN_G <= accel <= SIM_NORMAL_MAX_G
    cooldown_complete = (
        STATE["sim_last_fall_steady"] == 0.0
        or steady_now - STATE["sim_last_fall_steady"] >= SIM_REARM_COOLDOWN_SECONDS
    )

    baseline = (
        STATE["sim_baseline_ax"],
        STATE["sim_baseline_ay"],
        STATE["sim_baseline_az"],
    )
    comparison_vector = baseline if STATE["sim_armed"] else SIM_DEFAULT_UPRIGHT_VECTOR
    tilt = _vector_tilt_degrees(comparison_vector, vector)
    unarmed_fall_candidate = (
        not STATE["sim_armed"]
        and accel >= SIM_UNARMED_IMPACT_THRESHOLD_G
        and tilt >= SIM_POSTURE_THRESHOLD_DEG
        and cooldown_complete
    )
    STATE["secondary_value"] = tilt
    STATE["secondary_met"] = (
        (STATE["sim_armed"] and tilt >= SIM_POSTURE_THRESHOLD_DEG)
        or unarmed_fall_candidate
    )

    if not STATE["sim_armed"]:
        if unarmed_fall_candidate:
            reason = (
                f"Tinkered high impact {accel:.2f} g and posture change "
                f"{tilt:.1f} deg before baseline"
            )
            STATE["fall_impact_g"] = accel
            STATE["fall_secondary_label"] = "Posture change"
            STATE["fall_secondary_unit"] = "deg"
            STATE["fall_secondary_value"] = tilt
            _latch_fall_locked(now, steady_now, "TINKERED_ACCEL", reason)
            STATE["sim_last_fall_steady"] = steady_now
            _reset_sim_arm_locked()
            return
        if not normal or not cooldown_complete:
            STATE["sim_arm_started"] = 0.0
            return
        candidate = (
            STATE["sim_candidate_ax"],
            STATE["sim_candidate_ay"],
            STATE["sim_candidate_az"],
        )
        if STATE["sim_arm_started"] == 0.0 or _vector_distance(candidate, vector) > SIM_BASELINE_STABILITY_G:
            STATE["sim_arm_started"] = steady_now
            STATE["sim_candidate_ax"], STATE["sim_candidate_ay"], STATE["sim_candidate_az"] = vector
            return
        if steady_now - STATE["sim_arm_started"] >= SIM_BASELINE_HOLD_SECONDS:
            STATE["sim_armed"] = True
            STATE["sim_baseline_ax"], STATE["sim_baseline_ay"], STATE["sim_baseline_az"] = candidate
        return

    if normal and _vector_distance(baseline, vector) > SIM_BASELINE_STABILITY_G:
        # A slow, non-impact posture change becomes the new baseline only after
        # another stable hold; it must not prime a false impact confirmation.
        _reset_sim_arm_locked()
        STATE["sim_arm_started"] = steady_now
        STATE["sim_candidate_ax"], STATE["sim_candidate_ay"], STATE["sim_candidate_az"] = vector
        STATE["secondary_value"] = 0.0
        STATE["secondary_met"] = False
        return

    if STATE["impact_met"] and STATE["secondary_met"] and cooldown_complete:
        reason = (
            f"Tinkered impact {accel:.2f} g and posture change {tilt:.1f} deg"
        )
        STATE["fall_impact_g"] = accel
        STATE["fall_secondary_label"] = "Posture change"
        STATE["fall_secondary_unit"] = "deg"
        STATE["fall_secondary_value"] = tilt
        _latch_fall_locked(now, steady_now, "TINKERED_ACCEL", reason)
        STATE["sim_last_fall_steady"] = steady_now
        _reset_sim_arm_locked()

def ingest(line):
    line = line.strip()
    if not line:
        return
    now = time.time()
    steady_now = time.monotonic()
    with LOCK:
        # A non-empty line proves that the browser connector reached us, even
        # when the line itself is not a complete/valid telemetry packet.
        STATE["bridge_seen"] = steady_now
        if line.startswith("[TINKERED_BRIDGE]") or UI_VALID.search(line):
            STATE["bridge_protocol"] = "V1.2"
        elif "TINKERED_UI" in line and STATE["bridge_protocol"] == "NONE":
            STATE["bridge_protocol"] = "LEGACY"
    frame_match = FG.search(line)
    if frame_match:
        try:
            frame = json.loads(frame_match.group(1))
            required = ("seq", "uptime_ms", "mpu", "ax", "ay", "az", "gx", "gy", "gz", "accel", "gyro", "fall", "fall_count")
            if not isinstance(frame, dict) or not all(key in frame for key in required):
                return
            int(frame["seq"])
            uptime = max(0, int(frame["uptime_ms"]))
            incoming_count = max(0, int(frame["fall_count"]))
            mpu = boolean(frame["mpu"])
            fall = boolean(frame["fall"])
        except (json.JSONDecodeError, TypeError, ValueError, OverflowError):
            return
        with LOCK:
            restarted = uptime < STATE["uptime_ms"]
            if restarted:
                # A device reboot resets only its raw counter. Dashboard-side
                # incidents and acknowledgements belong to the care session
                # and must never be erased by a stale/restarted device frame.
                STATE["device_fall_count"] = 0
                STATE["device_fall_active"] = False
            previous_device_count = STATE["device_fall_count"]
            device_delta = max(0, incoming_count - previous_device_count)
            if restarted or incoming_count >= previous_device_count:
                STATE["device_fall_count"] = incoming_count
            new_fall_flag = fall and not STATE["device_fall_active"]
            STATE["device_fall_active"] = fall
            STATE["last_seen"] = steady_now
            STATE["structured_seen"] = steady_now
            STATE["telemetry_updated_ms"] = int(now * 1000)
            STATE["uptime_ms"] = uptime
            STATE["mpu"] = mpu
            structured_fall_state = str(frame.get("fall_state", "")).upper()
            if re.fullmatch(r"[A-Z_]+", structured_fall_state):
                STATE["fall_state"] = structured_fall_state
            else:
                STATE["fall_state"] = "CONFIRMED" if fall else "IDLE"
            STATE["alarm"] = str(frame.get("alarm", "Idle"))[:32]
            for key in ("ax", "ay", "az", "gx", "gy", "gz", "accel", "gyro"):
                STATE[key] = number(frame[key])
            _set_structured_decision_locked(frame, fall)
            evidence = STATE["evidence"]
            reason = (
                f"Firmware confirmed fall using {evidence}"
                if evidence not in ("", "NONE")
                else "Firmware confirmed fall"
            )
            if device_delta:
                _latch_fall_locked(
                    now,
                    steady_now,
                    "FIRMWARE",
                    reason,
                    device_delta,
                )
                _set_structured_decision_locked(frame, fall)
            elif new_fall_flag:
                _latch_fall_locked(
                    now, steady_now, "FIRMWARE", reason
                )
                _set_structured_decision_locked(frame, fall)
            elif fall and STATE["fall_active"]:
                # Continue the minimum 15-second alarm window without adding
                # another incident for each repeated structured frame. After
                # acknowledgement, the same still-asserted device flag must
                # not recreate the incident until a new edge/counter arrives.
                STATE["fall_until"] = max(
                    STATE["fall_until"], steady_now + FALL_LATCH_SECONDS
                )
                STATE["incident_active"] = True
        return
    with LOCK:
        match = HB.search(line)
        if match:
            STATE["last_seen"] = steady_now
            STATE["telemetry_updated_ms"] = int(now * 1000)
            STATE["uptime_ms"] = int(match.group(1)) * 1000
            STATE["mpu"] = match.group(2).lower() == "true"
            STATE["accel"] = number(match.group(3))
            STATE["gyro"] = number(match.group(4))
            fall_state = FALL_STATE.search(line)
            network_mode = NETWORK_MODE.search(line)
            if fall_state:
                STATE["fall_state"] = fall_state.group(1).upper()
            if network_mode:
                STATE["network_mode"] = network_mode.group(1).upper()
            _set_firmware_decision_locked()
        match = IMU.search(line)
        if match:
            STATE["last_seen"] = steady_now
            STATE["telemetry_updated_ms"] = int(now * 1000)
            values = [number(v) for v in match.groups()]
            for key, value in zip(("ax","ay","az","gx","gy","gz"), values[:6]):
                STATE[key] = value
            STATE["accel"], STATE["gyro"] = values[6], values[7]
            STATE["mpu"] = True
            _set_firmware_decision_locked()
        match = UI.search(line)
        if match:
            values = [number(v) if v is not None else None for v in match.groups()]
            for key, value in zip(("ax","ay","az","gx","gy","gz"), values):
                if value is not None:
                    STATE[key] = value
            # Version 1.2+ collectors add valid=1 only when all acceleration
            # axes were found in Tinkered's real UI. Older collectors used
            # zeroes for missing labels, so those packets must not prove that
            # a patient device is online.
            if UI_VALID.search(line):
                STATE["last_seen"] = steady_now
                STATE["telemetry_updated_ms"] = int(now * 1000)
                STATE["mpu"] = True
                STATE["accel"] = math.sqrt(sum(value * value for value in values[:3]))
                gyro_available = all(value is not None for value in values[3:])
                if gyro_available:
                    STATE["gyro"] = math.sqrt(sum(value * value for value in values[3:]))
                else:
                    # Missing simulator gyro controls mean unavailable, not
                    # "reuse the last heartbeat/packet gyro value".
                    STATE["gx"] = STATE["gy"] = STATE["gz"] = 0.0
                    STATE["gyro"] = 0.0
                _process_tinkered_accel_locked(
                    values, gyro_available, now, steady_now
                )
        # Only accept complete runtime log lines. The Tinkered editor can show
        # these phrases inside Serial.print source code, which must never be
        # counted as a patient fall.
        is_fall = ('"' not in line and "Serial." not in line
                   and RUNTIME_FALL.search(line) is not None)
        if is_fall:
            STATE["last_seen"] = steady_now
            _latch_fall_locked(
                now, steady_now, "FIRMWARE", line[:180]
            )
        is_emergency = ('"' not in line and "Serial." not in line
                        and RUNTIME_EMERGENCY.search(line) is not None)
        if is_emergency and line not in SEEN:
            SEEN.add(line)
            event_time_ms = int(now * 1000)
            reason = line[:180]
            STATE["last_seen"] = steady_now
            STATE["telemetry_updated_ms"] = event_time_ms
            STATE["emergency_active"] = True
            STATE["incident_active"] = True
            STATE["incident_type"] = "EMERGENCY_BUTTON"
            STATE["incident_reason"] = reason
            STATE["incident_time_ms"] = event_time_ms
            STATE["alarm"] = "Emergency"
            STATE["events"].insert(0, {
                "type": "EMERGENCY_BUTTON",
                "time_ms": event_time_ms,
                "source": "FIRMWARE",
                "reason": reason,
            })
            STATE["events"] = STATE["events"][:8]

def snapshot():
    now = time.time()
    steady_now = time.monotonic()
    with LOCK:
        result = dict(STATE)
        result["events"] = list(STATE["events"])
    result["server_id"] = SERVER_ID
    result["connected"] = steady_now - result.pop("last_seen") < 3.0
    result["bridge_connected"] = steady_now - result.pop("bridge_seen") < 3.0
    result["fall_latch_active"] = steady_now < result.pop("fall_until")
    # A care-station incident stays visible until explicitly acknowledged via
    # /api/clear.  The separate 15-second latch reports the minimum alarm
    # window while preventing a missed alert when the dashboard is unattended.
    result["fall_detected"] = result.pop("fall_active")
    if result["emergency_active"]:
        result["alarm"] = "Emergency"
    elif result["fall_detected"]:
        result["alarm"] = "Fall"
        result["fall_state"] = "CONFIRMED"
    result.pop("fall_event_at")
    for key in (
        "device_fall_active",
        "structured_seen",
        "sim_arm_started",
        "sim_candidate_ax", "sim_candidate_ay", "sim_candidate_az",
        "sim_baseline_ax", "sim_baseline_ay", "sim_baseline_az",
        "sim_last_fall_steady",
    ):
        result.pop(key)
    result["updated_ms"] = int(now * 1000)
    result["telemetry_updated_ms"] = result.pop("telemetry_updated_ms")
    return result

class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def end_headers(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Allow-Private-Network", "true")
        self.send_header("Cache-Control", "no-store")
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(204)
        self.end_headers()

    def do_GET(self):
        if self.path == "/api/live":
            body = json.dumps(snapshot()).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        super().do_GET()

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        if length > 16384:
            self.send_error(413)
            return
        body = self.rfile.read(length).decode("utf-8", "replace")
        if self.path == "/ingest":
            for line in body.splitlines():
                ingest(line)
        elif self.path == "/api/acknowledge":
            with LOCK:
                _acknowledge_incident_locked()
        elif self.path == "/api/events/clear":
            with LOCK:
                cleared = _clear_event_history_locked()
            if not cleared:
                self.send_error(
                    409,
                    "Acknowledge the active incident before clearing history",
                )
                return
        elif self.path == "/api/clear":
            with LOCK:
                _clear_incidents_locked()
        else:
            self.send_error(404)
            return
        self.send_response(204)
        self.end_headers()

class ExclusiveThreadingHTTPServer(ThreadingHTTPServer):
    """Refuse a second dashboard server on the same Windows port."""
    allow_reuse_address = False

    def server_bind(self):
        if hasattr(socket, "SO_EXCLUSIVEADDRUSE"):
            self.socket.setsockopt(
                socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1
            )
        super().server_bind()

if __name__ == "__main__":
    server = ExclusiveThreadingHTTPServer(("127.0.0.1", 8765), Handler)
    print("FallGuard Tinkered bridge: http://127.0.0.1:8765/", flush=True)
    server.serve_forever()
