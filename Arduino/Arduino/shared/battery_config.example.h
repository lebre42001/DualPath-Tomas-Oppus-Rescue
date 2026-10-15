#pragma once

// Enable only after a battery voltage divider is physically wired and checked.
// Default for ESP32 Dev Module: 1S Li-ion BAT+ -> 100k -> GPIO34 -> 100k -> GND.
// Add 100 nF from GPIO34 to GND. Never connect a 4.2 V cell directly to GPIO34.
static const bool BATTERY_MONITOR_ENABLED = false;
static const int BATTERY_ADC_PIN = 34;  // ADC1 works while Wi-Fi is active.
static const float BATTERY_DIVIDER_RATIO = 2.0f;  // (Rtop + Rbottom) / Rbottom
static const float BATTERY_CALIBRATION = 1.0f;   // multimeter voltage / displayed voltage
