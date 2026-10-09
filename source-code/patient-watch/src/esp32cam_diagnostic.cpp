// Isolated, USB-only OV2640 test. No Wi-Fi, flash, credentials or web uploads.
// Run scripts/test_camera_serial.py, then restore the esp32cam environment.
#include <Arduino.h>
#include "esp_camera.h"
#include "esp_heap_caps.h"
#include "driver/gpio.h"

struct CaptureProfile {
  const char* name;
  uint32_t clock;
  framesize_t size;
  bool psram;
  uint8_t buffers;
  pixformat_t format;
};

constexpr CaptureProfile profiles[] = {
  {"standard20-qvga-dram", 20000000, FRAMESIZE_QVGA, false, 1, PIXFORMAT_JPEG},
  {"standard20-vga-psram", 20000000, FRAMESIZE_VGA, true, 1, PIXFORMAT_JPEG},
  {"existing8-vga-psram", 8000000, FRAMESIZE_VGA, true, 1, PIXFORMAT_JPEG},
  {"standard20-vga-2buffers", 20000000, FRAMESIZE_VGA, true, 2, PIXFORMAT_JPEG},
  {"standard20-raw-colorbar", 20000000, FRAMESIZE_QQVGA, false, 1, PIXFORMAT_RGB565},
};

camera_config_t configFor(const CaptureProfile& profile) {
  camera_config_t config = {};
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = 5;
  config.pin_d1 = 18;
  config.pin_d2 = 19;
  config.pin_d3 = 21;
  config.pin_d4 = 36;
  config.pin_d5 = 39;
  config.pin_d6 = 34;
  config.pin_d7 = 35;
  config.pin_xclk = 0;
  config.pin_pclk = 22;
  config.pin_vsync = 25;
  config.pin_href = 23;
  config.pin_sccb_sda = 26;
  config.pin_sccb_scl = 27;
  config.pin_pwdn = 32;
  config.pin_reset = -1;
  config.xclk_freq_hz = profile.clock;
  config.pixel_format = profile.format;
  config.frame_size = profile.size;
  config.jpeg_quality = 14;
  config.fb_count = profile.buffers;
  config.fb_location = profile.psram ? CAMERA_FB_IN_PSRAM : CAMERA_FB_IN_DRAM;
  config.grab_mode = profile.buffers > 1 ? CAMERA_GRAB_LATEST : CAMERA_GRAB_WHEN_EMPTY;
  return config;
}

bool psramCheck() {
  constexpr size_t bytes = 262144;
  auto* memory = static_cast<uint8_t*>(heap_caps_malloc(bytes, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT));
  if (!memory) return false;
  for (size_t i = 0; i < bytes; ++i) memory[i] = static_cast<uint8_t>((i * 37) ^ (i >> 8));
  bool valid = true;
  for (size_t i = 0; i < bytes; ++i) {
    if (memory[i] != static_cast<uint8_t>((i * 37) ^ (i >> 8))) { valid = false; break; }
  }
  heap_caps_free(memory);
  return valid;
}

void reportSignals(const char* profile) {
  unsigned pclkEdges = 0, vsyncEdges = 0, hrefEdges = 0;
  int pclk = gpio_get_level(GPIO_NUM_22);
  int vsync = gpio_get_level(GPIO_NUM_25);
  int href = gpio_get_level(GPIO_NUM_23);
  const uint32_t started = micros();
  while (static_cast<uint32_t>(micros() - started) < 50000) {
    const int nextPclk = gpio_get_level(GPIO_NUM_22);
    const int nextVsync = gpio_get_level(GPIO_NUM_25);
    const int nextHref = gpio_get_level(GPIO_NUM_23);
    pclkEdges += nextPclk != pclk;
    vsyncEdges += nextVsync != vsync;
    hrefEdges += nextHref != href;
    pclk = nextPclk; vsync = nextVsync; href = nextHref;
  }
  Serial.printf("DIAG_SIGNALS {\"profile\":\"%s\",\"pclk_edges\":%u,\"vsync_edges\":%u,\"href_edges\":%u}\n",
                profile, pclkEdges, vsyncEdges, hrefEdges);
}

void runDiagnostic(bool rawOnly) {
  Serial.printf("DIAG_BEGIN {\"psram_found\":%s,\"psram_memory_ok\":%s}\n",
                psramFound() ? "true" : "false", psramCheck() ? "true" : "false");
  bool initialized = false;
  for (const auto& profile : profiles) {
    if ((profile.format != PIXFORMAT_JPEG) != rawOnly) continue;
    if (initialized) esp_camera_deinit();
    initialized = false;
    if (profile.psram && !psramFound()) continue;
    pinMode(32, OUTPUT);
    digitalWrite(32, HIGH);
    delay(200);
    digitalWrite(32, LOW);
    delay(200);
    camera_config_t config = configFor(profile);
    const esp_err_t result = esp_camera_init(&config);
    initialized = result == ESP_OK;
    sensor_t* sensor = initialized ? esp_camera_sensor_get() : nullptr;
    Serial.printf("DIAG_INIT {\"profile\":\"%s\",\"code\":%u,\"sensor_pid\":%u}\n",
                  profile.name, static_cast<unsigned>(result), sensor ? sensor->id.PID : 0);
    if (!initialized) continue;
    if (rawOnly && sensor) {
      Serial.printf("DIAG_COLORBAR {\"result\":%d}\n", sensor->set_colorbar(sensor, 1));
    }
    delay(300);
    reportSignals(profile.name);
    for (unsigned attempt = 1; attempt <= 3; ++attempt) {
      const uint32_t started = millis();
      camera_fb_t* frame = esp_camera_fb_get();
      uint32_t hash = 2166136261u;
      if (frame) for (size_t i = 0; i < frame->len; ++i) hash = (hash ^ frame->buf[i]) * 16777619u;
      Serial.printf("DIAG_FRAME {\"profile\":\"%s\",\"format\":\"%s\",\"width\":%u,\"height\":%u,\"attempt\":%u,\"bytes\":%u,\"hash\":%u,\"capture_ms\":%u}\n",
                    profile.name, rawOnly ? "rgb565" : "jpeg",
                    frame ? static_cast<unsigned>(frame->width) : 0,
                    frame ? static_cast<unsigned>(frame->height) : 0,
                    attempt, frame ? static_cast<unsigned>(frame->len) : 0,
                    hash, millis() - started);
      if (frame) {
        Serial.write(frame->buf, frame->len);
        Serial.flush();
        esp_camera_fb_return(frame);
      }
      Serial.println();
      delay(150);
    }
  }
  if (initialized) esp_camera_deinit();
  Serial.println("DIAG_DONE");
}

void setup() {
  Serial.begin(115200);
  pinMode(4, OUTPUT);
  digitalWrite(4, LOW);
  delay(150);
  Serial.println("DIAG_READY: send START for JPEG or RAW for colorbar");
}

void loop() {
  if (Serial.available()) {
    const String command = Serial.readStringUntil('\n');
    if (command == "START") runDiagnostic(false);
    else if (command == "RAW") runDiagnostic(true);
  }
  delay(10);
}
