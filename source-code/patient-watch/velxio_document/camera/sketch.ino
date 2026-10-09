// Fall Detection - CAMERA NODE (ESP32-CAM AI-Thinker + OV2640)
//
// The document specifies a separate camera controller.  This sketch starts
// the OV2640 and exposes /health plus authenticated JPEG endpoints.
// Laravel stores actual JPEGs; the separate backend performs optional pose analysis.

#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <freertos/FreeRTOS.h>
#include <freertos/semphr.h>
#include <lwip/sockets.h>
#include <errno.h>
#include <stdlib.h>
#include <atomic>
#include "esp_system.h"
#include "esp_timer.h"
#include "esp_camera.h"
#include "camera_safety.h"
#if __has_include("camera_node_secrets.h")
#include "camera_node_secrets.h"
#else
#define CAMERA_WIFI_SSID "YOUR_WIFI_NAME"
#define CAMERA_WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define CAMERA_NODE_KEY ""
#endif
#ifndef CAMERA_HEARTBEAT_URL
#define CAMERA_HEARTBEAT_URL ""
#endif
#ifndef CAMERA_DEVICE_KEY
#define CAMERA_DEVICE_KEY ""
#endif
#include "../../src/cloud_http.h"

#ifndef CAMERA_DIRECT_UPLOAD_ENABLED
#define CAMERA_DIRECT_UPLOAD_ENABLED 0
#endif
#ifndef CAMERA_FLASH_ENABLED
// First bring-up of a new board: avoid the white LED's extra supply load.
// Enable only after confirming the board, supply and capture stability.
#define CAMERA_FLASH_ENABLED 0
#endif
static_assert(CAMERA_FLASH_ENABLED == 0 || CAMERA_FLASH_ENABLED == 1,
              "CAMERA_FLASH_ENABLED must be 0 or 1");

constexpr char WIFI_SSID[] = CAMERA_WIFI_SSID;
constexpr char WIFI_PASSWORD[] = CAMERA_WIFI_PASSWORD;
constexpr char CAMERA_FIRMWARE_VERSION[] = "safe-vga8-q14-fresh-20261008";
WiFiServer server(80);
bool serverStarted = false;
uint32_t lastServerStartAttemptAt = 0;
std::atomic<bool> cameraReady{false};
std::atomic<bool> captureAttempted{false};
std::atomic<bool> lastCaptureOk{false};
std::atomic<int> lastHeartbeatStatus{0};
std::atomic<uint32_t> lastHeartbeatAt{0};
std::atomic<uint32_t> heartbeatStackFree{0};
camera_config_t cameraConfig = {};
std::atomic<uint32_t> cameraRecoveryAttempts{0};
CameraSafety::RecoveryLimiter recoveryLimiter;
bool cameraRecoveryBlocked = false;
bool powerGuardLatched = false;
// NOINIT (not initialized DATA) survives software/watchdog resets. Validate
// before use and clear only on true power-on; never write a boot counter to NVS.
RTC_NOINIT_ATTR uint32_t cameraBootMagic;
RTC_NOINIT_ATTR uint32_t cameraRapidBoots;
RTC_NOINIT_ATTR uint32_t cameraPowerLatch;
SemaphoreHandle_t networkMutex = nullptr;
constexpr uint8_t FLASH_LED_PIN = 4;
constexpr unsigned long FLASH_WARMUP_MS = 180;
constexpr unsigned long FLASH_MAX_ON_MS = 300;
constexpr unsigned long FLASH_COOLDOWN_MS = 5000;
esp_timer_handle_t flashCutoffTimer = nullptr;
uint32_t lastFlashAt = 0;
bool flashUsed = false;
// Keep each write below the usual Wi-Fi TCP MSS and allow a slow hotspot
// enough time to drain the socket. Closing the connection after only part of
// an HD frame produces a JPEG header without its FF D9 end marker.
constexpr size_t JPEG_CHUNK_BYTES = 1360;
std::atomic<bool> outboundUploadActive{false};
// Protected by CameraLock, like the frame itself. Uptime timestamps, not
// patient data or wall-clock timestamps; included for freshness diagnostics.
int64_t lastFrameRequestUs = 0;
unsigned lastDiscardedFrames = 0;

