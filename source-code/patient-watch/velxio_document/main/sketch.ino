// Fall Detection - MAIN CONTROLLER (ESP32 DevKit V1)
//
// This is the controller described in SEP 7: the MPU6050 and emergency
// button are connected to this board.  The ESP32-CAM is a separate board in
// the Velxio project and is reached through CameraLink (Wi-Fi on real
// hardware; deterministic simulation in Velxio).

// Keep this enabled in Velxio. Set to 0 for a physical build and set
// CAMERA_VERIFY_URL to the address of the ESP32-CAM /verify-fall endpoint.
#define CAMERA_SIMULATION 1
#ifndef CAMERA_VERIFY_URL
#define CAMERA_VERIFY_URL "http://192.168.1.50/verify-fall"
#endif

#include <Arduino.h>
#include <Wire.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <WiFi.h>

#include "detector.h"
#include "camera_link.h"

// SEP 7 wiring for the main ESP32 DevKit V1.
constexpr int EMERGENCY_PIN = 27;  // button -> GND, INPUT_PULLUP
constexpr int GREEN_PIN = 18;
constexpr int YELLOW_PIN = 19;
constexpr int RED_PIN = 23;
constexpr int BUZZER_PIN = 25;
constexpr int SDA_PIN = 21;        // MPU6050 SDA
constexpr int SCL_PIN = 22;        // MPU6050 SCL

Adafruit_MPU6050 mpu;
Detector detector;
CameraLink camera(CAMERA_VERIFY_URL);

bool sensorOK = false;
bool cameraRequested = false;
bool alarmOn = false;
bool demoMode = false;
char demoKind = 'f';
uint32_t demoStart = 0;
uint32_t lastSample = 0;
uint32_t lastButtonChange = 0;
uint32_t eventId = 0;
int rawButton = HIGH;
int stableButton = HIGH;

void buzzer(bool on) {
  if (on) tone(BUZZER_PIN, 1800);
  else noTone(BUZZER_PIN);
}

void alarmEvent(const char* type, const CameraResult* cameraResult = nullptr) {
  if (alarmOn) return;
  alarmOn = true;
  demoMode = false;
  buzzer(true);

  const char* verdict = "not_requested";
  float confidence = 0.0f;
  if (cameraResult != nullptr) {
    verdict = cameraVerdictName(cameraResult->verdict);
    confidence = cameraResult->confidence;
  }

  // This JSON is the simulator-safe event record.  A physical deployment can
  // POST the same fields to the FastAPI service after Wi-Fi is configured.
  Serial.printf(
      "{\"event_id\":%lu,\"event_type\":\"%s\","
      "\"camera_verdict\":\"%s\",\"camera_confidence\":%.2f,"
      "\"is_simulated\":%s,\"architecture\":\"two_board\","
      "\"delivery\":\"serial_simulation\",\"uptime_ms\":%lu}\n",
      (unsigned long)++eventId, type, verdict, confidence,
      CAMERA_SIMULATION ? "true" : "false", (unsigned long)millis());
}

void resetSystem() {
  detector.reset();
  camera.cancel();
  cameraRequested = false;
  alarmOn = false;
  demoMode = false;
  demoKind = 'f';
  buzzer(false);
  Serial.println("RESET: monitoring resumed");
}

void startDemo(char kind) {
  resetSystem();
  demoMode = true;
  demoKind = kind;
  demoStart = millis();
  Serial.println("DEMO: synthetic motion injected (not MPU6050 measurements)");
}

void printHelp() {
  Serial.println("Commands: f=fall+camera confirm, v=fall+camera reject, t=fall+timeout");
  Serial.println("          e=manual emergency, r=reset, n=normal, c=force camera confirm");
}

void handleSerial() {
  while (Serial.available() > 0) {
    const char c = (char)Serial.read();
    if (c == 'f' || c == 'v' || c == 't') startDemo(c);
    else if (c == 'e') alarmEvent("manual_emergency");
    else if (c == 'r') resetSystem();
    else if (c == 'n') {
      resetSystem();
      Serial.println("NORMAL: stationary sample => no alarm");
    } else if (c == 'c' && detector.state == Detector::WAITING_CAMERA) {
      CameraResult result;
      result.verdict = CameraVerdict::CONFIRMED_FALL;
      result.confidence = 0.99f;
      result.httpStatus = 200;
      alarmEvent("fall_camera_confirmed", &result);
    } else if (c == '?') printHelp();
  }
}

