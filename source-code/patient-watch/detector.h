#pragma once
#include <stdint.h>
#include <math.h>

// Waist-mounted MPU6050 fall detector. Sample at 50 Hz and require the full
// motion sequence (free-fall -> impact -> posture change -> stillness) so a
// single bump or quick hand movement does not become an alert.
struct Detector {
  enum State { NORMAL, FREE_FALL, POST_IMPACT, TRIGGERED } state = NORMAL;
  uint32_t entered = 0, quietStart = 0;
  bool quiet = false, hadFreeFall = false, freeFallQualified = false,
       baselineValid = false;
  float baselineX = 0, baselineY = 0, baselineZ = 0;
  float peakAccelerationG = 0, peakGyroDegS = 0;

  // Tuned for a waist-mounted sensor: sensitive enough for a sudden fall,
  // while still requiring the later impact/stillness checks.
  static constexpr float FREE_FALL_MAX_G = 0.65f;
  static constexpr float FREE_FALL_RELEASE_G = 0.85f;
  static constexpr float IMPACT_MIN_G = 2.40f;
  // The MPU is configured for +/-8g, so this catches a hard impact while
  // still requiring the post-impact checks below.
  static constexpr float DIRECT_IMPACT_G = 2.40f;
  static constexpr float SOFT_IMPACT_MIN_GYRO_DEG_S = 120.0f;
  static constexpr float SETTLED_MIN_G = 0.80f;
  static constexpr float SETTLED_MAX_G = 1.20f;
  static constexpr float SETTLED_MAX_GYRO_DEG_S = 25.0f;
  static constexpr uint32_t MIN_FREE_FALL_MS = 80;
  static constexpr uint32_t MAX_IMPACT_WINDOW_MS = 1000;
  static constexpr uint32_t STILLNESS_REQUIRED_MS = 2500;
  static constexpr uint32_t POST_IMPACT_TIMEOUT_MS = 8000;

  void resetCandidate() {
    state = NORMAL;
    entered = quietStart = 0;
    quiet = hadFreeFall = freeFallQualified = false;
    peakAccelerationG = peakGyroDegS = 0;
  }

  void reset() {
    resetCandidate();
    baselineValid = false;
    baselineX = baselineY = baselineZ = 0;
  }

  bool orientationChanged(float ax, float ay, float az) const {
    if (!baselineValid) return false;
    const float reference = sqrtf(baselineX * baselineX + baselineY * baselineY + baselineZ * baselineZ);
    const float current = sqrtf(ax * ax + ay * ay + az * az);
    if (reference < 0.5f || current < 0.5f) return false;
    const float cosine = (baselineX * ax + baselineY * ay + baselineZ * az) / (reference * current);
    return cosine < 0.707f; // Approximately 45 degrees of orientation change.
  }

  void update(uint32_t now, float accelerationG, float gyroDegS,
              float ax, float ay, float az) {
    if (!isfinite(accelerationG) || !isfinite(gyroDegS) ||
        !isfinite(ax) || !isfinite(ay) || !isfinite(az)) {
      reset();
      return;
    }
    if (state == TRIGGERED) return;

    if (state == NORMAL) {
      if (accelerationG < FREE_FALL_MAX_G) {
        state = FREE_FALL;
        entered = now;
        hadFreeFall = true;
        freeFallQualified = false;
        peakAccelerationG = accelerationG;
        peakGyroDegS = gyroDegS;
        return;
      }
      // Some falls do not produce a sampled free-fall phase. Keep a strict
      // direct-impact path, then still require posture change and stillness.
      if (accelerationG >= DIRECT_IMPACT_G) {
        state = POST_IMPACT;
        entered = now;
        hadFreeFall = false;
        freeFallQualified = false;
        peakAccelerationG = accelerationG;
        peakGyroDegS = gyroDegS;
        quiet = false;
        return;
      }
      if (accelerationG >= 0.75f && accelerationG <= 1.25f && gyroDegS < 45.0f) {
        if (!baselineValid) {
          baselineX = ax; baselineY = ay; baselineZ = az;
          baselineValid = true;
        } else {
          baselineX = baselineX * 0.98f + ax * 0.02f;
          baselineY = baselineY * 0.98f + ay * 0.02f;
          baselineZ = baselineZ * 0.98f + az * 0.02f;
        }
      }
      return;
    }

    if (state == FREE_FALL) {
      const uint32_t freeFallElapsed = now - entered;
      if (freeFallElapsed > MAX_IMPACT_WINDOW_MS) {
        resetCandidate();
        return;
      }
      if (accelerationG > peakAccelerationG) peakAccelerationG = accelerationG;
      if (gyroDegS > peakGyroDegS) peakGyroDegS = gyroDegS;

      if (accelerationG < FREE_FALL_MAX_G) {
        if (freeFallElapsed >= MIN_FREE_FALL_MS) freeFallQualified = true;
        return;
      }

      // The low-acceleration phase must last long enough to be meaningful,
      // and the impact must arrive inside the same one-second fall window.
      const bool hardImpact = accelerationG >= IMPACT_MIN_G;
      const bool rotatingImpact = gyroDegS >= SOFT_IMPACT_MIN_GYRO_DEG_S;
      if (freeFallQualified && (hardImpact || rotatingImpact)) {
        state = POST_IMPACT;
        entered = now;
        quiet = false;
        return;
      }

      if (accelerationG < FREE_FALL_RELEASE_G) {
        return;
      }

      resetCandidate();
      return;
    }

    if (now - entered > POST_IMPACT_TIMEOUT_MS) {
      resetCandidate();
      return;
    }
    if (accelerationG > peakAccelerationG) peakAccelerationG = accelerationG;
    if (gyroDegS > peakGyroDegS) peakGyroDegS = gyroDegS;
    const bool settled = accelerationG >= SETTLED_MIN_G &&
                         accelerationG <= SETTLED_MAX_G &&
                         gyroDegS < SETTLED_MAX_GYRO_DEG_S;
    if (!settled) {
      quiet = false;
      return;
    }
    if (!quiet) {
      quiet = true;
      quietStart = now;
    }
    if (now - quietStart >= STILLNESS_REQUIRED_MS) {
      const bool postureChanged = orientationChanged(ax, ay, az);
      const bool qualifiedFreeFallImpact =
          hadFreeFall && peakAccelerationG >= IMPACT_MIN_G;
      const bool qualifiedSoftFall =
          hadFreeFall && peakGyroDegS >= SOFT_IMPACT_MIN_GYRO_DEG_S &&
          postureChanged;
      const bool directImpactWithPostureChange =
          !hadFreeFall && peakAccelerationG >= DIRECT_IMPACT_G && postureChanged;

      // A real free-fall followed by hard impact and sustained stillness is
      // enough even when the sensor lands flat. A softer fall may use the
      // rotation plus posture change path. Direct impacts still need posture
      // change to reject ordinary bumps.
      if (qualifiedFreeFallImpact || qualifiedSoftFall ||
          directImpactWithPostureChange) {
        state = TRIGGERED;
      } else {
        resetCandidate();
      }
    }
  }
};
