#include "detector.h"
#include <cassert>
#include <iostream>

static void steady(Detector& detector, unsigned from, unsigned through,
                   float ax = 1, float ay = 0, float az = 0) {
  for (unsigned time = from; time <= through; time += 20) {
    detector.update(time, 1, 5, ax, ay, az);
  }
}

int main() {
  Detector detector;
  steady(detector, 0, 1000);
  assert(detector.state == Detector::NORMAL);

  // Waist fall: qualified free-fall, impact, posture change, then 3 seconds
  // of stillness in the new posture.
  detector.update(1020, 0.3f, 40, 0.3f, 0, 0);
  assert(detector.state == Detector::FREE_FALL);
  detector.update(1140, 0.3f, 40, 0.3f, 0, 0);
  detector.update(1160, 2.8f, 170, 2.8f, 0, 0);
  assert(detector.state == Detector::POST_IMPACT);
  steady(detector, 1180, 4180, 0, 1, 0);
  assert(detector.state == Detector::TRIGGERED);
  assert(detector.peakAccelerationG >= 2.8f);

  detector.reset();
  steady(detector, 0, 1000);
  detector.update(1020, 0.3f, 40, 0.3f, 0, 0);
  detector.update(1140, 0.3f, 40, 0.3f, 0, 0);
  detector.update(1160, 2.8f, 170, 2.8f, 0, 0);
  steady(detector, 1180, 4180); // Flat landing: no orientation change.
  assert(detector.state == Detector::TRIGGERED);

  detector.reset();
  steady(detector, 0, 1000);
  detector.update(1020, 0.5f, 35, 0.5f, 0, 0);
  detector.update(1100, 0.5f, 35, 0.5f, 0, 0);
  detector.update(1120, 1.0f, 145, 1.0f, 0, 0);
  steady(detector, 1140, 4140, 0, 1, 0);
  assert(detector.state == Detector::TRIGGERED); // Softer fall with rotation.

  detector.reset();
  steady(detector, 0, 1000);
  detector.update(1020, 0.3f, 20, 0.3f, 0, 0);
  detector.update(1140, 0.3f, 20, 0.3f, 0, 0);
  detector.update(1160, 1, 5, 1, 0, 0);
  assert(detector.state == Detector::NORMAL); // No impact.

  detector.reset();
  steady(detector, 0, 1000);
  detector.update(1020, 3.1f, 130, 3.1f, 0, 0);
  steady(detector, 1040, 4040);
  assert(detector.state == Detector::NORMAL); // Bump, no orientation change.

  detector.reset();
  steady(detector, 0, 1000);
  detector.update(1020, 3.1f, 150, 3.1f, 0, 0);
  steady(detector, 1040, 4040, 0, 1, 0);
  assert(detector.state == Detector::TRIGGERED); // Impact-first fall.

  detector.reset();
  steady(detector, 0, 1000);
  detector.update(1020, 0.3f, 20, 0.3f, 0, 0);
  detector.update(1140, 0.3f, 20, 0.3f, 0, 0);
  detector.update(1160, 2.8f, 170, 2.8f, 0, 0);
  steady(detector, 1180, 2160, 0, 1, 0);
  detector.update(2180, 1.4f, 60, 0, 1.4f, 0); // Movement in the landed posture.
  steady(detector, 2200, 5200, 0, 1, 0);
  assert(detector.state == Detector::TRIGGERED); // Quiet time restarted.

  std::cout << "PASS: waist sequence, direct impact, bump rejection, sustained stillness\n";
}
