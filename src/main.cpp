#include <Arduino.h>
#include <Wire.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include "config.h"

struct ImuSample {
  float ax_g;
  float ay_g;
  float az_g;
  float gx_dps;
  float gy_dps;
  float gz_dps;
  float accelMag_g;
  float gyroMag_dps;
};

static bool mpuReady = false;
static float filteredAccelMag = 0.0f;
static float filteredGyroMag = 0.0f;
static bool filterInitialized = false;

static uint32_t lastSampleMs = 0;
static uint32_t lastWiFiTryMs = 0;
static uint32_t lastFallAlertMs = 0;
static uint32_t lastAccelSpikeMs = 0;
static uint32_t lastGyroSpikeMs = 0;
static uint32_t lastImuLogMs = 0;
static uint32_t simArmStartedMs = 0;
static uint32_t simLastFallMs = 0;

static bool btnPrev = HIGH;
static bool simArmed = false;
static bool buzzerToneActive = false;
static bool buzzerPinHigh = false;
static uint32_t lastBuzzerToggleUs = 0;
static float simCandidateAx = 0.0f;
static float simCandidateAy = 0.0f;
static float simCandidateAz = 0.0f;
static float simBaselineAx = 0.0f;
static float simBaselineAy = 0.0f;
static float simBaselineAz = 1.0f;

enum AlarmPattern : uint8_t {
  ALARM_PATTERN_NONE = 0,
  ALARM_PATTERN_FALL_DETECTED,
  ALARM_PATTERN_EMERGENCY_BUTTON
};

struct PatternStep {
  bool on;
  uint16_t durationMs;
};

static const PatternStep FALL_PATTERN_STEPS[] = {
  {true, 140}, {false, 110},
  {true, 140}, {false, 110},
  {true, 220}, {false, 450}
};

static const PatternStep EMERGENCY_PATTERN_STEPS[] = {
  {true, 350}, {false, 120},
  {true, 350}, {false, 120},
  {true, 350}, {false, 600}
};

static AlarmPattern activeAlarmPattern = ALARM_PATTERN_NONE;
static uint8_t currentPatternStepIndex = 0;
static uint32_t patternStepStartedMs = 0;

uint16_t activeBuzzerToneHz() {
  if (activeAlarmPattern == ALARM_PATTERN_EMERGENCY_BUTTON) {
    return BUZZER_EMERGENCY_TONE_HZ;
  }
  return BUZZER_FALL_TONE_HZ;
}

void setBuzzerOutput(bool on) {
  buzzerToneActive = on;
  buzzerPinHigh = on;
  lastBuzzerToggleUs = micros();
  digitalWrite(PIN_BUZZER, on ? HIGH : LOW);
}

void updateBuzzerTone() {
  if (!buzzerToneActive) return;

  uint16_t toneHz = activeBuzzerToneHz();
  if (toneHz == 0) {
    digitalWrite(PIN_BUZZER, LOW);
    return;
  }

  uint32_t halfPeriodUs = 500000UL / toneHz;
  uint32_t nowUs = micros();
  if (nowUs - lastBuzzerToggleUs >= halfPeriodUs) {
    lastBuzzerToggleUs = nowUs;
    buzzerPinHigh = !buzzerPinHigh;
    digitalWrite(PIN_BUZZER, buzzerPinHigh ? HIGH : LOW);
  }
}

int patternPriority(AlarmPattern p) {
  if (p == ALARM_PATTERN_EMERGENCY_BUTTON) return 2;
  if (p == ALARM_PATTERN_FALL_DETECTED) return 1;
  return 0;
}

void getPatternDefinition(AlarmPattern pattern, const PatternStep*& steps, size_t& count, const char*& patternName) {
  switch (pattern) {
    case ALARM_PATTERN_FALL_DETECTED:
      steps = FALL_PATTERN_STEPS;
      count = sizeof(FALL_PATTERN_STEPS) / sizeof(FALL_PATTERN_STEPS[0]);
      patternName = "FALL_DETECTED";
      return;
    case ALARM_PATTERN_EMERGENCY_BUTTON:
      steps = EMERGENCY_PATTERN_STEPS;
      count = sizeof(EMERGENCY_PATTERN_STEPS) / sizeof(EMERGENCY_PATTERN_STEPS[0]);
      patternName = "EMERGENCY_BUTTON";
      return;
    default:
      steps = nullptr;
      count = 0;
      patternName = "NONE";
      return;
  }
}

