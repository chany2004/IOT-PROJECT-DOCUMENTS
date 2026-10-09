#pragma once

// Keep credentials in the ignored camera_secrets.h file.
// Every firmware environment uses physical networking; there is no serial-only mode.

#if defined(ENABLE_ESP32_CAM)
#define FALL_CAMERA_ACTIVE 1
#else
#define FALL_CAMERA_ACTIVE 0
#endif

#if __has_include("camera_secrets.h")
#include "camera_secrets.h"
#else
#define FALL_WIFI_SSID "YOUR_WIFI_NAME"
#define FALL_WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define FALL_VERIFY_URL "http://127.0.0.1:8000/verify-fall"
#define FALL_EVENT_URL "http://127.0.0.1:8085/api/v1/events"
#define FALL_API_KEY "NOT_CONFIGURED"
#define FALL_DEVICE_KEY "NOT_CONFIGURED"
#endif

#ifndef FALL_EVENT_URL
#define FALL_EVENT_URL "http://127.0.0.1:8085/api/v1/events"
#endif
#ifndef FALL_DEVICE_KEY
#define FALL_DEVICE_KEY "NOT_CONFIGURED"
#endif

static const char WIFI_SSID_VALUE[] = FALL_WIFI_SSID;
static const char WIFI_PASSWORD_VALUE[] = FALL_WIFI_PASSWORD;
static const char FALL_VERIFY_URL_VALUE[] = FALL_VERIFY_URL;
static const char FALL_API_KEY_VALUE[] = FALL_API_KEY;
static const char FALL_EVENT_URL_VALUE[] = FALL_EVENT_URL;
static const char FALL_DEVICE_KEY_VALUE[] = FALL_DEVICE_KEY;
