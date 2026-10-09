#include "wifi_provisioning.h"
#include "wifi_provisioning_policy.h"

#include <Arduino.h>
#include <Preferences.h>
#include <WiFi.h>
#include <WiFiManager.h>
#include <atomic>

namespace {
enum class SetupRequest : uint8_t { None, Open, Forget };
std::atomic<SetupRequest> pendingRequest{SetupRequest::None};
std::atomic<bool> workerStarted{false};
std::atomic<bool> statusRequested{false};

bool reportSetupRadio(const char *expectedSsid) {
  wifi_config_t config{};
  uint8_t channel = 0;
  wifi_second_chan_t secondary = WIFI_SECOND_CHAN_NONE;
  int8_t power = 0;
  IPAddress address = WiFi.softAPIP();
  bool configured = esp_wifi_get_config(WIFI_IF_AP, &config) == ESP_OK;
  bool ready = (WiFi.getMode() & WIFI_AP) && configured &&
      address != IPAddress(0, 0, 0, 0) &&
      config.ap.ssid_len == strlen(expectedSsid) &&
      memcmp(config.ap.ssid, expectedSsid, config.ap.ssid_len) == 0 &&
      config.ap.ssid_hidden == 0 && config.ap.authmode == WIFI_AUTH_WPA2_PSK;
  bool channelKnown = esp_wifi_get_channel(&channel, &secondary) == ESP_OK;
  bool powerKnown = esp_wifi_get_max_tx_power(&power) == ESP_OK;
  // Never dump wifi_config_t: it includes passwords. Report metadata only.
  Serial.printf("WIFI_SETUP_STATUS: active=%s ip=%s channel=%d hidden=%s clients=%u tx_quarter_dbm=%d\n",
                ready ? "true" : "false", address.toString().c_str(),
                channelKnown ? (int)channel : -1,
                configured && config.ap.ssid_hidden ? "true" : "false",
                WiFi.softAPgetStationNum(), powerKnown ? (int)power : -1);
  return ready;
}

// Registered before WiFiManager's routes so hidden OTA/reset endpoints are
// actually denied, not just hidden in the menu. Reject router-side clients too.
class PortalAccessGuard : public RequestHandler {
 public:
  explicit PortalAccessGuard(WebServer &server) : server_(server) {}

  bool canHandle(HTTPMethod, String uri) override {
    IPAddress remote = server_.client().remoteIP();
    IPAddress accessPoint = WiFi.softAPIP();
    // Destination/interface matters too: the home router might use the same
    // 192.168.4.x subnet. Never accept its station-side HTTP connections.
    bool localSetupClient = server_.client().localIP() == accessPoint &&
        remote[0] == accessPoint[0] &&
        remote[1] == accessPoint[1] && remote[2] == accessPoint[2];
    return !localSetupClient || uri == "/update" || uri == "/u" ||
           uri == "/erase" || uri == "/restart" || uri == "/info";
  }

  bool handle(WebServer &server, HTTPMethod, String) override {
    server.send(403, "text/plain", "Unavailable in Wi-Fi setup mode.");
    return true;
  }