void startBuzzerPattern(AlarmPattern pattern, const char* eventName) {
  const PatternStep* steps = nullptr;
  size_t count = 0;
  const char* patternName = "NONE";
  getPatternDefinition(pattern, steps, count, patternName);
  if (steps == nullptr || count == 0) return;

  if (activeAlarmPattern != ALARM_PATTERN_NONE &&
      patternPriority(pattern) <= patternPriority(activeAlarmPattern)) {
    const PatternStep* currentSteps = nullptr;
    size_t currentCount = 0;
    const char* currentName = "NONE";
    getPatternDefinition(activeAlarmPattern, currentSteps, currentCount, currentName);
    Serial.printf("[BUZZER] Event=%s pattern=%s ignored (currently playing %s).\n",
                  eventName, patternName, currentName);
    return;
  }

  activeAlarmPattern = pattern;
  currentPatternStepIndex = 0;
  patternStepStartedMs = millis();
  setBuzzerOutput(steps[0].on);
  Serial.printf("[BUZZER] Event=%s pattern=%s started.\n", eventName, patternName);
}

void updateBuzzerPattern() {
  if (activeAlarmPattern == ALARM_PATTERN_NONE) return;
  updateBuzzerTone();

  const PatternStep* steps = nullptr;
  size_t count = 0;
  const char* patternName = "NONE";
  getPatternDefinition(activeAlarmPattern, steps, count, patternName);
  if (steps == nullptr || count == 0) {
    activeAlarmPattern = ALARM_PATTERN_NONE;
    setBuzzerOutput(false);
    return;
  }

  uint32_t now = millis();
  if (now - patternStepStartedMs >= steps[currentPatternStepIndex].durationMs) {
    currentPatternStepIndex++;
    patternStepStartedMs = now;

    if (currentPatternStepIndex >= count) {
      activeAlarmPattern = ALARM_PATTERN_NONE;
      setBuzzerOutput(false);
      Serial.printf("[BUZZER] Pattern %s finished.\n", patternName);
      return;
    }

    setBuzzerOutput(steps[currentPatternStepIndex].on);
  }
}

float vectorDistance(float ax1, float ay1, float az1, float ax2, float ay2, float az2) {
  float dx = ax1 - ax2;
  float dy = ay1 - ay2;
  float dz = az1 - az2;
  return sqrtf(dx * dx + dy * dy + dz * dz);
}

float vectorTiltDegrees(float ax1, float ay1, float az1, float ax2, float ay2, float az2) {
  float mag1 = sqrtf(ax1 * ax1 + ay1 * ay1 + az1 * az1);
  float mag2 = sqrtf(ax2 * ax2 + ay2 * ay2 + az2 * az2);
  if (mag1 < 0.001f || mag2 < 0.001f) return 0.0f;

  float dot = ax1 * ax2 + ay1 * ay2 + az1 * az2;
  float cosine = dot / (mag1 * mag2);
  cosine = constrain(cosine, -1.0f, 1.0f);
  return acosf(cosine) * 57.2957795f;
}

void resetSimulatorArm() {
  simArmed = false;
  simArmStartedMs = 0;
  simCandidateAx = 0.0f;
  simCandidateAy = 0.0f;
  simCandidateAz = 0.0f;
}