int64_t frameTimestampUs(const camera_fb_t* frame) {
  return int64_t(frame->timestamp.tv_sec) * 1000000LL + frame->timestamp.tv_usec;
}

class CameraLock {
 public:
  explicit CameraLock(uint32_t timeoutMs) : taken_(networkMutex &&
      xSemaphoreTake(networkMutex, pdMS_TO_TICKS(timeoutMs)) == pdTRUE) {}
  ~CameraLock() { if (taken_) xSemaphoreGive(networkMutex); }
  explicit operator bool() const { return taken_; }
  CameraLock(const CameraLock&) = delete;
  CameraLock& operator=(const CameraLock&) = delete;
 private:
  bool taken_;
};

class FrameGuard {
 public:
  explicit FrameGuard(camera_fb_t* frame) : frame_(frame) {}
  ~FrameGuard() { if (frame_) esp_camera_fb_return(frame_); }
  FrameGuard(const FrameGuard&) = delete;
  FrameGuard& operator=(const FrameGuard&) = delete;
 private:
  camera_fb_t* frame_;
};

void flashCutoff(void*) { digitalWrite(FLASH_LED_PIN, LOW); }

class FlashGuard {
 public:
  explicit FlashGuard(bool requested) {
    if (!CAMERA_FLASH_ENABLED || !requested || !flashCutoffTimer || powerGuardLatched ||
        (flashUsed && !CameraSafety::elapsed(millis(), lastFlashAt, FLASH_COOLDOWN_MS))) return;
    lastFlashAt = millis();
    flashUsed = true;
    digitalWrite(FLASH_LED_PIN, HIGH);
    if (esp_timer_start_once(flashCutoffTimer, FLASH_MAX_ON_MS * 1000ULL) != ESP_OK) {
      digitalWrite(FLASH_LED_PIN, LOW);
      return;
    }
    delay(FLASH_WARMUP_MS);
  }
  ~FlashGuard() {
    digitalWrite(FLASH_LED_PIN, LOW);
    if (flashCutoffTimer) esp_timer_stop(flashCutoffTimer);
  }
};

// A successful SCCB probe only confirms initialization. Once a frame has
// been requested, readiness must reflect that result until a frame recovers.
bool cameraOperational() {
  return cameraReady && (!captureAttempted || lastCaptureOk);
}

void heartbeatTask(void*) {
  for (;;) {
    // A heartbeat uses its own HTTP socket. Never hold the capture mutex
    // across this request: a slow server must not delay an emergency frame.
    bool captureIdle = false;
    if (networkMutex) {
      captureIdle = xSemaphoreTake(networkMutex, 0) == pdTRUE;
      if (captureIdle) xSemaphoreGive(networkMutex);
    }
    if (captureIdle && !outboundUploadActive && WiFi.status() == WL_CONNECTED && strlen(CAMERA_HEARTBEAT_URL) > 8 &&
        strlen(CAMERA_DEVICE_KEY) >= 32) {
      CloudHttpClient request;
      if (request.begin(CAMERA_HEARTBEAT_URL, 5000, 7000)) {
        HTTPClient& http = request.http();
        http.addHeader("Content-Type", "application/json");
        http.addHeader("X-Device-Key", CAMERA_DEVICE_KEY);
        const int status = http.POST(cameraOperational() ? "{\"sensor_ok\":true}" : "{\"sensor_ok\":false}");
        lastHeartbeatStatus = status;
        if (status == 200) lastHeartbeatAt = millis();
        heartbeatStackFree = uxTaskGetStackHighWaterMark(nullptr);
        Serial.printf("CAMERA_HEARTBEAT_API: status=%d\n", status);
        http.end();
      }
    }
    vTaskDelay(pdMS_TO_TICKS(30000));
  }
}

