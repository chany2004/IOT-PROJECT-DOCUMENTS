#include <Wire.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <WiFi.h>
#include "camera_settings.h"
#include <HTTPClient.h>
#include <atomic>
#include "detector.h"
#include "camera_verifier.h"

Adafruit_MPU6050 mpu;
Detector detector;
CameraVerifier camera;
#if defined(ENABLE_ESP32_CAM)
const int EMERGENCY=12, GREEN=2, YELLOW=4, RED=33, BUZZER=15, SDA_PIN=13, SCL_PIN=14;
#else
const int EMERGENCY=27, GREEN=18, YELLOW=19, RED=23, BUZZER=25, SDA_PIN=21, SCL_PIN=22;
#endif
std::atomic<bool> sensorOK{false};
bool cameraOK=false, alarmOn=false;
bool cameraRequested=false, eventRequested=false;
uint32_t sampled=0, buttonChanged=0, count=0, lastSensorProbe=0;
float lastAcceleration=1.0f, lastGyro=0.0f;
bool eventSubmitted=false;
uint32_t bootIdHigh=0, bootIdLow=0;
int rawButton=HIGH, stableButton=HIGH;

// Network heartbeat runs separately so it cannot pause the MPU6050 sampling loop.
void heartbeatTask(void*) {
  String url = FALL_EVENT_URL_VALUE;
  if (!url.endsWith("/events")) { vTaskDelete(nullptr); return; }
  url.remove(url.length() - 7);
  url += "/heartbeat";
  for (;;) {
    if (WiFi.status() == WL_CONNECTED && strlen(FALL_DEVICE_KEY_VALUE) >= 32) {
      HTTPClient http;
      http.setConnectTimeout(1500);
      http.setTimeout(2500);
      if (http.begin(url)) {
        http.addHeader("Content-Type", "application/json");
        http.addHeader("X-Device-Key", FALL_DEVICE_KEY_VALUE);
        const int status = http.POST(sensorOK ? "{\"sensor_ok\":true}" : "{\"sensor_ok\":false}");
        Serial.printf("HEARTBEAT_API: status=%d\n", status);
        http.end();
      }
    }
    vTaskDelay(pdMS_TO_TICKS(30000));
  }
}
void sendEvent(const char* type) {
  if (WiFi.status() != WL_CONNECTED || strlen(FALL_EVENT_URL_VALUE) < 8 ||
      strlen(FALL_DEVICE_KEY_VALUE) < 32) return;
  HTTPClient http;
  http.setConnectTimeout(2500);
  http.setTimeout(4500);
  if (!http.begin(FALL_EVENT_URL_VALUE)) return;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Key", FALL_DEVICE_KEY_VALUE);
  char payload[420];
  const bool needsSensor = strcmp(type, "manual_emergency") != 0;
  if (needsSensor) {
    snprintf(payload, sizeof(payload),
      "{\"event_id\":\"esp32-%08lx%08lx-%lu-%lu\",\"event_type\":\"possible_fall\","
      "\"sensor\":{\"acceleration_g\":%.4f,\"gyro_rad_s\":%.4f}}",
      (unsigned long)bootIdHigh, (unsigned long)bootIdLow,
      (unsigned long)millis(), (unsigned long)count, lastAcceleration, lastGyro);
  } else {
    snprintf(payload, sizeof(payload),
      "{\"event_id\":\"esp32-%08lx%08lx-%lu-%lu\",\"event_type\":\"manual_emergency\"}",
      (unsigned long)bootIdHigh, (unsigned long)bootIdLow,
      (unsigned long)millis(), (unsigned long)count);
  }
  const int status = http.POST((uint8_t*)payload, strlen(payload));
  Serial.printf("EVENT_API: status=%d type=%s\n", status, type);
  if (status >= 200 && status < 300) eventSubmitted=true;
  http.end();
}

void alarmEvent(const char* type) {
  if (alarmOn) return;
  alarmOn=true;
#if defined(ENABLE_ESP32_CAM)
  digitalWrite(BUZZER,HIGH);
#else
  tone(BUZZER,1500);
#endif
  // Sound the local alarm before making any network request.
  if (strcmp(type, "manual_emergency") == 0 || !eventSubmitted) sendEvent(type);
  const char* delivery = eventSubmitted ? "laravel_api" : "laravel_api_failed";
  Serial.printf("{\"event_id\":%lu,\"event_type\":\"%s\",\"delivery\":\"%s\",\"uptime_ms\":%lu}\n",
    (unsigned long)++count,type,delivery,(unsigned long)millis());
}
void resetSystem() {
  detector.reset(); alarmOn=false; cameraRequested=false; eventRequested=false; eventSubmitted=false;
#if defined(ENABLE_ESP32_CAM)
  digitalWrite(BUZZER,LOW);
#else
  noTone(BUZZER);
#endif
  Serial.println("RESET: monitoring resumed");
}
void setup() {
  Serial.begin(115200);
  bootIdHigh=esp_random(); bootIdLow=esp_random();
  pinMode(EMERGENCY,INPUT_PULLUP);
  pinMode(GREEN,OUTPUT); pinMode(YELLOW,OUTPUT); pinMode(RED,OUTPUT); pinMode(BUZZER,OUTPUT);
  Wire.begin(SDA_PIN,SCL_PIN);
  sensorOK=mpu.begin();
  if(sensorOK) { mpu.setAccelerometerRange(MPU6050_RANGE_8_G); mpu.setGyroRange(MPU6050_RANGE_500_DEG); }
  cameraOK=false;
#if defined(ENABLE_ESP32_CAM)
  cameraOK=camera.begin();
#else
  // The separate OV2640 node is checked by Laravel, not by this main board.
  cameraOK=true;
#endif
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID_VALUE,WIFI_PASSWORD_VALUE);
#if defined(ENABLE_ESP32_CAM)
  Serial.println("ESP32-CAM: actual MPU6050 measurements and OV2640 capture enabled.");