bool detectSimulatorAccelFall(const ImuSample& s, uint32_t now, float& tiltDeg) {
  if (s.gyroMag_dps > SIM_GYRO_ZERO_MAX_DPS) {
    resetSimulatorArm();
    tiltDeg = 0.0f;
    return false;
  }

  bool normal = s.accelMag_g >= SIM_NORMAL_MIN_G && s.accelMag_g <= SIM_NORMAL_MAX_G;
  bool cooldownElapsed = now - simLastFallMs >= FALL_COOLDOWN_MS;

  float referenceAx = simArmed ? simBaselineAx : 0.0f;
  float referenceAy = simArmed ? simBaselineAy : 0.0f;
  float referenceAz = simArmed ? simBaselineAz : 1.0f;
  tiltDeg = vectorTiltDegrees(referenceAx, referenceAy, referenceAz, s.ax_g, s.ay_g, s.az_g);

  if (!simArmed) {
    bool strongUnarmedImpact =
        s.accelMag_g >= SIM_UNARMED_IMPACT_G &&
        tiltDeg >= SIM_POSTURE_THRESHOLD_DEG &&
        cooldownElapsed;
    if (strongUnarmedImpact) {
      simLastFallMs = now;
      resetSimulatorArm();
      return true;
    }

    if (!normal || !cooldownElapsed) {
      simArmStartedMs = 0;
      return false;
    }

    float drift = vectorDistance(simCandidateAx, simCandidateAy, simCandidateAz, s.ax_g, s.ay_g, s.az_g);
    if (simArmStartedMs == 0 || drift > SIM_BASELINE_STABILITY_G) {
      simArmStartedMs = now;
      simCandidateAx = s.ax_g;
      simCandidateAy = s.ay_g;
      simCandidateAz = s.az_g;
      return false;
    }

    if (now - simArmStartedMs >= SIM_BASELINE_HOLD_MS) {
      simArmed = true;
      simBaselineAx = simCandidateAx;
      simBaselineAy = simCandidateAy;
      simBaselineAz = simCandidateAz;
    }
    return false;
  }

  if (normal && vectorDistance(simBaselineAx, simBaselineAy, simBaselineAz, s.ax_g, s.ay_g, s.az_g) > SIM_BASELINE_STABILITY_G) {
    resetSimulatorArm();
    simArmStartedMs = now;
    simCandidateAx = s.ax_g;
    simCandidateAy = s.ay_g;
    simCandidateAz = s.az_g;
    tiltDeg = 0.0f;
    return false;
  }

  if (s.accelMag_g >= ACCEL_SPIKE_THRESHOLD_G &&
      tiltDeg >= SIM_POSTURE_THRESHOLD_DEG &&
      cooldownElapsed) {
    simLastFallMs = now;
    resetSimulatorArm();
    return true;
  }

  return false;
}

bool writeRegister8(uint8_t reg, uint8_t value) {
  Wire.beginTransmission(MPU6050_ADDR);
  Wire.write(reg);
  Wire.write(value);
  return (Wire.endTransmission() == 0);
}

bool readRegisters(uint8_t startReg, uint8_t* buffer, size_t len) {
  Wire.beginTransmission(MPU6050_ADDR);
  Wire.write(startReg);
  if (Wire.endTransmission(false) != 0) {
    return false;
  }

  size_t received = Wire.requestFrom((int)MPU6050_ADDR, (int)len, (int)true);
  if (received != len) {
    return false;
  }

  for (size_t i = 0; i < len; i++) {
    buffer[i] = Wire.read();
  }
  return true;
}

bool initMPU6050() {
  delay(100);

  // Wake up device
  if (!writeRegister8(MPU6050_REG_PWR_MGMT_1, 0x00)) {
    return false;
  }
  delay(50);

  // DLPF config (44Hz accel / 42Hz gyro bandwidth approx)
  if (!writeRegister8(MPU6050_REG_CONFIG, 0x03)) {
    return false;
  }

  // Gyro full scale = ±250 dps (LSB sensitivity 131)
  if (!writeRegister8(MPU6050_REG_GYRO_CONFIG, 0x00)) {
    return false;
  }

  // Accel full scale = ±2g (LSB sensitivity 16384)
  if (!writeRegister8(MPU6050_REG_ACCEL_CONFIG, 0x00)) {
    return false;
  }

  return true;
}

