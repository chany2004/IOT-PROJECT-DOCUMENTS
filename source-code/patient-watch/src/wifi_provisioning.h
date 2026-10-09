#pragma once

// Main ESP32 only. SSID/password come from the protected phone setup portal,
// not camera_secrets.h. The camera board keeps its existing configuration.
bool startWifiProvisioning();
void requestWifiSetup();
void requestWifiForget();
void requestWifiStatus();