camera_config_t makeCameraConfig() {
  camera_config_t config = {};
  const bool hasPsram = psramFound();
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
  // Preserve the existing tested image profile; do not stress-test a new
  // camera by automatically cycling clocks, sensor modes or frame buffers.
  config.xclk_freq_hz = 8000000;
  config.pixel_format = PIXFORMAT_JPEG;
  // Evidence needs a fresh still frame, not continuous video. One buffer
  // avoids continuous I2S capture competing with Wi-Fi and PSRAM writes.
  // Keep VGA when PSRAM is available and QVGA/internal-RAM as a fallback.
  config.frame_size = hasPsram ? FRAMESIZE_VGA : FRAMESIZE_QVGA;
  config.jpeg_quality = hasPsram ? 14 : 16;
  config.fb_count = 1;
  config.fb_location = hasPsram ? CAMERA_FB_IN_PSRAM : CAMERA_FB_IN_DRAM;
  config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
  return config;
}

bool recoverCamera() {
  // A temporary frame timeout must not leave a PSRAM-capable camera on the
  // QVGA/internal-RAM profile. That profile produced JPEGs with missing scan
  // data on this board even after the sensor began returning frames again.
  // Retry the same known-good startup profile; boards without PSRAM still use
  // QVGA/internal RAM through makeCameraConfig().
  // Called only while CameraLock is held. No allocation/reset storm on a
  // loose ribbon, invalid frames, or a camera that never initialized.
  if (powerGuardLatched || cameraRecoveryBlocked || !recoveryLimiter.permit(millis())) return false;
  ++cameraRecoveryAttempts;
  if (cameraReady.exchange(false)) {
    const esp_err_t stopped = esp_camera_deinit();
    if (stopped != ESP_OK) {
      cameraRecoveryBlocked = true;
      Serial.printf("CAMERA_RECOVER: deinit=0x%x; stopped until power cycle\n", (unsigned)stopped);
      return false;
    }
  }
  delay(60);
  const esp_err_t initResult = esp_camera_init(&cameraConfig);
  cameraReady = (initResult == ESP_OK);
  Serial.printf("CAMERA_RECOVER: init=0x%x profile=%s buffers=%u\n",
                (unsigned)initResult,
                cameraConfig.frame_size == FRAMESIZE_VGA ? "vga" : "qvga",
                (unsigned)cameraConfig.fb_count);
  return cameraReady;
}

camera_fb_t* captureFrame(bool illuminate) {
  const unsigned long warmupStarted = millis();
  FlashGuard flash(illuminate);

  const unsigned long frameStarted = millis();
  lastFrameRequestUs = esp_timer_get_time();
  camera_fb_t* frame = CameraSafety::freshFrame<camera_fb_t>(lastFrameRequestUs,
      []() { return esp_camera_fb_get(); },
      [](camera_fb_t* stale) { esp_camera_fb_return(stale); },
      [](camera_fb_t* candidate) { return frameTimestampUs(candidate); },
      []() { return esp_timer_get_time(); }, lastDiscardedFrames);
  captureAttempted = true;
  const bool valid = frame && frame->format == PIXFORMAT_JPEG &&
      frame->width > 0 && frame->height > 0 && frame->width <= 640 && frame->height <= 480 &&
      CameraSafety::validJpeg(frame->buf, frame->len);
  lastCaptureOk = valid;
  if (frame && !valid) {
    Serial.println("CAMERA_FRAME: rejected invalid/truncated JPEG; not sent");
    esp_camera_fb_return(frame);
    frame = nullptr;
  }
  if (valid) recoveryLimiter.successfulFrame();
  Serial.printf("CAMERA_FRAME: warmup_ms=%lu frame_ms=%lu discarded=%u frame_ok=%s\n",
                frameStarted - warmupStarted,
                millis() - frameStarted, lastDiscardedFrames, frame ? "yes" : "no");
  return frame;
}

