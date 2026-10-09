#pragma once

#include <Arduino.h>
#include <math.h>

// Thresholds from SEP 7, Section 3.3 / proposed detector state machine.
struct Detector {
  enum State { NORMAL, FREE_FALL, IMPACT, POST_IMPACT, WAITING_CAMERA } state = NORMAL;
  uint32_t entered = 0;
  uint32_t quietStart = 0;
  bool quiet = false;

  void reset() {
    state = NORMAL;
    entered = 0;
    quietStart = 0;
    quiet = false;
  }

  void go(State next, uint32_t now) {
    state = next;
    entered = now;
    quietStart = 0;
    quiet = false;
  }

  void update(uint32_t now, float accelerationG, float gyroRad) {
    if (!isfinite(accelerationG) || !isfinite(gyroRad)) {
      reset();
      return;
    }
    switch (state) {
      case NORMAL:
        if (accelerationG < 0.55f) go(FREE_FALL, now);
        break;
      case FREE_FALL:
        if (now - entered > 1500) reset();
        else if (accelerationG > 2.40f) go(IMPACT, now);
        break;
      case IMPACT:
        if (now - entered >= 50) go(POST_IMPACT, now);
        break;
      case POST_IMPACT:
        if (now - entered > 5000) {
          reset();
          break;
        }
        if (gyroRad < 0.70f && accelerationG >= 0.75f && accelerationG <= 1.25f) {
          if (!quiet) {
            quiet = true;
            quietStart = now;
          }
          if (now - quietStart >= 1000) go(WAITING_CAMERA, now);
        } else {
          quiet = false;
        }
        break;
      case WAITING_CAMERA:
        break;
    }
  }

  const char* name() const {
    switch (state) {
      case NORMAL: return "NORMAL";
      case FREE_FALL: return "FREE_FALL";
      case IMPACT: return "IMPACT";
      case POST_IMPACT: return "POST_IMPACT";
      case WAITING_CAMERA: return "WAITING_CAMERA";
    }
    return "UNKNOWN";
  }
};