bool readImuSample(ImuSample& s) {
  uint8_t raw[14];
  if (!readRegisters(MPU6050_REG_ACCEL_XOUT_H, raw, sizeof(raw))) {
    return false;
  }

  int16_t axRaw = (int16_t)((raw[0] << 8) | raw[1]);
  int16_t ayRaw = (int16_t)((raw[2] << 8) | raw[3]);
  int16_t azRaw = (int16_t)((raw[4] << 8) | raw[5]);
  int16_t gxRaw = (int16_t)((raw[8] << 8) | raw[9]);
  int16_t gyRaw = (int16_t)((raw[10] << 8) | raw[11]);
  int16_t gzRaw = (int16_t)((raw[12] << 8) | raw[13]);

  s.ax_g = (float)axRaw / 16384.0f;
  s.ay_g = (float)ayRaw / 16384.0f;
  s.az_g = (float)azRaw / 16384.0f;

  s.gx_dps = (float)gxRaw / 131.0f;
  s.gy_dps = (float)gyRaw / 131.0f;
  s.gz_dps = (float)gzRaw / 131.0f;

  s.accelMag_g = sqrtf(s.ax_g * s.ax_g + s.ay_g * s.ay_g + s.az_g * s.az_g);
  s.gyroMag_dps = sqrtf(s.gx_dps * s.gx_dps + s.gy_dps * s.gy_dps + s.gz_dps * s.gz_dps);

  return true;
}

void connectWiFiIfNeeded() {
  if (WiFi.status() == WL_CONNECTED) return;

  if (String(WIFI_SSID) == "YOUR_WIFI_SSID" || String(WIFI_PASSWORD) == "YOUR_WIFI_PASSWORD") {
    static bool warned = false;
    if (!warned) {
      Serial.println("[WiFi] Credentials not set. Skipping Wi-Fi connect in simulator.");
      warned = true;
    }
    return;
  }

  uint32_t now = millis();
  if (now - lastWiFiTryMs < WIFI_RETRY_INTERVAL_MS) return;
  lastWiFiTryMs = now;

  Serial.printf("[WiFi] Connecting to SSID: %s\n", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}

bool postAlert(const char* eventType, const ImuSample* sample) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[HTTP] Not connected to Wi-Fi, alert not sent.");
    return false;
  }

  HTTPClient http;
  http.begin(WEBHOOK_URL);
  http.setTimeout(1500);
  http.addHeader("Content-Type", "application/json");

  String payload = "{";
  payload += "\"event\":\"" + String(eventType) + "\"";
  payload += ",\"uptime_ms\":" + String(millis());
  payload += ",\"ip\":\"" + WiFi.localIP().toString() + "\"";
  payload += ",\"rssi\":" + String(WiFi.RSSI());

  if (sample != nullptr) {
    payload += ",\"accel_mag_g\":" + String(sample->accelMag_g, 3);
    payload += ",\"gyro_mag_dps\":" + String(sample->gyroMag_dps, 2);
    payload += ",\"ax_g\":" + String(sample->ax_g, 3);
    payload += ",\"ay_g\":" + String(sample->ay_g, 3);
    payload += ",\"az_g\":" + String(sample->az_g, 3);
    payload += ",\"gx_dps\":" + String(sample->gx_dps, 2);
    payload += ",\"gy_dps\":" + String(sample->gy_dps, 2);
    payload += ",\"gz_dps\":" + String(sample->gz_dps, 2);
  }
  payload += "}";

  int code = http.POST(payload);
  http.end();

  Serial.printf("[HTTP] POST %s -> code=%d\n", eventType, code);

  return (code > 0 && code < 400);
}

void handleButton() {
  bool btn = digitalRead(PIN_BUTTON); // INPUT_PULLUP => LOW when pressed
  if (btn == LOW && btnPrev == HIGH) {
    Serial.println("[BUTTON] Emergency button pressed.");
    startBuzzerPattern(ALARM_PATTERN_EMERGENCY_BUTTON, "EMERGENCY_BUTTON");
    postAlert("EMERGENCY_BUTTON", nullptr);
  }
  btnPrev = btn;
}

