#pragma once

#include <Arduino.h>
#include <HTTPClient.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <atomic>
#include <time.h>
#include "cloud_trust.h"

// SNTP runs in the networking stack. Never wait for time in the detector loop.
inline bool cloudClockReady() {
  static std::atomic<bool> syncStarted{false};
  if (WiFi.status() != WL_CONNECTED) return false;
  if (!syncStarted.exchange(true)) {
    configTime(0, 0, "pool.ntp.org", "time.cloudflare.com", "time.google.com");
  }
  return time(nullptr) >= 1704067200; // 2024-01-01 UTC; reject the boot-time epoch.
}

inline bool cloudLocalHttpEndpoint(const String &endpoint) {
  if (!endpoint.startsWith("http://")) return false;
  int hostEnd = endpoint.indexOf('/', 7);
  String host = endpoint.substring(7, hostEnd < 0 ? endpoint.length() : hostEnd);
  if (host.indexOf('@') >= 0) return false;
  int portStart = host.indexOf(':');
  if (portStart >= 0) host.remove(portStart);
  host.toLowerCase();
  IPAddress address;
  if (!address.fromString(host)) return host.endsWith(".local");
  return address[0] == 10 ||
         (address[0] == 172 && address[1] >= 16 && address[1] <= 31) ||
         (address[0] == 192 && address[1] == 168);
}

// Own clients for the full HTTPClient lifetime. HTTPS always validates the
// certificate chain and hostname; HTTP retains the existing LAN workflow.
class CloudHttpClient {
 public:
  bool begin(const String &endpoint, int32_t connectTimeoutMs = 2500,
             uint16_t responseTimeoutMs = 4500) {
    http_.setFollowRedirects(HTTPC_DISABLE_FOLLOW_REDIRECTS);
    if (endpoint.startsWith("https://")) {
      if (!cloudClockReady()) {
        Serial.println("TLS: waiting for network time before certificate verification");
        return false;
      }
      const char *rootCa = FALL_TLS_ROOT_CA;
      if (!rootCa || !strstr(rootCa, "-----BEGIN CERTIFICATE-----")) {
        Serial.println("TLS: root certificate is not configured");
        return false;
      }
      secure_.setCACert(rootCa);
      secure_.setHandshakeTimeout(5);
      // Cloud handshakes can take longer than a local plain-HTTP connection.
      if (connectTimeoutMs < 5000) connectTimeoutMs = 5000;
      if (responseTimeoutMs < 5000) responseTimeoutMs = 5000;
      http_.setConnectTimeout(connectTimeoutMs);
      http_.setTimeout(responseTimeoutMs);
      return http_.begin(secure_, endpoint);
    }
    if (!cloudLocalHttpEndpoint(endpoint)) {
      Serial.println("HTTP: cloud endpoints require certificate-validated HTTPS");
      return false;
    }
    http_.setConnectTimeout(connectTimeoutMs);
    http_.setTimeout(responseTimeoutMs);
    return http_.begin(plain_, endpoint);
  }

  HTTPClient &http() { return http_; }

 private:
  WiFiClient plain_;
  WiFiClientSecure secure_;
  HTTPClient http_;
};
