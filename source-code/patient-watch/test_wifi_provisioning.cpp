#include "src/wifi_provisioning_policy.h"
#include <cassert>
#include <iostream>

static void freshOfflineGrace() {
  const uint32_t started = 1234;
  WifiProvisioningPolicy policy(started);
  assert(!policy.shouldOpenPortal(started + 59999));
  assert(policy.shouldOpenPortal(started + 60000));
}

static void absolutePortalWindow() {
  const uint32_t opened = 60000;
  const uint32_t deadline = opened + WifiProvisioningPolicy::PORTAL_WINDOW_MS;
  WifiProvisioningPolicy policy(0);
  policy.portalOpened(opened);
  assert(!policy.shouldOpenPortal(120000));
  assert(!policy.shouldReconnect(120000));
  assert(!policy.portalExpired(deadline - 1));
  // Connection observations do not extend an already-open portal window.
  policy.observeConnection(true, deadline - 1);
  assert(policy.portalExpired(deadline));
  policy.observeConnection(false, deadline + 1);
  assert(policy.portalExpired(deadline + 1));
}

static void closedPortalCooldown() {
  WifiProvisioningPolicy policy(0);
  policy.portalOpened(60000);
  policy.portalClosed(240000);
  assert(!policy.portalExpired(240000));
  assert(!policy.shouldOpenPortal(299999));
  assert(policy.shouldOpenPortal(300000));
  assert(!policy.shouldReconnect(269999));
  assert(policy.shouldReconnect(270000));
}

static void connectedSuppressesAutomaticSetup() {
  WifiProvisioningPolicy policy(0);
  policy.observeConnection(true, 10000);
  assert(!policy.shouldOpenPortal(500000));
  assert(!policy.shouldReconnect(500000));
  // The grace period begins at the observed disconnect, not at boot.
  policy.observeConnection(false, 500000);
  assert(!policy.shouldOpenPortal(559999));
  assert(policy.shouldOpenPortal(560000));
}

static void reconnectCadence() {
  WifiProvisioningPolicy policy(100);
  assert(!policy.shouldReconnect(30099));
  assert(policy.shouldReconnect(30100));
  policy.lastReconnect = 30100;
  assert(!policy.shouldReconnect(60099));
  assert(policy.shouldReconnect(60100));
  policy.portalOpened(60100);
  assert(!policy.shouldReconnect(900000));
}

static void clocksCanWrap() {
  const uint32_t started = UINT32_MAX - 10000;
  WifiProvisioningPolicy policy(started);
  assert(!policy.shouldReconnect(uint32_t(started + 29999)));
  assert(policy.shouldReconnect(uint32_t(started + 30000)));
  assert(!policy.shouldOpenPortal(uint32_t(started + 59999)));
  assert(policy.shouldOpenPortal(uint32_t(started + 60000)));

  policy.portalOpened(started);
  assert(!policy.portalExpired(uint32_t(started + WifiProvisioningPolicy::PORTAL_WINDOW_MS - 1)));
  assert(policy.portalExpired(uint32_t(started + WifiProvisioningPolicy::PORTAL_WINDOW_MS)));

  policy.portalClosed(started);
  assert(!policy.shouldReconnect(uint32_t(started + 29999)));
  assert(policy.shouldReconnect(uint32_t(started + 30000)));
  assert(!policy.shouldOpenPortal(uint32_t(started + 59999)));
  assert(policy.shouldOpenPortal(uint32_t(started + 60000)));

  policy.observeConnection(true, uint32_t(started + 20000));
  policy.observeConnection(false, uint32_t(started + 25000));
  assert(!policy.shouldOpenPortal(uint32_t(started + 84999)));
  assert(policy.shouldOpenPortal(uint32_t(started + 85000)));
}

int main() {
  assert(WifiProvisioningPolicy::PORTAL_WINDOW_MS == 600000);
  freshOfflineGrace();
  absolutePortalWindow();
  closedPortalCooldown();
  connectedSuppressesAutomaticSetup();
  reconnectCadence();
  clocksCanWrap();
  std::cout << "PASS: Wi-Fi grace, portal deadline, cooldown, connectivity, reconnect, clock wrap\n";
}
