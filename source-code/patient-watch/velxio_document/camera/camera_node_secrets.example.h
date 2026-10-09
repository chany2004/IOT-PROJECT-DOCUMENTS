#pragma once

// Copy to camera_node_secrets.h on the camera build and keep it private.
#define CAMERA_WIFI_SSID "YOUR_2_4_GHZ_WIFI_NAME"
#define CAMERA_WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
// Use the same value entered as the ESP32-CAM camera key in Patient Watch.
#define CAMERA_NODE_KEY "PASTE_A_RANDOM_KEY_OF_AT_LEAST_32_CHARACTERS"
// Heartbeats can reach AWS through certificate-validated HTTPS. This alone does
// not let AWS pull private-LAN snapshots; keep direct upload disabled for now.
// Local-only alternative: http://YOUR_PC_LAN_IP:8085/api/v1/heartbeat
#define CAMERA_HEARTBEAT_URL "https://patientwatchcare.com/api/v1/heartbeat"
// One-time device key from registering this camera in Patient Watch.
// This is separate from CAMERA_NODE_KEY, which protects image downloads.
#define CAMERA_DEVICE_KEY "PASTE_THE_CAMERA_DEVICE_KEY_HERE"
// Direct upload is optional. Match platformio.ini's camera build flag and
// CAMERA_PUSH_ENABLED in the web app only after a successful stability trial.
