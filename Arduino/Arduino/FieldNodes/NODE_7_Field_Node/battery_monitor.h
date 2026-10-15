#pragma once

#include <Arduino.h>
#include "battery_config.h"

// Shared by gateway and field nodes. No guessed percentage or USB battery level.
inline String measuredBatteryVoltage() {
  if (!BATTERY_MONITOR_ENABLED) return "NA";

  static bool initialized = false;
  static uint32_t sampledAt = 0;
  static String cached = "NA";
  if (initialized && millis() - sampledAt < 10000) return cached;
  if (!initialized) {
    pinMode(BATTERY_ADC_PIN, INPUT);
    analogSetPinAttenuation(BATTERY_ADC_PIN, ADC_11db);
    initialized = true;
  }

  uint32_t totalMillivolts = 0;
  for (int sample = 0; sample < 16; sample++) {
    totalMillivolts += analogReadMilliVolts(BATTERY_ADC_PIN);
    delayMicroseconds(200);
  }
  const float pinMillivolts = totalMillivolts / 16.0f;
  const float batteryVolts = pinMillivolts * BATTERY_DIVIDER_RATIO * BATTERY_CALIBRATION / 1000.0f;
  // Reject disconnected/near-zero or saturated readings instead of inventing a level.
  cached = (pinMillivolts < 150 || pinMillivolts >= 3050 || batteryVolts <= 0)
    ? "NA" : String(batteryVolts, 2) + " V";
  sampledAt = millis();
  return cached;
}
