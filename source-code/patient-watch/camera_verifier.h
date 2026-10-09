#pragma once

#include <Arduino.h>

enum class CameraVerdict {
  CONFIRMED_FALL,
  NO_FALL,
  UNCERTAIN,
  ERROR
};

struct CameraResult {
  CameraVerdict verdict = CameraVerdict::ERROR;
  float confidence = 0.0f;
  int httpStatus = 0;
};

class CameraVerifier {
 public:
  bool begin();
  CameraResult verify();
  bool ready() const { return ready_; }

 private:
  bool ready_ = false;
};