camera_fb_t* captureWithFlash() { return captureFrame(true); }

// Preview frames are short-lived JPEG responses rather than a persistent
// MJPEG connection. A bounded request lets Laravel poll the camera without
// starving /capture, heartbeat traffic, or another caregiver request.
// Previews intentionally avoid the flash so the next evidence snapshot
// retains its normal illuminated exposure.
camera_fb_t* capturePreview() {
  return captureFrame(false);
}

#if CAMERA_DIRECT_UPLOAD_ENABLED
// This option is for the trusted local-LAN HTTP endpoint only. A public web
// deployment needs certificate-validated HTTPS before carrying patient images.
String cameraApiUrl(const String& suffix) {
  String base(CAMERA_HEARTBEAT_URL);
  if (!base.startsWith("http://") || !base.endsWith("/heartbeat")) return String();
  base.remove(base.length() - strlen("heartbeat"));
  return base + "camera/" + suffix;
}

void uploadPendingSnapshot() {
  if (WiFi.status() != WL_CONNECTED || strlen(CAMERA_DEVICE_KEY) < 32) return;
  const String pendingUrl = cameraApiUrl("pending");
  if (pendingUrl.isEmpty()) return;

  HTTPClient poll;
  poll.setConnectTimeout(750);
  poll.setTimeout(1000);
  // HTTP/1.0 plus Connection: close avoids raw chunk framing and permits the
  // Laravel plain-text ID endpoint to omit Content-Length safely.
  poll.useHTTP10(true);
  poll.setReuse(false);
  if (!poll.begin(pendingUrl)) return;
  poll.addHeader("X-Device-Key", CAMERA_DEVICE_KEY);
  const int pollStatus = poll.GET();
  // The pending endpoint is a decimal ID, never an unbounded JSON/image body.
  char pendingBody[24]{};
  const int bodySize = poll.getSize();
  const bool boundedBody = pollStatus == 200 && bodySize != 0 && bodySize < (int)sizeof(pendingBody);
  size_t received = 0;
  bool completeBody = false;
  const uint32_t bodyStarted = millis();
  if (boundedBody) {
    WiFiClient& stream = poll.getStream();
    while (!CameraSafety::elapsed(millis(), bodyStarted, 1500)) {
      if (stream.available()) {
        if (received == sizeof(pendingBody) - 1) break;
        const int value = stream.read();
        if (value >= 0) pendingBody[received++] = (char)value;
        if (bodySize > 0 && received == (size_t)bodySize) { completeBody = true; break; }
      } else if (!stream.connected()) {
        completeBody = bodySize < 0 || received == (size_t)bodySize;
        break;
      } else delay(2);
    }
  }
  poll.end();
  if (!boundedBody || !completeBody || !received) return;
  char* idEnd = nullptr;
  errno = 0;
  const long pendingId = strtol(pendingBody, &idEnd, 10);
  if (pendingBody[0] < '0' || pendingBody[0] > '9' || errno == ERANGE ||
      pendingId <= 0 || !idEnd || *idEnd != '\0') return;

  static long lastId = 0;
  static uint8_t attemptsForId = 0;
  static uint32_t retryAfter = 0;
  if (pendingId != lastId) {
    lastId = pendingId;
    attemptsForId = 0;
  }
  if (attemptsForId >= 2 || (int32_t)(millis() - retryAfter) < 0) return;
  ++attemptsForId;
  retryAfter = millis() + 2500;

  // Polling runs on a separate task so a slow Laravel response cannot block
  // /health or a legacy /capture request. Only the actual frame operation and
  // upload take the camera lock; if a pull capture is active, retry later.
  CameraLock cameraLock(50);
  if (!cameraLock) {
    --attemptsForId;
    return;
  }
  outboundUploadActive = true;
  if (!cameraReady && !recoverCamera()) {
    outboundUploadActive = false;
    return;
  }
  const uint32_t captureStarted = millis();
  camera_fb_t* frame = captureWithFlash();
  if (!frame && recoverCamera()) frame = captureWithFlash();
  if (!frame) {
    Serial.printf("CAMERA_PUSH: id=%ld frame=unavailable capture_ms=%lu\n",
                  pendingId, (unsigned long)(millis() - captureStarted));
    outboundUploadActive = false;
    return;
  }
  FrameGuard ownedFrame(frame);

  const String uploadUrl = cameraApiUrl(String("captures/") + String(pendingId));
  HTTPClient upload;
  upload.setConnectTimeout(1500);
  upload.setTimeout(12000);
  int uploadStatus = -1;
  const uint32_t sendStarted = millis();
  if (upload.begin(uploadUrl)) {
    upload.addHeader("X-Device-Key", CAMERA_DEVICE_KEY);
    upload.addHeader("Content-Type", "image/jpeg");
    uploadStatus = upload.sendRequest("POST", frame->buf, frame->len);
    upload.end();
  }
  Serial.printf("CAMERA_PUSH: id=%ld capture_ms=%lu send_ms=%lu bytes=%u status=%d\n",
                pendingId, (unsigned long)(sendStarted - captureStarted),
                (unsigned long)(millis() - sendStarted), (unsigned)frame->len,
                uploadStatus);
  outboundUploadActive = false;
  if (uploadStatus == 200 || uploadStatus == 201) attemptsForId = 2;
}

