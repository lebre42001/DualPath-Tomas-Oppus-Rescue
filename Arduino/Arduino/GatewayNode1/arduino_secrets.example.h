#pragma once

// Copy this file as arduino_secrets.h in the same folder, then replace every
// placeholder. arduino_secrets.h is excluded by the repository .gitignore.

const char* ROUTER_WIFI_SSID = "Infinix Note 40 Pro Nel";
const char* ROUTER_WIFI_PASSWORD = "12345678";

// Local Laragon example: http://192.168.1.23:8000
// Online example: https://your-official-domain.example
const char* SERVER_BASE_URL = "http://192.168.1.23:8000";

// Must exactly match LORA_INGEST_TOKEN in web_system/.env.
const char* LORA_GATEWAY_TOKEN = "SET_THE_SAME_LORA_INGEST_TOKEN";

// Password for the NODE_1_Gateway local captive portal (minimum 8 characters).
const char* LOCAL_AP_PASSWORD = "CHANGE_NODE_1_AP_PASSWORD";

// Leave empty for local HTTP. For HTTPS, paste the trusted root CA PEM.
const char* SERVER_ROOT_CA_CERT = "";
