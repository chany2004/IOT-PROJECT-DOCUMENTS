#pragma once

// -------------------- Pin Map (canonical wiring) --------------------
#define PIN_BUTTON      13
#define PIN_BUZZER      14
#define PIN_I2C_SDA     21
#define PIN_I2C_SCL     22

// -------------------- MPU6050 --------------------
#define MPU6050_ADDR            0x68
#define MPU6050_REG_PWR_MGMT_1  0x6B
#define MPU6050_REG_CONFIG      0x1A
#define MPU6050_REG_GYRO_CONFIG 0x1B
#define MPU6050_REG_ACCEL_CONFIG 0x1C
#define MPU6050_REG_ACCEL_XOUT_H 0x3B

// -------------------- Wi-Fi / Webhook (EDIT THESE) --------------------
static const char* WIFI_SSID     = "YOUR_WIFI_SSID";
static const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
static const char* WEBHOOK_URL   = "https://your-server.example.com/fall-alert";

// -------------------- Timing --------------------
static const uint32_t SAMPLE_PERIOD_MS        = 50;    // 20 Hz
static const uint32_t FALL_WINDOW_MS          = 250;   // accel + gyro must occur within this window
static const uint32_t FALL_COOLDOWN_MS        = 10000; // suppress repeated fall alerts
static const uint32_t WIFI_RETRY_INTERVAL_MS  = 5000;
static const uint32_t MPU_INIT_RETRY_INTERVAL_MS = 1000;

// -------------------- Buzzer Alarm Pattern --------------------
static const uint32_t BUZZER_BEEP_ON_MS       = 120;
static const uint32_t BUZZER_BEEP_OFF_MS      = 120;
static const uint8_t  BUZZER_BEEP_COUNT       = 4;
static const uint16_t BUZZER_FALL_TONE_HZ     = 2200;
static const uint16_t BUZZER_EMERGENCY_TONE_HZ = 2800;

// -------------------- Fall Detection Thresholds --------------------
static const float ACCEL_SPIKE_THRESHOLD_G    = 2.20f;   // total accel magnitude
static const float GYRO_SPIKE_THRESHOLD_DPS   = 220.0f;  // total angular velocity magnitude
static const float FILTER_ALPHA               = 0.30f;   // simple EMA filtering

// Tinkered may expose only accelerometer movement while gyro remains zero.
// This simulator fallback still uses real MPU6050 values from the simulator.
static const float SIM_GYRO_ZERO_MAX_DPS      = 1.0f;
static const float SIM_NORMAL_MIN_G           = 0.75f;
static const float SIM_NORMAL_MAX_G           = 1.30f;
static const uint32_t SIM_BASELINE_HOLD_MS    = 750;
static const float SIM_BASELINE_STABILITY_G   = 0.25f;
static const float SIM_POSTURE_THRESHOLD_DEG  = 35.0f;
static const float SIM_UNARMED_IMPACT_G       = 2.80f;