void directUploadTask(void*) {
  for (;;) {
    uploadPendingSnapshot();
    // Leave breathing room for inbound /capture and /health on a small hotspot.
    vTaskDelay(pdMS_TO_TICKS(2000));
  }
}
#endif

bool sendAll(WiFiClient& client, const uint8_t* data, size_t length) {
  if (!data || !length) return false;
  size_t sent = 0;
  unsigned long lastProgress = millis();
  const uint32_t started = lastProgress;
  const int socketFd = client.fd();
  if (socketFd < 0) {
    return false;
  }

  while (sent < length && client.connected()) {
    if (CameraSafety::elapsed(millis(), started, CameraSafety::SEND_TIMEOUT_MS)) return false;
    const size_t remaining = length - sent;
    const size_t chunk = remaining < JPEG_CHUNK_BYTES ? remaining : JPEG_CHUNK_BYTES;
    const int written = send(socketFd, data + sent, chunk, MSG_DONTWAIT);

    if (written > 0) {
      sent += (size_t) written;
      lastProgress = millis();
    } else {
      if (written < 0 && errno != EAGAIN && errno != EWOULDBLOCK) {
        return false;
      }
      if (CameraSafety::elapsed(millis(), lastProgress, CameraSafety::SEND_IDLE_TIMEOUT_MS)) {
        return false;
      }
      delay(2);
    }
    taskYIELD();
  }

  return sent == length;
}

void jsonResponse(WiFiClient& client, int status, const char* body) {
  const char* reason = status == 200 ? "OK" : status == 401 ? "Unauthorized" :
      status == 404 ? "Not Found" : status == 405 ? "Method Not Allowed" :
      status == 408 ? "Request Timeout" : status == 431 ? "Request Header Fields Too Large" :
      status == 503 ? "Service Unavailable" : "Bad Request";
  char header[224];
  const int count = snprintf(header, sizeof(header),
      "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\n"
      "Cache-Control: no-store\r\nConnection: close\r\nContent-Length: %u\r\n\r\n",
      status, reason, (unsigned)strlen(body));
  if (count > 0 && count < (int)sizeof(header) &&
      sendAll(client, (const uint8_t*)header, count))
    sendAll(client, (const uint8_t*)body, strlen(body));
}

