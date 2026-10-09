#pragma once

// Velxio browser project settings.
// This file intentionally contains test placeholders only.
#define FALL_CAMERA_HARDWARE 1

#if defined(ENABLE_ESP32_CAM) || FALL_CAMERA_HARDWARE
#define FALL_CAMERA_ACTIVE 1
#else
#define FALL_CAMERA_ACTIVE 0
#endif

#if FALL_CAMERA_HARDWARE && !defined(ENABLE_ESP32_CAM)
#define ENABLE_ESP32_CAM
#endif

// Replace these only with simulator/test credentials when your Velxio project
// provides Wi-Fi or a Local Network Gateway. Never paste private production
// credentials into a shared browser project.
#define FALL_WIFI_SSID "VELXIO_TEST_WIFI"
#define FALL_WIFI_PASSWORD "VELXIO_TEST_PASSWORD"
#define FALL_VERIFY_URL "http://host.velxio.internal:8000/verify-fall"
#define FALL_API_KEY "VELXIO_TEST_API_KEY"

static const char WIFI_SSID_VALUE[] = FALL_WIFI_SSID;
static const char WIFI_PASSWORD_VALUE[] = FALL_WIFI_PASSWORD;
static const char FALL_VERIFY_URL_VALUE[] = FALL_VERIFY_URL;
static const char FALL_API_KEY_VALUE[] = FALL_API_KEY;
