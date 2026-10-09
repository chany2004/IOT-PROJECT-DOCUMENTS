#include <Arduino.h>
#include <Wire.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <atomic>
#include <math.h>
#include "../camera_settings.h"
#include "../detector.h"
#include "cloud_http.h"
#include "wifi_provisioning.h"

// ESP32 I2C pins
#define SDA_PIN 21
#define SCL_PIN 22

// Breadboard indicators and emergency controls
#define NORMAL_LED_PIN 18
#define VERIFY_LED_PIN 19
#define ALARM_LED_PIN 23
#define BUZZER_PIN 25
#define BUZZER_PWM_CHANNEL 0
#define EMERGENCY_BUTTON_PIN 27

// MPU6050 I2C address
const uint8_t MPU_ADDR = 0x68;
// MPU6050 ACCEL_CONFIG=0x10 (±8g) and GYRO_CONFIG=0x08 (±500 deg/s).
// Match the datasheet scale factors to the configured ranges.
const float ACCEL_LSB_PER_G = 4096.0f;
const float GYRO_LSB_PER_DEG_S = 65.5f;

// Raw sensor values
int16_t AcX, AcY, AcZ, Tmp, GyX, GyY, GyZ;

// The same state machine is exercised by test_detector.cpp.
Detector fallDetector;
bool emergencyWasPressed = false;
bool alarmLatched = false;
bool buzzerActive = false;
bool buzzerPwmAttached = false;
bool buzzerPatternActive = false;
bool buzzerPatternPhaseOn = false;
unsigned long buzzerPhaseStartedAt = 0;
bool sensorReady = false;
bool mpuOnline = false;
bool i2cStarted = false;
bool buttonStablePressed = false;
int lastButtonRawState = HIGH;
uint8_t stableStartupSamples = 0;
uint8_t consecutiveMpuFailures = 0;
unsigned long lastMpuRecoveryAttempt = 0;
unsigned long lastButtonChangeTime = 0;
unsigned long lastSensorSampleTime = 0;
unsigned long lastSensorPrintTime = 0;
unsigned long eventSequence = 0;
uint32_t bootIdHigh = 0;
uint32_t bootIdLow = 0;
wl_status_t lastWifiStatus = WL_NO_SHIELD;
std::atomic<bool> heartbeatSensorOk{false};
std::atomic<bool> apiOnline{false};
const uint32_t BOOT_GUARD_MAGIC = 0x50574731;
RTC_DATA_ATTR uint32_t bootGuardMagic = 0;
RTC_DATA_ATTR uint32_t rapidBootGuard = 0;
bool wifiStartupAllowed = true;

const unsigned long BUTTON_DEBOUNCE_MS = 30;
const unsigned long SENSOR_SAMPLE_INTERVAL_MS = 10; // 100 Hz reduces missed short impacts.
const unsigned long SENSOR_PRINT_INTERVAL_MS = 250;
const uint8_t STARTUP_STABLE_SAMPLES = 25; // 0.5 s of healthy still readings.
const unsigned long BUTTON_SERVICE_DELAY_MS = 5;
const unsigned long WIFI_STARTUP_SETTLE_MS = 750;
const uint8_t BUZZER_DUTY = 64;
const unsigned long BUZZER_ON_MS = 300;
const unsigned long BUZZER_OFF_MS = 500;

bool apiSettingsConfigured() {
  String endpoint = FALL_EVENT_URL_VALUE;
  return (endpoint.startsWith("http://") || endpoint.startsWith("https://")) &&
         !endpoint.startsWith("http://127.0.0.1") &&
         !endpoint.startsWith("https://127.0.0.1") &&
         strlen(FALL_DEVICE_KEY_VALUE) >= 32 &&
         strcmp(FALL_DEVICE_KEY_VALUE, "NOT_CONFIGURED") != 0 &&
         strcmp(FALL_DEVICE_KEY_VALUE, "PASTE_THE_DEVICE_KEY_HERE") != 0;
}

String heartbeatUrl() {
  String endpoint = FALL_EVENT_URL_VALUE;
  if (!endpoint.endsWith("/events")) {
    return String();
  }

  endpoint.remove(endpoint.length() - 7);
  endpoint += "/heartbeat";
  return endpoint;
}

