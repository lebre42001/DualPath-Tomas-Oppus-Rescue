#pragma once

// Copy this file as arduino_secrets.h in the same folder, then replace every
// placeholder. arduino_secrets.h is excluded by the repository .gitignore.

const char* ROUTER_WIFI_SSID = "YOUR_WIFI_NAME";
const char* ROUTER_WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

// Local Laragon example: http://192.168.1.23:8000
// Official hosted website. Do not append /api/v1/lora; the sketch adds it.
const char* SERVER_BASE_URL = "https://dualpath.bsit4bdevs.tech";

// Must exactly match LORA_INGEST_TOKEN in the hosted website's environment.
const char* LORA_GATEWAY_TOKEN = "SET_THE_SAME_LORA_INGEST_TOKEN";

// Password for the NODE_1_Gateway local captive portal (minimum 8 characters).
const char* LOCAL_AP_PASSWORD = "CHANGE_NODE_1_AP_PASSWORD";

// Public trusted root from server_root_ca.h, included by GatewayNode1.ino.
// For a local HTTP deployment only, this may be set to "".
const char* SERVER_ROOT_CA_CERT = DUALPATH_ROOT_CA;
