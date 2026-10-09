#include "camera_verifier.h"
#include "camera_settings.h"

#if FALL_CAMERA_ACTIVE
#include <HTTPClient.h>
#include <WiFi.h>
#include "esp_camera.h"

namespace {
camera_config_t cameraConfig() {
  camera_config_t config = {};
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = 5;
  config.pin_d1 = 18;
  config.pin_d2 = 19;
  config.pin_d3 = 21;
  config.pin_d4 = 36;
  config.pin_d5 = 39;
  config.pin_d6 = 34;
  config.pin_d7 = 35;
  config.pin_xclk = 0;
  config.pin_pclk = 22;
  config.pin_vsync = 25;
  config.pin_href = 23;
  config.pin_sccb_sda = 26;
  config.pin_sccb_scl = 27;
  config.pin_pwdn = 32;
  config.pin_reset = -1;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size = psramFound() ? FRAMESIZE_VGA : FRAMESIZE_QVGA;
  config.jpeg_quality = psramFound() ? 12 : 16;
  config.fb_count = psramFound() ? 2 : 1;
  config.grab_mode = CAMERA_GRAB_LATEST;
  return config;
}

float jsonNumber(const String& body, const char* key) {
  int start = body.indexOf(key);
  if (start < 0) return 0.0f;
  start = body.indexOf(':', start);
  if (start < 0) return 0.0f;
  ++start;
  return body.substring(start).toFloat();
}
}  // namespace

bool CameraVerifier::begin() {
  if (strlen(FALL_VERIFY_URL_VALUE) == 0) return false;
  camera_config_t config = cameraConfig();
  ready_ = (esp_camera_init(&config) == ESP_OK);
  return ready_;
}

CameraResult CameraVerifier::verify() {
  CameraResult result;
  if (!ready_ || WiFi.status() != WL_CONNECTED) return result;
  camera_fb_t* frame = esp_camera_fb_get();
  if (!frame) return result;
  HTTPClient http;
  http.setConnectTimeout(2500);
  http.setTimeout(4500);
  http.begin(FALL_VERIFY_URL_VALUE);
  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-API-Key", FALL_API_KEY_VALUE);
  http.addHeader("X-Device-Id", "esp32cam-patient-monitor");
  int status = http.POST(frame->buf, frame->len);
  esp_camera_fb_return(frame);
  result.httpStatus = status;
  if (status == 200) {
    String body = http.getString();
    result.confidence = jsonNumber(body, "confidence");
    if (body.indexOf("no_fall") >= 0)
      result.verdict = CameraVerdict::NO_FALL;
    else if (body.indexOf("fall") >= 0)
      result.verdict = CameraVerdict::CONFIRMED_FALL;
    else
      result.verdict = CameraVerdict::UNCERTAIN;
  }
  http.end();
  return result;
}

#else

bool CameraVerifier::begin() {
  ready_ = true;
  return true;
}

CameraResult CameraVerifier::verify() {
  CameraResult result;
  result.verdict = CameraVerdict::UNCERTAIN;
  return result;
}

#endif
