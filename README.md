# LihokSafe live Tinkered dashboard

This dashboard displays the actual MPU6050 values and fall state from the open Tinkered simulator. It does not generate demo sensor samples.

## Start the live monitor

From the project root, double-click `start-dashboard.cmd`, or run:

```powershell
.\start-dashboard.cmd
```

The bridge listens only on `http://127.0.0.1:8765` and opens the dashboard locally.

## Connect Tinkered

1. In Chrome, open `chrome://extensions` and enable **Developer mode**.
2. Choose **Load unpacked**, then select `dashboard/extension` from this project.
3. Open the Tinkered project and start its simulation.
4. Keep the Tinkered tab open. The extension forwards its real Serial Monitor and MPU6050 controls to the local bridge.
5. Open `http://127.0.0.1:8765/`, or use **Open CareGuard Monitor** inside Tinkered.

The dashboard connection checklist turns green in this order: local monitor, Tinkered bridge, then live telemetry.

## Fall decision

When gyroscope telemetry is available, a fall requires both an acceleration spike of at least `2.20 g` and rotational velocity of at least `220 deg/s` within the correlation window.

Tinkered may expose only its accelerometer controls while leaving the gyroscope at zero. In that case, the bridge uses a simulator-specific real-data fallback:

- hold a normal position close to `(0, 0, 1 g)` long enough to establish a baseline;
- an acceleration-vector spike of at least `2.20 g` plus a posture change of at least `35 deg` latches a fall alert.
- for quick simulator testing before a baseline is armed, a strong impact of at least `2.80 g` with at least `35 deg` posture change from the default upright vector also latches a fall alert.

The fallback still uses only values read from the Tinkered MPU6050 UI; it does not synthesize samples. Fall and emergency alerts remain visibly latched until **Acknowledge alert** is pressed. Acknowledgement resets the active alarm but preserves the incident record and session counters. **Clear history** is a separate action and is disabled while an alarm is active.

The ESP32 firmware also mirrors this simulator fallback so the piezo buzzer sounds when the same fall is detected in Tinkered. The buzzer is driven with a square-wave tone on GPIO14 through the 100 ohm resistor; a plain HIGH output is not enough for the passive piezo component.

## Dashboard functions

- Shows a persistent **Safe**, **Validating**, **Fall detected**, **Emergency SOS**, or **Offline** state.
- Displays live G-force, rotation/posture evidence, all six sensor axes, thresholds, and rolling charts.
- Separately counts confirmed falls and emergency-button presses for the current session.
- Opens an urgent alert dialog with captured evidence; the dialog can be dismissed while the alarm remains active.
- Preserves acknowledged events, exports them as CSV, and clears history only on explicit request.

## Verify

```powershell
node --test dashboard/tests/*.test.js
& "$env:USERPROFILE\.platformio\penv\Scripts\python.exe" -m unittest dashboard.tests.test_live_bridge
```

Edits to local firmware do not automatically replace the sketch already stored in the online Tinkered project. The bridge supports the current `[IMU]` serial format as well as structured `@@FG1` telemetry.

This dashboard is a project prototype, not a medical device.