void heartbeatTask(void *) {
  String endpoint = heartbeatUrl();
  if (endpoint.length() == 0) {
    Serial.println("HEARTBEAT_API: invalid event URL; expected .../api/v1/events");
    vTaskDelete(nullptr);
    return;
  }

  unsigned long lastAttempt = 0;
  for (;;) {
    unsigned long now = millis();
    if (WiFi.status() == WL_CONNECTED &&
        (lastAttempt == 0 || now - lastAttempt >= 30000)) {
      lastAttempt = now;
      CloudHttpClient request;
      HTTPClient &http = request.http();

      const bool cloudEndpoint = endpoint.startsWith("https://");
      if (request.begin(endpoint, cloudEndpoint ? 5000 : 1500,
                        cloudEndpoint ? 7000 : 2500)) {
        http.addHeader("Content-Type", "application/json");
        http.addHeader("X-Device-Key", FALL_DEVICE_KEY_VALUE);
        const char *body = heartbeatSensorOk.load()
            ? R"json({"sensor_ok":true})json"
            : R"json({"sensor_ok":false})json";
        int status = http.POST((uint8_t *)body, strlen(body));
        apiOnline.store(status >= 200 && status < 300);
        Serial.printf("HEARTBEAT_API: status=%d sensor_ok=%s\n",
                      status, heartbeatSensorOk.load() ? "true" : "false");
        http.end();
      } else {
        apiOnline.store(false);
        Serial.println("HEARTBEAT_API: could not open endpoint");
      }
    }

    vTaskDelay(pdMS_TO_TICKS(1000));
  }
}

