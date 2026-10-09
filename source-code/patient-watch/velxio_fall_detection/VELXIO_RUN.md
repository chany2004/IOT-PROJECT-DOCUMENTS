# Velxio fall-detection package

This folder is for the Velxio ESP32-CAM Webcam Demo. It uses the current
`env:esp32cam` pin map and is intentionally free of your private
`camera_secrets.h` and `.env` files.

## Run it

1. Open the Velxio ESP32-CAM Webcam Demo:
   https://velxio.dev/example/esp32cam-webcam-demo
2. Start the simulation and allow webcam access when Velxio asks.
3. Add/replace these five files in the Velxio project:
   `sketch.ino`, `detector.h`, `camera_verifier.h`, `camera_verifier.cpp`, and `camera_settings.h`.
4. Run/compile. Open Serial at 115200 baud.
5. For a repeatable demonstration, send `f` in Serial. Send `r` to reset.

The OV2640 camera capture can run in the browser demo. A full JPEG POST to the
fall-analysis backend needs the Velxio Local Network Gateway; otherwise the
camera request will time out and the firmware will use its fail-safe alarm.

## Current one-board wiring

- MPU6050: VCC -> 3V3, GND -> GND, SDA -> GPIO13, SCL -> GPIO14, AD0 -> GND.
- Emergency button: GPIO12 -> normally-open switch -> GND.
- External green LED: GPIO2 -> 220 ohm resistor -> LED anode; LED cathode -> GND.
- Yellow indicator: onboard GPIO4 flash/status LED; no external wire.
- Red alarm: onboard GPIO33 LED; no external wire.
- Active buzzer: GPIO15 -> 1k -> NPN base; NPN emitter -> GND; buzzer from +5V to NPN collector; diode stripe to +5V.
- Camera ribbon: keep the OV2640 seated in its camera socket.

Use only test credentials in this browser project. Do not upload the private
workspace `camera_secrets.h` or `.env`.