void processFallDetection(const ImuSample& s) {
  uint32_t now = millis();

  if (!filterInitialized) {
    filteredAccelMag = s.accelMag_g;
    filteredGyroMag = s.gyroMag_dps;
    filterInitialized = true;
  } else {
    filteredAccelMag = FILTER_ALPHA * s.accelMag_g + (1.0f - FILTER_ALPHA) * filteredAccelMag;
    filteredGyroMag  = FILTER_ALPHA * s.gyroMag_dps + (1.0f - FILTER_ALPHA) * filteredGyroMag;
  }

  bool accelSpike = filteredAccelMag >= ACCEL_SPIKE_THRESHOLD_G;
  bool gyroSpike  = filteredGyroMag >= GYRO_SPIKE_THRESHOLD_DPS;

  if (accelSpike) lastAccelSpikeMs = now;
  if (gyroSpike)  lastGyroSpikeMs = now;

  bool spikesWithinWindow =
      (now - lastAccelSpikeMs <= FALL_WINDOW_MS) &&
      (now - lastGyroSpikeMs <= FALL_WINDOW_MS);

  bool cooldownElapsed = (now - lastFallAlertMs >= FALL_COOLDOWN_MS);
  float simulatorTiltDeg = 0.0f;
  bool simulatorAccelFall = detectSimulatorAccelFall(s, now, simulatorTiltDeg);

  if (now - lastImuLogMs >= 300) {
    lastImuLogMs = now;
    Serial.printf(
        "[IMU] a[g]=%.3f %.3f %.3f | g[dps]=%.1f %.1f %.1f | magA=%.3f (f=%.3f) magG=%.1f (f=%.1f) | spikes A:%d G:%d | sim armed:%d tilt:%.1f\n",
        s.ax_g, s.ay_g, s.az_g, s.gx_dps, s.gy_dps, s.gz_dps,
        s.accelMag_g, filteredAccelMag, s.gyroMag_dps, filteredGyroMag,
        accelSpike ? 1 : 0, gyroSpike ? 1 : 0, simArmed ? 1 : 0, simulatorTiltDeg
    );
  }

  if ((spikesWithinWindow && cooldownElapsed) || simulatorAccelFall) {
    if (simulatorAccelFall) {
      Serial.printf("[FALL] Fall signature detected: impact %.2f g and posture change %.1f deg.\n",
                    s.accelMag_g, simulatorTiltDeg);
    } else {
      Serial.println("[FALL] Fall signature detected: impact and rotation threshold met.");
    }
    startBuzzerPattern(ALARM_PATTERN_FALL_DETECTED, "FALL_DETECTED");
    lastFallAlertMs = now;
    postAlert("FALL_DETECTED", &s);
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n[BOOT] Patient-end fall detector starting...");

  pinMode(PIN_BUTTON, INPUT_PULLUP);
  pinMode(PIN_BUZZER, OUTPUT);
  setBuzzerOutput(false);

  Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
  Wire.setClock(400000);

  mpuReady = initMPU6050();
  if (mpuReady) {
    Serial.println("[MPU6050] Initialized successfully at 0x68.");
  } else {
    Serial.println("[MPU6050] Initialization failed. Check wiring/power/I2C.");
  }

  connectWiFiIfNeeded();
}

void loop() {
  connectWiFiIfNeeded();

  if (WiFi.status() == WL_CONNECTED) {
    static bool wasConnected = false;
    if (!wasConnected) {
      Serial.printf("[WiFi] Connected. IP: %s RSSI: %d dBm\n",
                    WiFi.localIP().toString().c_str(), WiFi.RSSI());
      wasConnected = true;
    }
  } else {
    static bool wasConnected = true;
    if (wasConnected) {
      Serial.println("[WiFi] Disconnected.");
      wasConnected = false;
    }
  }

  handleButton();
  updateBuzzerPattern();

  uint32_t now = millis();
  if (mpuReady && (now - lastSampleMs >= SAMPLE_PERIOD_MS)) {
    lastSampleMs = now;
    ImuSample sample;
    if (readImuSample(sample)) {
      processFallDetection(sample);
    } else {
      Serial.println("[MPU6050] Read error.");
    }
  }
}