#else
  Serial.println("PHYSICAL MODE: MPU6050 events go to Laravel; the separate camera is checked by the server.");
#endif
  WiFi.setAutoReconnect(true);
  if (xTaskCreate(heartbeatTask, "monitor-heartbeat", 6144, nullptr, 1, nullptr) != pdPASS)
    Serial.println("HEARTBEAT FAULT: task could not start.");
  Serial.println("Use the physical emergency button. Serial r resets the local alarm after attending to the patient.");
  Serial.println(sensorOK ? "MPU6050 ready: reading actual motion." : "SENSOR FAULT: check wiring. Physical emergency button still works.");
}
void loop() {
  uint32_t now=millis();
  int b=digitalRead(EMERGENCY);
  if(b!=rawButton) { rawButton=b; buttonChanged=now; }
  if(now-buttonChanged>=35 && stableButton!=rawButton) {
    stableButton=rawButton; if(stableButton==LOW) alarmEvent("manual_emergency");
  }
  while(Serial.available()) {
    char c=Serial.read();
    if(c=='r') resetSystem();
  }
  if(!sensorOK && now-lastSensorProbe>=5000) {
    lastSensorProbe=now;
    sensorOK=mpu.begin();
    if(sensorOK) {
      mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
      mpu.setGyroRange(MPU6050_RANGE_500_DEG);
      Serial.println("MPU6050 recovered: actual measurements resumed.");
    }
  }
  if(now-sampled>=20 && !alarmOn) {
    sampled=now; float a=0, w=0; bool valid=false;
    if(sensorOK) {
      sensors_event_t acc,gyro,temp;
      if(mpu.getEvent(&acc,&gyro,&temp)) {
        a=sqrtf(acc.acceleration.x*acc.acceleration.x+acc.acceleration.y*acc.acceleration.y+acc.acceleration.z*acc.acceleration.z)/9.80665f;
        w=sqrtf(gyro.gyro.x*gyro.gyro.x+gyro.gyro.y*gyro.gyro.y+gyro.gyro.z*gyro.gyro.z);
        valid=isfinite(a) && isfinite(w);
      }
      sensorOK=valid;
    }
    auto old=detector.state;
    if(valid) {
      lastAcceleration=a; lastGyro=w;
      detector.update(now,a,w);
    } else { detector.reset(); }
    if(old==Detector::NORMAL && detector.state!=Detector::NORMAL) {
      eventSubmitted=false; eventRequested=false; cameraRequested=false;
    }
    if(old!=detector.state) Serial.printf("STATE: %s | a=%.2fg gyro=%.2frad/s\n",detector.name(),a,w);
    if(detector.state==Detector::WAITING_CAMERA) {
      if(!eventRequested) {
        // A pose result cannot silently suppress the caregiver's motion alert.
        sendEvent("possible_fall");
        eventRequested=true;
      }
#if defined(ENABLE_ESP32_CAM)
      if(!cameraRequested && cameraOK && WiFi.status()==WL_CONNECTED) {
        cameraRequested=true;
        CameraResult result=camera.verify();
        Serial.printf("CAMERA: verdict=%d confidence=%.2f http=%d\n",
          (int)result.verdict,result.confidence,result.httpStatus);
        if(result.verdict==CameraVerdict::CONFIRMED_FALL)
          alarmEvent("fall_camera_confirmed");
        else if(result.verdict==CameraVerdict::NO_FALL) {
          detector.reset(); cameraRequested=false;
        }
      }
#else
      cameraRequested=true;
#endif
      if(!alarmOn && detector.state==Detector::WAITING_CAMERA && millis()-detector.entered>=5000)
        alarmEvent("possible_fall_camera_timeout");
    }
  }
  const bool ready=sensorOK && cameraOK && WiFi.status()==WL_CONNECTED;
  digitalWrite(GREEN,!alarmOn && ready && detector.state==Detector::NORMAL);
  digitalWrite(YELLOW,!alarmOn && (!ready || detector.state!=Detector::NORMAL));
#if defined(ENABLE_ESP32_CAM)
  digitalWrite(RED,!alarmOn);
#else
  digitalWrite(RED,alarmOn);
#endif
  static bool wifiReported=false;
  if(!wifiReported && WiFi.status()==WL_CONNECTED) {
    wifiReported=true;
#if defined(ENABLE_ESP32_CAM)
    Serial.println("WiFi connected; camera verification endpoint enabled.");
#else
    Serial.println("WiFi connected; Laravel event API and periodic heartbeat enabled.");
#endif
  }
}
