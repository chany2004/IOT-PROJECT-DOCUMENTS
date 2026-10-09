#pragma once

// Used only by the camera/simulator. Physical main sensor uses phone Wi-Fi setup
// (WiFiManager) and saved NVS credentials; it ignores these two values.
#define FALL_WIFI_SSID "YOUR_WIFI_NAME"
#define FALL_WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define FALL_VERIFY_URL "http://YOUR_PC_LAN_IP:8000/verify-fall"
#define FALL_API_KEY "PASTE_THE_SAME_KEY_FROM_DOT_ENV"
// The value shown once by the Laravel administrator when the ESP32 device is registered.
// AWS: verified HTTPS with the shared trust anchors and automatic network time.
// Local-only alternative: http://YOUR_PC_LAN_IP:8085/api/v1/events
#define FALL_EVENT_URL "https://patientwatchcare.com/api/v1/events"
#define FALL_DEVICE_KEY "PASTE_THE_DEVICE_KEY_HERE"
