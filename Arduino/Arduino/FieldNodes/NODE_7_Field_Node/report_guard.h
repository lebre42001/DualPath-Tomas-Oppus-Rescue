#pragma once
#include <Arduino.h>

// New incidents have no timed cooldown. One-use, body-bound confirmation,
// authenticated radio frames and durable queue capacity remain enforced.
static uint32_t reportBlocked = 0, reportLastBlockedAt = 0;
struct ReportConfirmation { uint32_t address = 0, at = 0; String digest, token; };
static ReportConfirmation reportConfirmations[8];
static String reportConfirmationBody(String message, String landmark, String type, String created) {
  message.trim(); landmark.trim(); created.trim();
  return "CONFIRM2|" + type + "|" + created + "|" + message + "|" + landmark;
}
static String reportPrepare(uint32_t address, const String& body) {
  ReportConfirmation* slot = nullptr;
  for (auto& confirmation : reportConfirmations) {
    if (confirmation.address == address) { slot = &confirmation; break; }
    if (!confirmation.token.length() || millis() - confirmation.at >= 120000) slot = &confirmation;
  }
  if (!slot) return "";
  slot->address = address; slot->at = millis();
  slot->digest = meshTag(meshOriginKey(MESH_THIS_NODE), body);
  slot->token = meshTag(meshOriginKey(MESH_THIS_NODE), String(esp_random()) + "|" + String(esp_random()) + "|" + String(esp_random()) + "|" + String(esp_random()));
  return slot->token;
}
static bool reportConsumeConfirmation(uint32_t address, const String& body, const String& token) {
  for (auto& confirmation : reportConfirmations) if (confirmation.address == address &&
      millis() - confirmation.at < 120000 && meshEqual(confirmation.token, token) &&
      meshEqual(confirmation.digest, meshTag(meshOriginKey(MESH_THIS_NODE), body))) {
    confirmation.token = ""; return true;
  }
  return false;
}
static uint32_t reportRetrySeconds(uint32_t, uint32_t) { return 0; }
static bool reportGuardAccept(uint32_t, uint32_t) { return true; }
static void reportRecordBlocked() { if (reportBlocked != UINT32_MAX) ++reportBlocked; reportLastBlockedAt = millis(); }
static String reportGuardStatus(uint32_t address) {
  return "\"retry_after_seconds\":" + String(reportRetrySeconds(address, millis())) +
    ",\"blocked_submissions\":" + String(reportBlocked) +
    ",\"radio_rejections\":" + String(meshInvalidFrames) + ",\"radio_auth\":\"hmac-sha256-v2\",";
}
static String reportGuardHeartbeat() {
  // Boot ID makes counter resets explicit; no personal IP addresses leave node.
  static String boot = String(esp_random(), HEX);
  return "|" + String(reportBlocked) + "|" + String(meshInvalidFrames) + "|" + boot;
}