void setup() {
  Serial.begin(115200);
  delay(150);
  pinMode(EMERGENCY_PIN, INPUT_PULLUP);
  pinMode(GREEN_PIN, OUTPUT);
  pinMode(YELLOW_PIN, OUTPUT);
  pinMode(RED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  buzzer(false);

  // SEP 7: SDA=GPIO21, SCL=GPIO22 on the main ESP32.
  Wire.begin(SDA_PIN, SCL_PIN);
  sensorOK = mpu.begin();
  if (sensorOK) {
    mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
    mpu.setGyroRange(MPU6050_RANGE_500_DEG);
    mpu.setFilterBandwidth(MPU6050_BAND_21_HZ);
  }

  camera.begin();
  WiFi.mode(WIFI_STA);
  WiFi.begin("Velxio-GUEST", "");

  Serial.println();
  Serial.println("FALL DETECTION - SEP 7 TWO-BOARD ARCHITECTURE");
  Serial.println("MAIN: ESP32 DevKit V1 | CAMERA: separate ESP32-CAM + OV2640");
  Serial.println(sensorOK ? "MPU6050 ready (GPIO21/22)." :
                            "MPU6050 not detected; demo and emergency input remain available.");
  Serial.println(CAMERA_SIMULATION ?
                 "Velxio camera response: deterministic simulation (no patient image)." :
                 "Physical camera link enabled.");
  printHelp();
}

void sampleMotion(uint32_t now, float& accelerationG, float& gyroRad, bool& valid) {
  valid = false;
  accelerationG = 1.0f;
  gyroRad = 0.0f;

  if (demoMode) {
    const uint32_t elapsed = now - demoStart;
    // Free-fall (<0.55 g), impact (>2.40 g), then quiet post-impact period.
    accelerationG = elapsed < 500 ? 1.0f :
                   elapsed < 700 ? 0.20f :
                   elapsed < 800 ? 3.00f : 1.00f;
    gyroRad = (elapsed >= 700 && elapsed < 800) ? 1.50f : 0.0f;
    valid = true;
    return;
  }

  if (!sensorOK) return;
  sensors_event_t acc, gyro, temp;
  mpu.getEvent(&acc, &gyro, &temp);
  accelerationG = sqrtf(acc.acceleration.x * acc.acceleration.x +
                        acc.acceleration.y * acc.acceleration.y +
                        acc.acceleration.z * acc.acceleration.z) / 9.80665f;
  gyroRad = sqrtf(gyro.gyro.x * gyro.gyro.x +
                  gyro.gyro.y * gyro.gyro.y +
                  gyro.gyro.z * gyro.gyro.z);
  valid = true;
}

void serviceCamera(uint32_t now) {
  if (detector.state != Detector::WAITING_CAMERA || alarmOn) return;
  if (!cameraRequested) {
    camera.request(now, demoKind == 'v', demoKind == 't');
    cameraRequested = true;
  }

  CameraResult result;
  if (camera.poll(now, result)) {
    Serial.printf("CAMERA: verdict=%s confidence=%.2f http=%d\n",
                  cameraVerdictName(result.verdict), result.confidence,
                  result.httpStatus);
    if (result.verdict == CameraVerdict::CONFIRMED_FALL) {
      alarmEvent("fall_camera_confirmed", &result);
    } else if (result.verdict == CameraVerdict::NO_FALL) {
      detector.reset();
      cameraRequested = false;
      demoMode = false;
      Serial.println("CAMERA: no fall; monitoring reset");
    }
  }

  // SEP 7 fail-safe: if camera verification does not answer within 5 s,
  // raise an alert rather than silently discarding the possible fall.
  if (!alarmOn && now - detector.entered >= 5000) {
    alarmEvent("possible_fall_camera_timeout");
  }
}

void loop() {
  const uint32_t now = millis();

  const int button = digitalRead(EMERGENCY_PIN);
  if (button != rawButton) {
    rawButton = button;
    lastButtonChange = now;
  }
  if (now - lastButtonChange >= 35 && stableButton != rawButton) {
    stableButton = rawButton;
    if (stableButton == LOW) alarmEvent("manual_emergency");
  }

  handleSerial();

  if (!alarmOn && now - lastSample >= 20) {
    lastSample = now;
    float accelerationG, gyroRad;
    bool valid;
    sampleMotion(now, accelerationG, gyroRad, valid);
    if (valid) {
      const Detector::State oldState = detector.state;
      detector.update(now, accelerationG, gyroRad);
      if (oldState != detector.state) {
        Serial.printf("STATE: %s | accel=%.2fg gyro=%.2frad/s\n",
                      detector.name(), accelerationG, gyroRad);
      }
    }
  }

  serviceCamera(now);

  const bool normal = detector.state == Detector::NORMAL;
  digitalWrite(GREEN_PIN, !alarmOn && normal && sensorOK);
  digitalWrite(YELLOW_PIN, !alarmOn && (!sensorOK || !normal));
  digitalWrite(RED_PIN, alarmOn);

  static bool wifiReported = false;
  if (!wifiReported && WiFi.status() == WL_CONNECTED) {
    wifiReported = true;
    Serial.printf("Wi-Fi ready: %s\n", WiFi.localIP().toString().c_str());
  }
}