 private:
  WebServer &server_;
};

String setupPassword() {
  // Persist a different protected hotspot password for each physical board.
  // This is not the router password or the device API key. Never publish it.
  Preferences preferences;
  bool storageOpen = preferences.begin("pw-wifi", false);
  String password = storageOpen ? preferences.getString("setup-key", "") : "";
  bool valid = password.length() == 16;
  for (unsigned i = 0; valid && i < password.length(); ++i) {
    char c = password[i];
    valid = (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f');
  }
  if (!valid) {
    // RF has already been enabled, supplying entropy to the hardware RNG.
    char generated[17];
    snprintf(generated, sizeof(generated), "%08lx%08lx",
             (unsigned long)esp_random(), (unsigned long)esp_random());
    password = generated;
    if (!storageOpen || preferences.putString("setup-key", password) == 0) {
      Serial.println("WIFI_SETUP: hotspot password could not persist; changes on next boot.");
    }
  }
  if (storageOpen) preferences.end();
  return password;
}

void provisioningTask(void *) {
  // All WiFiManager calls belong to this worker, never the 100 Hz sensor loop.
  // Even its nonblocking process() may wait during scans/password validation.
  if (!WiFi.mode(WIFI_STA)) {
    Serial.println("WIFI_SETUP: radio could not start; check power and restart.");
    workerStarted.store(false);
    vTaskDelete(nullptr);
    return;
  }
  WiFi.setSleep(true);
  WiFi.setTxPower(WIFI_POWER_2dBm); // Preserve the board's existing low-current setting.
  WiFi.setAutoReconnect(true);

  char hotspot[33];
  snprintf(hotspot, sizeof(hotspot), "PatientWatch-Setup-%06lx",
           (unsigned long)((ESP.getEfuseMac() >> 24) & 0xffffff));
  const String password = setupPassword();
  WifiProvisioningPolicy policy(millis());
  bool manualPortal = false;
  bool portalRadioReady = false;

  // Lifetime is the lifetime of this task. No other task accesses this object.
  WiFiManager manager;
  manager.setDebugOutput(false);
  manager.setConfigPortalBlocking(false);
  // Use our wrap-safe absolute deadline instead of the library's extended
  // activity timer. A credential validation already in progress is bounded
  // separately by setSaveConnectTimeout.
  manager.setConfigPortalTimeout(0);
  manager.setConnectTimeout(15);
  manager.setSaveConnectTimeout(15);
  manager.setConnectRetries(1);
  manager.setWiFiAutoReconnect(true);
  manager.setWiFiAPChannel(1);
  manager.setWiFiAPHidden(false);
  manager.setAPClientCheck(false);
  manager.setWebPortalClientCheck(false);
  manager.setShowPassword(false);
  manager.setShowInfoUpdate(false);
  manager.setShowInfoErase(false);
  manager.setTitle("Patient Watch - Wi-Fi setup");
  // Do not offer OTA/restart/erase controls. Deny their routes below as well.
  const char *menu[] = {"wifi", "exit"};
  manager.setMenu(menu, 2);
  manager.setWebServerCallback([&manager]() {
    manager.server->addHandler(new PortalAccessGuard(*manager.server));
  });
  manager.setAPCallback([&](WiFiManager *) {
    policy.portalOpened(millis());
    WiFi.setAutoReconnect(false); // No old-router association during Wi-Fi scans.
    portalRadioReady = reportSetupRadio(hotspot);
    if (!portalRadioReady) {
      Serial.println("WIFI_SETUP: access point did not initialize; saved Wi-Fi retained.");
      return;
    }
    Serial.printf("\nWIFI_SETUP: hotspot=%s\n", hotspot);
    Serial.printf("WIFI_SETUP: hotspot password=%s (private; do not screenshot)\n",
                  password.c_str());
    Serial.println("WIFI_SETUP: connect your phone, then open http://192.168.4.1");
    Serial.println("WIFI_SETUP: Configure WiFi -> select 2.4 GHz Wi-Fi -> password -> Save.");
    Serial.println("WIFI_SETUP: portal closes after 10 minutes; Serial 'w' reopens it.");
  });
  manager.setSaveConfigCallback([]() {
    Serial.println("WIFI_SETUP: saved Wi-Fi connected; next boot reconnects automatically.");
  });

  Serial.println("WIFI_SETUP: trying saved Wi-Fi (no compiled SSID/password).");
  // A false result can mean the nonblocking portal started, not a fatal failure.
  manager.autoConnect(hotspot, password.c_str());

  auto openSetupPortal = [&]() {
    // AP+STA shares the old router's radio channel even when softAP requests 1.
    // Temporarily leave that router, without erasing its saved credentials, so
    // phones can discover setup on channel 1. STA returns on Save/portal close.
    manager.setWiFiAutoReconnect(false);
    WiFi.setAutoReconnect(false);
    WiFi.disconnect(false, false);
    if (!WiFi.mode(WIFI_AP)) {
      Serial.println("WIFI_SETUP: could not enter setup radio mode; saved Wi-Fi retained.");
      WiFi.setAutoReconnect(true);
      manager.setWiFiAutoReconnect(true);
      policy.portalClosed(millis());
      WiFi.begin();
      return;
    }
    manager.startConfigPortal(hotspot, password.c_str());
  };

  for (;;) {
    SetupRequest request = pendingRequest.exchange(SetupRequest::None);
    if (request != SetupRequest::None) {
      if (manager.getConfigPortalActive()) manager.stopConfigPortal();
      policy.portalClosed(millis());
      if (request == SetupRequest::Forget) {
        // Station credentials only: never erase the entire NVS or API settings.
        manager.resetSettings();
        Serial.println("WIFI_SETUP: saved router Wi-Fi cleared; API key unchanged.");
      }
      manualPortal = true;
      openSetupPortal();
    }

    if (statusRequested.exchange(false)) reportSetupRadio(hotspot);

    uint32_t now = millis();
    // Absolute deadline, independent of clients extending library timeouts.
    if (manager.getConfigPortalActive() && !portalRadioReady) {
      manager.stopConfigPortal();
      Serial.println("WIFI_SETUP: failed portal closed; retrying saved Wi-Fi.");
    } else if (manager.getConfigPortalActive() && policy.portalExpired(now)) {
      manager.stopConfigPortal();
      Serial.println("WIFI_SETUP: portal window ended; retrying saved Wi-Fi.");
    } else {
      manager.process();
    }
    now = millis();
    bool connected = WiFi.status() == WL_CONNECTED;
    policy.observeConnection(connected, now);

    if (manager.getConfigPortalActive() && connected && !manualPortal) {
      manager.stopConfigPortal();
    }
    if (policy.portalActive && !manager.getConfigPortalActive()) {
      policy.portalClosed(now);
      manualPortal = false;
      manager.setWiFiAutoReconnect(true);
      WiFi.setAutoReconnect(true);
      // Closing an offline portal must re-enable STA and resume saved credentials.
      // begin() without arguments reads the radio's saved NVS config.
      if (!connected) WiFi.begin();
    }
    if (policy.shouldOpenPortal(now)) {
      manualPortal = false;
      openSetupPortal();
    } else if (policy.shouldReconnect(now)) {
      policy.lastReconnect = now;
      WiFi.begin();
    }
    vTaskDelay(pdMS_TO_TICKS(20));
  }
}
} // namespace

bool startWifiProvisioning() {
  if (workerStarted.exchange(true)) return true;
  // Arduino loop is on core 1. Portal/connection waits run on core 0 instead.
  if (xTaskCreatePinnedToCore(provisioningTask, "wifi-setup", 8192, nullptr, 1,
                              nullptr, 0) != pdPASS) {
    workerStarted.store(false);
    return false;
  }
  return true;
}

void requestWifiSetup() {
  if (!workerStarted.load()) {
    Serial.println("WIFI_SETUP: worker unavailable; check power and restart.");
    return;
  }
  // Do not let a following 'w' overwrite an already-requested Wi-Fi reset.
  SetupRequest expected = SetupRequest::None;
  pendingRequest.compare_exchange_strong(expected, SetupRequest::Open);
}

void requestWifiForget() {
  if (!workerStarted.load()) {
    Serial.println("WIFI_SETUP: worker unavailable; check power and restart.");
    return;
  }
  pendingRequest.store(SetupRequest::Forget);
}

void requestWifiStatus() {
  if (!workerStarted.load()) {
    Serial.println("WIFI_SETUP: worker unavailable; check power and restart.");
    return;
  }
  statusRequested.store(true);
}