bool jpegResponse(WiFiClient& client, camera_fb_t* frame) {
  char header[384];
  const int count = snprintf(header, sizeof(header),
      "HTTP/1.1 200 OK\r\nContent-Type: image/jpeg\r\nCache-Control: no-store\r\n"
      "Content-Length: %u\r\nX-Camera-Request-Us: %lld\r\nX-Camera-Frame-Us: %lld\r\n"
      "X-Camera-Frame-Age-Ms: %lld\r\nX-Camera-Discarded-Frames: %u\r\n"
      "Connection: close\r\n\r\n", (unsigned)frame->len,
      (long long)lastFrameRequestUs, (long long)frameTimestampUs(frame),
      (long long)((esp_timer_get_time() - frameTimestampUs(frame)) / 1000),
      lastDiscardedFrames);
  return count > 0 && count < (int)sizeof(header) &&
      sendAll(client, (const uint8_t*)header, count) && sendAll(client, frame->buf, frame->len);
}

void handleClient() {
  WiFiClient client = server.available();
  if (!client) return;
  client.setNoDelay(true);
  CameraSafety::RequestParser request;
  const uint32_t started = millis();
  while (client.connected() && request.state() == CameraSafety::RequestParser::State::Reading &&
         !CameraSafety::elapsed(millis(), started, CameraSafety::HEADER_TIMEOUT_MS)) {
    if (client.available()) request.feed((char)client.read());
    else delay(2);
  }
  if (request.state() != CameraSafety::RequestParser::State::Complete) {
    const int status = request.state() == CameraSafety::RequestParser::State::TooLarge ? 431 :
        request.state() == CameraSafety::RequestParser::State::BadRequest ? 400 : 408;
    jsonResponse(client, status, R"({"error":"invalid_or_incomplete_request"})");
    client.stop();
    return;
  }
  if (!request.isGet()) {
    jsonResponse(client, 405, R"({"error":"get_required"})");
    client.stop();
    return;
  }
  const bool authorized = request.authorized(CAMERA_NODE_KEY);

  if (strcmp(request.path(), "/health") == 0) {
    char body[768];
    snprintf(body, sizeof(body),
             "{\"camera\":\"esp32-cam\",\"ov2640_ready\":%s,\"role\":\"visual_verifier\","
             "\"initialized\":%s,\"last_capture_ok\":%s,"
             "\"firmware\":\"%s\",\"profile\":\"%s\",\"jpeg_quality\":%d,"
             "\"buffers\":%u,\"xclk_hz\":%u,"
             "\"recoveries\":%lu,\"uptime_ms\":%lu,\"flash_enabled\":%s,"
             "\"power_guard\":%s,\"camera_lock_ready\":%s,"
             "\"heartbeat_status\":%d,\"heartbeat_age_ms\":%ld,"
             "\"free_heap\":%u,\"largest_heap_block\":%u,\"min_free_heap\":%u,"
             "\"heartbeat_stack_free\":%u}",
             cameraOperational() ? "true" : "false",
             cameraReady ? "true" : "false",
             captureAttempted ? (lastCaptureOk ? "true" : "false") : "null",
             CAMERA_FIRMWARE_VERSION,
             cameraConfig.frame_size == FRAMESIZE_VGA ? "vga" : "qvga",
             cameraConfig.jpeg_quality,
             (unsigned)cameraConfig.fb_count, (unsigned)cameraConfig.xclk_freq_hz,
             (unsigned long)cameraRecoveryAttempts.load(), (unsigned long)millis(),
             CAMERA_FLASH_ENABLED && flashCutoffTimer ? "true" : "false",
             powerGuardLatched ? "true" : "false", networkMutex ? "true" : "false",
             lastHeartbeatStatus.load(), lastHeartbeatAt.load() ?
               (long)(uint32_t)(millis() - lastHeartbeatAt.load()) : -1L,
             (unsigned)ESP.getFreeHeap(), (unsigned)ESP.getMaxAllocHeap(),
             (unsigned)ESP.getMinFreeHeap(), (unsigned)heartbeatStackFree.load());
    jsonResponse(client, 200, body);
  } else if (strcmp(request.path(), "/capture") == 0) {
    if (!authorized) {
      jsonResponse(client, 401, "{\"error\":\"camera_key_required\"}");
      client.stop();
      return;
    }
    CameraLock cameraLock(50);
    if (!cameraLock) {
      jsonResponse(client, 503, R"({"error":"camera_busy"})");
      client.stop();
      return;
    }

    const unsigned long captureStarted = millis();
    camera_fb_t* frame = cameraReady ? captureWithFlash() : nullptr;
    if (!frame && recoverCamera()) frame = captureWithFlash();
    const unsigned long captureMs = millis() - captureStarted;
    if (!frame) {
      jsonResponse(client, 503, "{\"error\":\"capture_failed\"}");
    } else {
      FrameGuard ownedFrame(frame);
      const unsigned long sendStarted = millis();
      const bool sent = jpegResponse(client, frame);
      Serial.printf("CAMERA_CAPTURE: capture_ms=%lu send_ms=%lu bytes=%u sent=%s\n",
                    captureMs, millis() - sendStarted,
                    (unsigned)frame->len, sent ? "complete" : "incomplete");
    }
  } else if (strcmp(request.path(), "/preview") == 0) {
    if (!authorized) {
      jsonResponse(client, 401, R"({"error":"camera_key_required"})");
      client.stop();
      return;
    }
    CameraLock cameraLock(50);
    if (!cameraLock) {
      jsonResponse(client, 503, R"({"error":"camera_busy"})");
      client.stop();
      return;
    }

    camera_fb_t* frame = cameraReady ? capturePreview() : nullptr;
    if (!frame && recoverCamera()) frame = capturePreview();
    if (!frame) {
      jsonResponse(client, 503, R"({"error":"preview_failed"})");
    } else {
      FrameGuard ownedFrame(frame);
      const bool sent = jpegResponse(client, frame);
      Serial.printf("CAMERA_PREVIEW: bytes=%u sent=%s\n",
                    (unsigned)frame->len, sent ? "complete" : "incomplete");
    }
  } else {
    jsonResponse(client, 404, "{\"error\":\"not_found\"}");
  }
  // Give the TCP stack a short opportunity to drain the final JPEG bytes.
  delay(40);
  client.stop();
}

