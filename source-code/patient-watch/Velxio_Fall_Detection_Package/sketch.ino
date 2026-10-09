#include <Wire.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <WiFi.h>
#include "detector.h"
#include "camera_verifier.h"
#include "camera_settings.h"

Adafruit_MPU6050 mpu;
Detector detector;
CameraVerifier camera;
#if defined(ENABLE_ESP32_CAM)
const int EMERGENCY=12, GREEN=2, YELLOW=4, RED=33, BUZZER=15, SDA_PIN=13, SCL_PIN=14;
const char* SIMULATION_JSON="false";
#else
const int EMERGENCY=27, GREEN=18, YELLOW=19, RED=23, BUZZER=25, SDA_PIN=21, SCL_PIN=22;
const char* SIMULATION_JSON="true";
#endif
bool sensorOK=false, cameraOK=false, alarmOn=false, demo=false, cameraAuto=true, cameraReject=false;
bool cameraRequested=false;
uint32_t demoStart=0, sampled=0, buttonChanged=0, count=0;
int rawButton=HIGH, stableButton=HIGH;

void alarmEvent(const char* type) {
  if (alarmOn) return;
  alarmOn=true; demo=false;
#if defined(ENABLE_ESP32_CAM)
  digitalWrite(BUZZER,HIGH);
#else
  tone(BUZZER,1500);
#endif
  Serial.printf("{\"event_id\":%lu,\"event_type\":\"%s\",\"is_simulated\":%s,\"delivery\":\"serial_only\",\"uptime_ms\":%lu}\n",
    (unsigned long)++count,type,SIMULATION_JSON,(unsigned long)millis());
}
void resetSystem() {
  detector.reset(); alarmOn=false; demo=false; cameraRequested=false; cameraReject=false;
#if defined(ENABLE_ESP32_CAM)
  digitalWrite(BUZZER,LOW);
#else
  noTone(BUZZER);
#endif
  Serial.println("RESET: monitoring resumed");
}
void setup() {
  Serial.begin(115200);
  pinMode(EMERGENCY,INPUT_PULLUP);
  pinMode(GREEN,OUTPUT); pinMode(YELLOW,OUTPUT); pinMode(RED,OUTPUT); pinMode(BUZZER,OUTPUT);
  Wire.begin(SDA_PIN,SCL_PIN);
  sensorOK=mpu.begin();
  if(sensorOK) { mpu.setAccelerometerRange(MPU6050_RANGE_8_G); mpu.setGyroRange(MPU6050_RANGE_500_DEG); }
  cameraOK=camera.begin();
#if defined(ENABLE_ESP32_CAM)
  WiFi.begin(WIFI_SSID_VALUE,WIFI_PASSWORD_VALUE);
#else
  WiFi.begin("Wokwi-GUEST","",6);
#endif
#if defined(ENABLE_ESP32_CAM)
  Serial.println("ESP32-CAM verification enabled; educational prototype, not a medical device.");
#else
  Serial.println("SIMULATION ONLY - camera verdicts contain no real images.");
#endif
  Serial.println("Commands: f=demo fall + simulated camera response; t=demo fall + camera timeout; e=emergency; r=reset; c=simulated camera response; n=normal demo");
  Serial.println("Wokwi only: v=demo fall + camera says no fall.");
  Serial.println(sensorOK?"MPU6050 ready. Default input: sensor sliders.":"SENSOR FAULT: manual emergency still works; demo commands remain available.");
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
    if(c=='e') alarmEvent("manual_emergency");
    if(c=='f'||c=='t'||c=='v') {
      resetSystem(); demo=true; demoStart=now;
      cameraAuto=(c=='f'); cameraReject=(c=='v');
      Serial.println("DEMO: synthetic motion injected, not MPU6050 measurements");
    }
    if(c=='n') { resetSystem(); Serial.println("NORMAL: stationary synthetic sample => no alarm"); detector.update(now,1.0f,0.0f); }
#if !defined(ENABLE_ESP32_CAM)
    if(c=='c' && detector.state==Detector::WAITING_CAMERA && !alarmOn)
      alarmEvent("possible_fall_simulated_camera_response");
#endif
  }
  if(now-sampled>=20 && !alarmOn) {
    sampled=now; float a=1, w=0; bool valid=sensorOK||demo;
    if(demo) {
      uint32_t elapsed=now-demoStart;
      a=elapsed<500?1.0f:elapsed<700?0.2f:elapsed<800?3.0f:1.0f;
      w=elapsed>=700 && elapsed<800?1.5f:0;
    } else if(sensorOK) {
      sensors_event_t acc,gyro,temp; mpu.getEvent(&acc,&gyro,&temp);
      a=sqrtf(acc.acceleration.x*acc.acceleration.x+acc.acceleration.y*acc.acceleration.y+acc.acceleration.z*acc.acceleration.z)/9.80665f;
      w=sqrtf(gyro.gyro.x*gyro.gyro.x+gyro.gyro.y*gyro.gyro.y+gyro.gyro.z*gyro.gyro.z);
    }
    auto old=detector.state;
    if(valid) detector.update(now,a,w);
    if(old!=detector.state) Serial.printf("STATE: %s | a=%.2fg gyro=%.2frad/s\n",detector.name(),a,w);
    if(detector.state==Detector::WAITING_CAMERA) {
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
      if(demo && cameraAuto && now-detector.entered>=1000) alarmEvent("possible_fall_simulated_camera_response");
      else if(demo && cameraReject && now-detector.entered>=1000) {
        detector.reset(); demo=false; cameraRequested=false;
      }
#endif
      else if(millis()-detector.entered>=5000) alarmEvent("possible_fall_camera_timeout");
    }
  }
  digitalWrite(GREEN,!alarmOn && sensorOK && cameraOK && detector.state==Detector::NORMAL);
  digitalWrite(YELLOW,!alarmOn && (!sensorOK || !cameraOK || detector.state!=Detector::NORMAL));
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
    Serial.println("WiFi connected; event output remains Serial only.");
#endif
  }
}