bool sendEvent(const char *eventType, float accelerationG = 0.0f,
               float gyroRadS = 0.0f) {
  if (!apiSettingsConfigured()) {
    Serial.println("EVENT_API: skipped; device URL/key is not configured");
    return false;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("EVENT_API: skipped; WiFi is offline");
    return false;
  }

  const unsigned long sequence = ++eventSequence;
  char eventId[96];
  snprintf(eventId, sizeof(eventId), "esp32-%08lx%08lx-%lu-%lu",
           (unsigned long)bootIdHigh, (unsigned long)bootIdLow,
           (unsigned long)millis(), sequence);

  char payload[384];
  if (strcmp(eventType, "possible_fall") == 0) {
    snprintf(payload, sizeof(payload),
             R"json({"event_id":"%s","event_type":"possible_fall","sensor":{"acceleration_g":%.4f,"gyro_rad_s":%.4f}})json",
             eventId, accelerationG, gyroRadS);
  } else {
    snprintf(payload, sizeof(payload),
             R"json({"event_id":"%s","event_type":"manual_emergency"})json",
             eventId);
  }

  CloudHttpClient request;
  HTTPClient &http = request.http();
  if (!request.begin(FALL_EVENT_URL_VALUE, 2500, 4500)) {
    Serial.println("EVENT_API: could not open endpoint");
    return false;
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Key", FALL_DEVICE_KEY_VALUE);
  int status = http.POST((uint8_t *)payload, strlen(payload));
  http.end();
  Serial.printf("EVENT_API: status=%d type=%s id=%s\n",
                status, eventType, eventId);
  return status >= 200 && status < 300;
}

void reportWifiState() {
  wl_status_t currentStatus = WiFi.status();
  if (currentStatus == lastWifiStatus) {
    return;
  }

  if (currentStatus == WL_CONNECTED) {
    Serial.print("WiFi connected. ESP32 IP: ");
    Serial.println(WiFi.localIP());
    Serial.println("WiFi ready; device online requires a successful API heartbeat.");
  } else if (lastWifiStatus == WL_CONNECTED) {
    apiOnline.store(false);
    Serial.printf("WiFi disconnected (status=%d); reconnecting automatically.\n",
                  (int)currentStatus);
  }
  lastWifiStatus = currentStatus;
}

void stopBuzzer() {
  buzzerPatternActive = false;
  buzzerPatternPhaseOn = false;
  if (buzzerPwmAttached) {
    ledcWriteTone(BUZZER_PWM_CHANNEL, 0);
    ledcWrite(BUZZER_PWM_CHANNEL, 0);
    ledcDetachPin(BUZZER_PIN);
    buzzerPwmAttached = false;
  }
  pinMode(BUZZER_PIN, INPUT);
  buzzerActive = false;
}

void startBuzzer(uint16_t frequency = 2000) {
  if (!buzzerActive) {
    pinMode(BUZZER_PIN, OUTPUT);
    ledcSetup(BUZZER_PWM_CHANNEL, 1000, 8);
    ledcAttachPin(BUZZER_PIN, BUZZER_PWM_CHANNEL);
    buzzerPwmAttached = true;
    ledcWriteTone(BUZZER_PWM_CHANNEL, frequency);
    ledcWrite(BUZZER_PWM_CHANNEL, BUZZER_DUTY);
    buzzerActive = true;
  }
}

void startBuzzerPattern() {
  if (buzzerPatternActive) return;

  buzzerPatternActive = true;
  buzzerPatternPhaseOn = true;
  buzzerPhaseStartedAt = millis();
  startBuzzer();
}

void serviceBuzzerPattern() {
  if (!buzzerPatternActive) return;

  const unsigned long now = millis();
  const unsigned long phaseDuration =
      buzzerPatternPhaseOn ? BUZZER_ON_MS : BUZZER_OFF_MS;
  if (now - buzzerPhaseStartedAt < phaseDuration) return;

  buzzerPatternPhaseOn = !buzzerPatternPhaseOn;
  buzzerPhaseStartedAt = now;
  if (buzzerPatternPhaseOn) {
    startBuzzer();
  } else {
    // Fully detach PWM during the pause so the buzzer is silent.
    if (buzzerPwmAttached) {
      ledcWriteTone(BUZZER_PWM_CHANNEL, 0);
      ledcWrite(BUZZER_PWM_CHANNEL, 0);
      ledcDetachPin(BUZZER_PIN);
      buzzerPwmAttached = false;
    }
    pinMode(BUZZER_PIN, INPUT);
    buzzerActive = false;
  }
}

void showNormalStatus() {
  const bool systemReady = sensorReady && mpuOnline
      && WiFi.status() == WL_CONNECTED && apiOnline.load();
  digitalWrite(NORMAL_LED_PIN, systemReady ? HIGH : LOW);
  digitalWrite(VERIFY_LED_PIN, systemReady ? LOW : HIGH);
  digitalWrite(ALARM_LED_PIN, LOW);
  stopBuzzer();
}

void showVerifyStatus() {
  digitalWrite(NORMAL_LED_PIN, LOW);
  digitalWrite(VERIFY_LED_PIN, HIGH);
  digitalWrite(ALARM_LED_PIN, LOW);
  stopBuzzer();
}

void showAlarmStatus() {
  digitalWrite(NORMAL_LED_PIN, LOW);
  digitalWrite(VERIFY_LED_PIN, LOW);
  digitalWrite(ALARM_LED_PIN, HIGH);
  startBuzzerPattern();
}

void setAllLeds(bool greenOn, bool yellowOn, bool redOn) {
  digitalWrite(NORMAL_LED_PIN, greenOn ? HIGH : LOW);
  digitalWrite(VERIFY_LED_PIN, yellowOn ? HIGH : LOW);
  digitalWrite(ALARM_LED_PIN, redOn ? HIGH : LOW);
}

void runLedSelfTest() {
  stopBuzzer();
  setAllLeds(false, false, false);

  Serial.println("LED self-test starting...");

  Serial.println("  GREEN LED ON  (GPIO18)");
  setAllLeds(true, false, false);
  delay(1000);

  Serial.println("  YELLOW LED ON (GPIO19)");
  setAllLeds(false, true, false);
  delay(1000);

  Serial.println("  RED LED ON    (GPIO23)");
  setAllLeds(false, false, true);
  delay(1000);

  setAllLeds(false, false, false);
  Serial.println("LED self-test complete.");
}

void runBuzzerSelfTest() {
  Serial.println("Buzzer self-test: listen for two beeps (GPIO25)");

  for (uint8_t beep = 0; beep < 2; beep++) {
    startBuzzer(2000);
    delay(350);
    stopBuzzer();
    delay(250);
  }

  Serial.println("Buzzer self-test complete.");
}

bool readEmergencyButton() {
  int rawState = digitalRead(EMERGENCY_BUTTON_PIN);

  if (rawState != lastButtonRawState) {
    lastButtonRawState = rawState;
    lastButtonChangeTime = millis();
  }

  if (millis() - lastButtonChangeTime >= BUTTON_DEBOUNCE_MS) {
    bool pressed = rawState == LOW;

    if (pressed != buttonStablePressed) {
      buttonStablePressed = pressed;
      Serial.print("Button GPIO27: ");
      Serial.println(pressed ? "PRESSED (LOW)" : "RELEASED (HIGH)");
    }
  }

  return buttonStablePressed;
}

void resetAlarm() {
  alarmLatched = false;
  fallDetector.reset();
  showNormalStatus();
  Serial.println("ALARM RESET: monitoring resumed");
}

void serviceSerialCommands() {
  while (Serial.available()) {
    char command = Serial.read();
    if (command == 'r' || command == 'R') {
      resetAlarm();
    } else if (command == 'w' || command == 'W') {
      requestWifiSetup();
    } else if (command == 'x' || command == 'X') {
      requestWifiForget();
    } else if (command == '?') {
      requestWifiStatus();
    }
  }
}

bool writeMPURegister(uint8_t reg, uint8_t value) {
  Wire.beginTransmission(MPU_ADDR);
  Wire.write(reg);
  Wire.write(value);
  return Wire.endTransmission(true) == 0;
}

bool readMPURegister(uint8_t reg, uint8_t &value) {
  Wire.beginTransmission(MPU_ADDR);
  Wire.write(reg);

  if (Wire.endTransmission(false) != 0) {
    return false;
  }

  if (Wire.requestFrom(MPU_ADDR, (uint8_t)1, (uint8_t)true) != 1) {
    return false;
  }

  value = Wire.read();
  return true;
}

bool initializeMPU() {
  if (i2cStarted) {
    Wire.end();
    delay(20);
  }

  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setClock(100000);
  Wire.setTimeOut(50);
  i2cStarted = true;
  delay(100);

  uint8_t whoAmI = 0;
  if (!readMPURegister(0x75, whoAmI)) {
    Serial.println("MPU6050 not responding at I2C address 0x68");
    return false;
  }

  Serial.print("MPU WHO_AM_I: 0x");
  Serial.println(whoAmI, HEX);

  if (whoAmI != 0x68) {
    Serial.println("Unexpected MPU identity; check the module type/address");
    return false;
  }

  // Reset, select a stable clock, then configure the sensor ranges.
  if (!writeMPURegister(0x6B, 0x80)) {
    return false;
  }
  delay(100);

  bool configured =
      writeMPURegister(0x6B, 0x01) &&
      writeMPURegister(0x6C, 0x00) &&
      writeMPURegister(0x19, 0x09) &&
      writeMPURegister(0x1A, 0x03) &&
      writeMPURegister(0x1C, 0x10) &&
      writeMPURegister(0x1B, 0x08);

  delay(100);
  uint8_t powerManagement = 0x40;
  bool awake = readMPURegister(0x6B, powerManagement) &&
               (powerManagement & 0x40) == 0;
  uint8_t accelConfig = 0, gyroConfig = 0;
  bool rangesConfirmed = readMPURegister(0x1C, accelConfig) &&
      readMPURegister(0x1B, gyroConfig) &&
      (accelConfig & 0x18) == 0x10 && (gyroConfig & 0x18) == 0x08;
  if (!rangesConfirmed) {
    Serial.println("MPU range configuration could not be verified");
  }
  return configured && awake && rangesConfirmed;
}

bool readMPU() {
  Wire.beginTransmission(MPU_ADDR);
  Wire.write(0x3B); // starting register for accel data
  byte error = Wire.endTransmission(false);

  if (error != 0) {
    Serial.print("MPU read error: ");
    Serial.println(error);
    return false;
  }

  uint8_t bytesRead = Wire.requestFrom(MPU_ADDR, (uint8_t)14, (uint8_t)true);

  if (bytesRead < 14) {
    Serial.println("MPU data incomplete");
    return false;
  }

  AcX = (Wire.read() << 8) | Wire.read();
  AcY = (Wire.read() << 8) | Wire.read();
  AcZ = (Wire.read() << 8) | Wire.read();
  Tmp = (Wire.read() << 8) | Wire.read();
  GyX = (Wire.read() << 8) | Wire.read();
  GyY = (Wire.read() << 8) | Wire.read();
  GyZ = (Wire.read() << 8) | Wire.read();

  bool allFrameZero =
      AcX == 0 && AcY == 0 && AcZ == 0 &&
      Tmp == 0 &&
      GyX == 0 && GyY == 0 && GyZ == 0;

  if (allFrameZero) {
    Serial.println("MPU data invalid: all-zero sample ignored");
    return false;
  }

  return true;
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  if (bootGuardMagic != BOOT_GUARD_MAGIC) {
    bootGuardMagic = BOOT_GUARD_MAGIC;
    rapidBootGuard = 0;
  }
  if (rapidBootGuard < 255) {
    rapidBootGuard++;
  }
  wifiStartupAllowed = rapidBootGuard <= 3;
  bootIdHigh = esp_random();
  bootIdLow = esp_random();

  pinMode(NORMAL_LED_PIN, OUTPUT);
  pinMode(VERIFY_LED_PIN, OUTPUT);
  pinMode(ALARM_LED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, INPUT);
  pinMode(EMERGENCY_BUTTON_PIN, INPUT_PULLUP);
  lastButtonRawState = digitalRead(EMERGENCY_BUTTON_PIN);
  buttonStablePressed = lastButtonRawState == LOW;
  lastButtonChangeTime = millis();

  Serial.println();
  Serial.println("Starting ESP32 + MPU6050 Fall Detection Test...");
  runLedSelfTest();
  // Avoid a buzzer current spike during boot. The alarm path still exercises
  // the buzzer when a real fall or button emergency is detected.
  stopBuzzer();
  Serial.print("Button GPIO27 startup state: ");
  Serial.println(buttonStablePressed ? "PRESSED (LOW)" : "RELEASED (HIGH)");
  Serial.println("Button test: press GPIO27-to-GND for alarm; press again to stop buzzer.");
  Serial.println("Serial: r=acknowledge alarm, w=Wi-Fi setup, x=forget Wi-Fi only, ?=Wi-Fi status.");
  showNormalStatus();

  mpuOnline = initializeMPU();

  if (mpuOnline) {
    Serial.println("MPU6050 connected!");
  } else {
    showVerifyStatus();
    Serial.println("Check wiring: VCC=3.3V, GND=GND, SDA=21, SCL=22");
  }

  Serial.println("Keep the sensor still while it stabilizes...");

  if (!wifiStartupAllowed) {
    setAllLeds(false, true, false);
    Serial.println("NETWORK: rapid restart guard; check power, then power-cycle the board.");
  } else {
    // Remove external loads and lower CPU current before radio calibration.
    setAllLeds(false, false, false);
    stopBuzzer();
    setCpuFrequencyMhz(80);
    delay(WIFI_STARTUP_SETTLE_MS);
    if (!startWifiProvisioning()) {
      Serial.println("WIFI_SETUP: task could not start; monitoring continues offline.");
    }

    if (apiSettingsConfigured()) {
      if (xTaskCreate(heartbeatTask, "monitor-heartbeat", 8192, nullptr, 1,
                      nullptr) != pdPASS) {
        Serial.println("HEARTBEAT_API: task could not start");
      }
    } else {
      Serial.println("NETWORK: FALL_EVENT_URL or FALL_DEVICE_KEY is not configured");
    }
  }
}

void loop() {
  if (rapidBootGuard != 0 && millis() >= 60000) {
    rapidBootGuard = 0;
  }
  serviceSerialCommands();
  reportWifiState();
  bool emergencyPressed = readEmergencyButton();

  if (emergencyPressed && !emergencyWasPressed) {
    emergencyWasPressed = true;

    if (alarmLatched) {
      // A second button press acknowledges the alarm locally.
      resetAlarm();
      Serial.println("ALARM ACKNOWLEDGED: buzzer stopped by button");
    } else {
      alarmLatched = true;
      Serial.println(">>> MANUAL EMERGENCY BUTTON PRESSED <<<");
      showAlarmStatus();
      sendEvent("manual_emergency");
    }
  }

  if (!emergencyPressed && emergencyWasPressed) {
    emergencyWasPressed = false;
    Serial.println("Manual emergency button released; press again to acknowledge the alarm");
  }

  if (alarmLatched) {
    showAlarmStatus();
    serviceBuzzerPattern();
    delay(BUTTON_SERVICE_DELAY_MS);
    return;
  }

  if (fallDetector.state == Detector::NORMAL) {
    showNormalStatus();
  }

  unsigned long now = millis();
  if (now - lastSensorSampleTime < SENSOR_SAMPLE_INTERVAL_MS) {
    delay(BUTTON_SERVICE_DELAY_MS);
    return;
  }
  lastSensorSampleTime = now;

  if (!mpuOnline) {
    heartbeatSensorOk.store(false);
    sensorReady = false;
    stableStartupSamples = 0;
    fallDetector.reset();
    showVerifyStatus();

    if (millis() - lastMpuRecoveryAttempt >= 1000) {
      lastMpuRecoveryAttempt = millis();
      Serial.println("Attempting MPU6050 recovery...");
      mpuOnline = initializeMPU();

      if (mpuOnline) {
        consecutiveMpuFailures = 0;
        Serial.println("MPU6050 communication restored.");
        Serial.println("Keep the sensor still while it stabilizes...");
      }
    }

    delay(BUTTON_SERVICE_DELAY_MS);
    return;
  }

  if (!readMPU()) {
    heartbeatSensorOk.store(false);
    consecutiveMpuFailures++;
    sensorReady = false;
    stableStartupSamples = 0;
    fallDetector.reset();
    showVerifyStatus();

    if (consecutiveMpuFailures >= 3) {
      mpuOnline = false;
      Serial.println("Three invalid MPU samples; scheduling I2C recovery.");
    }

    delay(BUTTON_SERVICE_DELAY_MS);
    return;
  }

  consecutiveMpuFailures = 0;

  // Convert raw values
  float ax = AcX / ACCEL_LSB_PER_G;
  float ay = AcY / ACCEL_LSB_PER_G;
  float az = AcZ / ACCEL_LSB_PER_G;

  float gx = GyX / GYRO_LSB_PER_DEG_S;
  float gy = GyY / GYRO_LSB_PER_DEG_S;
  float gz = GyZ / GYRO_LSB_PER_DEG_S;

  float accelMagnitude = sqrt((ax * ax) + (ay * ay) + (az * az));
  float gyroMagnitude = sqrt((gx * gx) + (gy * gy) + (gz * gz));
  // Printing every 20 ms would block the loop and hide the impact sample.
  if (now - lastSensorPrintTime >= SENSOR_PRINT_INTERVAL_MS) {
    lastSensorPrintTime = now;
    Serial.printf("Accel: %.2f g | Gyro: %.2f deg/s | AX:%.2f AY:%.2f AZ:%.2f\n",
                  accelMagnitude, gyroMagnitude, ax, ay, az);
  }

  // Ignore power-up transients until the sensor is steady for five samples.
  // This prevents an initial all-zero reading from becoming a false fall.
  if (!sensorReady) {
    bool nearOneG = accelMagnitude >= 0.75 && accelMagnitude <= 1.25;
    bool nearlyStill = gyroMagnitude < 45.0;

    if (nearOneG && nearlyStill) {
      stableStartupSamples++;
    } else {
      stableStartupSamples = 0;
    }

    if (stableStartupSamples < STARTUP_STABLE_SAMPLES) {
      delay(BUTTON_SERVICE_DELAY_MS);
      return;
    }

    sensorReady = true;
    heartbeatSensorOk.store(true);
    showNormalStatus();
    Serial.println("--------------------------------");
    Serial.println("Sensor stable. Fall detection started.");
    Serial.println("--------------------------------");
  }

  const Detector::State previousState = fallDetector.state;
  fallDetector.update(now, accelMagnitude, gyroMagnitude, ax, ay, az);
  if (fallDetector.state != previousState) {
    if (fallDetector.state == Detector::FREE_FALL) {
      showVerifyStatus();
      Serial.println(">>> Possible fall: low acceleration <<<");
    } else if (fallDetector.state == Detector::POST_IMPACT) {
      showVerifyStatus();
      Serial.println(">>> Impact detected; checking post-impact stillness <<<");
    } else if (fallDetector.state == Detector::NORMAL) {
      showNormalStatus();
      Serial.println("Fall candidate cancelled: incomplete sequence or no orientation change");
    }
  }
  if (fallDetector.state == Detector::TRIGGERED) {
    const float peakG = fallDetector.peakAccelerationG;
    const float peakGyroRadS = fallDetector.peakGyroDegS * DEG_TO_RAD;
    Serial.println(">>> FALLING PATIENT DETECTED <<<");
    Serial.printf("Peak acceleration: %.2f g | peak gyro: %.2f deg/s\n",
                  peakG, fallDetector.peakGyroDegS);
    alarmLatched = true;
    showAlarmStatus();
    sendEvent("possible_fall", peakG, peakGyroRadS);
    fallDetector.reset();
    Serial.println("Alarm latched. Press the physical button to acknowledge and stop the buzzer.");
  }

  delay(BUTTON_SERVICE_DELAY_MS);
}
