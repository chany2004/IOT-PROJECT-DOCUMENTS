#pragma once

#include <Arduino.h>

#if !CAMERA_SIMULATION
#include <HTTPClient.h>
#include <WiFi.h>
#endif

enum class CameraVerdict { CONFIRMED_FALL, NO_FALL, UNCERTAIN, ERROR };

struct CameraResult {
  CameraVerdict verdict = CameraVerdict::ERROR;
  float confidence = 0.0f;
  int httpStatus = 0;
};

inline const char* cameraVerdictName(CameraVerdict verdict) {
  switch (verdict) {
    case CameraVerdict::CONFIRMED_FALL: return "confirmed_fall";
    case CameraVerdict::NO_FALL: return "no_fall";
    case CameraVerdict::UNCERTAIN: return "uncertain";
    default: return "error";
  }
}

// In Velxio this class is deterministic so the whole fall state machine can
// be exercised without a physical patient or an unimplemented OV2640 sensor.
// On hardware, compile with CAMERA_SIMULATION=0 and point the URL at the
// camera board or FastAPI gateway.
class CameraLink {
 public:
  explicit CameraLink(const char* url) : url_(url) {}

  bool begin() {
    ready_ = true;
    return ready_;
  }

  void request(uint32_t now, bool reject, bool timeout) {
    requested_ = true;
    requestedAt_ = now;
    reject_ = reject;
    timeout_ = timeout;
  }

  void cancel() { requested_ = false; }
  bool pending() const { return requested_; }

  bool poll(uint32_t now, CameraResult& result) {
    if (!requested_ || now - requestedAt_ < 1000) return false;
    requested_ = false;

#if CAMERA_SIMULATION
    if (timeout_) return false;
    result.httpStatus = 200;
    result.confidence = reject_ ? 0.97f : 0.99f;
    result.verdict = reject_ ? CameraVerdict::NO_FALL : CameraVerdict::CONFIRMED_FALL;
    return true;
#else
    if (WiFi.status() != WL_CONNECTED) return false;
    HTTPClient http;
    http.setConnectTimeout(2500);
    http.setTimeout(4500);
    if (!http.begin(url_)) return false;
    const int status = http.GET();
    result.httpStatus = status;
    if (status == 200) {
      const String body = http.getString();
      result.confidence = 0.0f;
      const int confidenceAt = body.indexOf("confidence");
      if (confidenceAt >= 0) result.confidence = body.substring(body.indexOf(':', confidenceAt) + 1).toFloat();
      if (body.indexOf("no_fall") >= 0) result.verdict = CameraVerdict::NO_FALL;
      else if (body.indexOf("fall") >= 0) result.verdict = CameraVerdict::CONFIRMED_FALL;
      else result.verdict = CameraVerdict::UNCERTAIN;
    }
    http.end();
    return status == 200;
#endif
  }

 private:
  const char* url_ = nullptr;
  bool ready_ = false;
  bool requested_ = false;
  bool reject_ = false;
  bool timeout_ = false;
  uint32_t requestedAt_ = 0;
};
