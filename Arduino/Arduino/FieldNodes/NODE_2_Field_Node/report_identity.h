#pragma once
#include <Arduino.h>

// Keep the existing SOS fields, origin signature and 70-byte message budget.
// Older gateways/relays forward this signed envelope without a firmware change.
static bool reportIdentityText(const String& value, size_t limit) {
  if (!value.length() || value.length() > limit) return false;
  for (size_t i = 0; i < value.length(); ++i) {
    const uint8_t c = (uint8_t)value[i];
    if (c < 32 || c == 127 || c == '|') return false;
  }
  return true;
}

static String reportIdentityMessage(String name, String description) {
  name.trim(); description.trim();
  if (!reportIdentityText(name, 24) || !reportIdentityText(description, 70)) return "";
  const String message = "@N1:" + String(name.length()) + ":" + name + description;
  return message.length() <= 70 ? message : String("");
}
