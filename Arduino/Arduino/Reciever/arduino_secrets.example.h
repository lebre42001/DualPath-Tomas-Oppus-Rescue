#pragma once

// Copy this file to arduino_secrets.h before uploading the receiver sketch.
// Keep arduino_secrets.h private because it contains WiFi and API credentials.
const char* WIFI_SSID = "YOUR_WIFI_NAME";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

// Local Laravel example:
// const char* STITCH_LORA_API_URL = "http://192.168.1.5:8000/api/v1/lora/alerts";
//
// Online Render example:
const char* STITCH_LORA_API_URL = "https://stitch-web-demo.onrender.com/api/v1/lora/alerts";

// Must match LORA_INGEST_TOKEN in Laravel .env or Render environment variables.
const char* STITCH_LORA_TOKEN = "replace-with-the-same-token-from-laravel";