void setup() {
  Serial.begin(115200);
  // Leave the brownout detector enabled. A weak supply must not be hidden
  // behind repeated camera/flash/Wi-Fi starts or unconditional ESP.restart().
  const esp_reset_reason_t resetReason = esp_reset_reason();
  constexpr uint32_t BOOT_MAGIC = 0x50574341;
  if (cameraBootMagic != BOOT_MAGIC || resetReason == ESP_RST_POWERON) {
    cameraBootMagic = BOOT_MAGIC;
    cameraRapidBoots = 0;
    cameraPowerLatch = 0;
  }
  if (cameraRapidBoots < 4) ++cameraRapidBoots;
  if (resetReason == ESP_RST_BROWNOUT || cameraRapidBoots > 3) cameraPowerLatch = 1;
  powerGuardLatched = cameraPowerLatch != 0;
  pinMode(FLASH_LED_PIN, OUTPUT);
  digitalWrite(FLASH_LED_PIN, LOW);
  Serial.printf("CAMERA_BOOT: reset_reason=%u rapid_boots=%lu power_guard=%s\n",
                (unsigned)resetReason, (unsigned long)cameraRapidBoots,
                powerGuardLatched ? "latched" : "clear");
  if (powerGuardLatched) {
    pinMode(32, OUTPUT);
    digitalWrite(32, HIGH); // AI-Thinker OV2640 power-down pin, NOT a supply pin.
    Serial.println("CAMERA POWER GUARD: camera/Wi-Fi/flash disabled. Check supply and wiring, then fully power off/on.");
    return;
  }
  delay(750); // Camera and radio loads are started sequentially, not together.
  networkMutex = xSemaphoreCreateMutex();
#if CAMERA_FLASH_ENABLED
  const esp_timer_create_args_t flashTimerArgs = {flashCutoff, nullptr, ESP_TIMER_TASK, "flash-cutoff", false};
  if (esp_timer_create(&flashTimerArgs, &flashCutoffTimer) != ESP_OK) {
    flashCutoffTimer = nullptr;
    Serial.println("CAMERA_FLASH: timer unavailable; flash remains OFF.");
  }
#endif
  cameraConfig = makeCameraConfig();
  recoveryLimiter.initialAttempt(millis());
  const esp_err_t initResult = networkMutex ? esp_camera_init(&cameraConfig) : ESP_ERR_NO_MEM;
  cameraReady = (initResult == ESP_OK);
  Serial.printf("CAMERA_INIT: code=0x%x\n", (unsigned)initResult);
  if (cameraReady) {
    sensor_t* sensor = esp_camera_sensor_get();
    if (sensor) Serial.printf("CAMERA_SENSOR: pid=0x%x\n", (unsigned)sensor->id.PID);
  }
  if (!networkMutex) Serial.println("CAMERA FAULT: capture lock unavailable; snapshots disabled safely.");

  delay(250);
  WiFi.persistent(false); // Do not rewrite saved credentials on each boot.
  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  if (xTaskCreate(heartbeatTask, "camera-heartbeat", 8192, nullptr, 1, nullptr) != pdPASS)
    Serial.println("HEARTBEAT FAULT: task could not start.");
#if CAMERA_DIRECT_UPLOAD_ENABLED
  if (networkMutex && xTaskCreate(directUploadTask, "camera-upload", 8192, nullptr, 1, nullptr) != pdPASS)
    Serial.println("CAMERA UPLOAD FAULT: task could not start.");
#endif

  Serial.println();
  Serial.println("ESP32-CAM CAMERA NODE");
  Serial.printf("Firmware: %s\n", CAMERA_FIRMWARE_VERSION);
  Serial.println(cameraReady ? "OV2640 initialized." :
                              "OV2640 unavailable; check the ribbon cable and power.");
  Serial.printf("Capture profile: %s JPEG (quality=%d, buffers=%d, PSRAM=%s)\n",
                cameraConfig.frame_size == FRAMESIZE_VGA ? "640x480 VGA" : "320x240 QVGA",
                cameraConfig.jpeg_quality, cameraConfig.fb_count,
                psramFound() ? "yes" : "no");
  Serial.println("Endpoints: GET /health | GET /preview | GET /capture");
  Serial.printf("Flash: %s | no OTA/storage erase | bounded requests and recovery\n",
                CAMERA_FLASH_ENABLED && flashCutoffTimer ? "timer-limited" : "OFF (new-board default)");
}

