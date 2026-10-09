#pragma once

#include <stdint.h>

// Pure timing policy: no radio/HTTP work and unsigned subtraction handles millis wrap.
struct WifiProvisioningPolicy {
  static constexpr uint32_t PORTAL_WINDOW_MS = 600000; // Protected 10-minute setup window.
  static constexpr uint32_t OFFLINE_GRACE_MS = 60000;
  static constexpr uint32_t RECONNECT_INTERVAL_MS = 30000;

  bool connected = false;
  bool portalActive = false;
  uint32_t offlineSince = 0;
  uint32_t portalSince = 0;
  uint32_t lastReconnect = 0;

  explicit WifiProvisioningPolicy(uint32_t now)
      : offlineSince(now), lastReconnect(now) {}

  void observeConnection(bool online, uint32_t now) {
    if (online || connected) offlineSince = now;
    connected = online;
  }

  void portalOpened(uint32_t now) {
    portalActive = true;
    portalSince = now;
  }

  void portalClosed(uint32_t now) {
    portalActive = false;
    offlineSince = now;
    lastReconnect = now;
  }

  bool portalExpired(uint32_t now) const {
    return portalActive && uint32_t(now - portalSince) >= PORTAL_WINDOW_MS;
  }

  bool shouldOpenPortal(uint32_t now) const {
    return !connected && !portalActive &&
           uint32_t(now - offlineSince) >= OFFLINE_GRACE_MS;
  }

  bool shouldReconnect(uint32_t now) const {
    return !connected && !portalActive &&
           uint32_t(now - lastReconnect) >= RECONNECT_INTERVAL_MS;
  }
};