void loop() {
  if (powerGuardLatched) {
    delay(100);
    return;
  }
  if (millis() >= 60000) cameraRapidBoots = 0;
  const bool wifiConnected = WiFi.status() == WL_CONNECTED;
  if (wifiConnected && !serverStarted &&
      (uint32_t)(millis() - lastServerStartAttemptAt) >= 1000) {
    lastServerStartAttemptAt = millis();
    server.end();
    server.begin();
    serverStarted = (bool) server;
    Serial.printf("CAMERA_SERVER: listening=%s address=%s:80\n",
                  serverStarted ? "yes" : "no", WiFi.localIP().toString().c_str());
  } else if (!wifiConnected && serverStarted) {
    server.end();
    serverStarted = false;
    Serial.println("CAMERA_SERVER: Wi-Fi disconnected, listener closed");
  }
  if (serverStarted) handleClient();
  static uint32_t lastReport = 0;
  if (millis() - lastReport >= 5000) {
    lastReport = millis();
    if (WiFi.status() == WL_CONNECTED) {
      Serial.printf("CAMERA_NODE: Wi-Fi ready at %s rssi=%d listener=%s\n",
                    WiFi.localIP().toString().c_str(), WiFi.RSSI(),
                    serverStarted ? "yes" : "no");
    } else {
      Serial.println("CAMERA_NODE: waiting for configured Wi-Fi");
    }
  }
  delay(2);
}
