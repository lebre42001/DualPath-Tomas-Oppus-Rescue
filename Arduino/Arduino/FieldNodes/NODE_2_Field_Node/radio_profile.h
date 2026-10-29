#pragma once
#include <Arduino.h>

// 0 preserves the deployed SF7 mesh. 1 is an ISOLATED TWO-BOARD SF9 test.
// Set the same build flag on both test boards; do not mix profiles in one mesh.
#ifndef AKSYRES_RADIO_PROFILE
#define AKSYRES_RADIO_PROFILE 0
#endif
static_assert(AKSYRES_RADIO_PROFILE == 0 || AKSYRES_RADIO_PROFILE == 1,
  "AKSYRES_RADIO_PROFILE must be 0 (deployed) or 1 (isolated range test).");

namespace AksyResRadio {
constexpr long frequency = 433000000;
constexpr int syncWord = 0x34;
constexpr int txPower = 17;
constexpr long bandwidth = 125000;
constexpr int spreadingFactor = AKSYRES_RADIO_PROFILE == 1 ? 9 : 7;
constexpr int codingDenominator = AKSYRES_RADIO_PROFILE == 1 ? 6 : 5;
constexpr const char* profile = AKSYRES_RADIO_PROFILE == 1 ? "SF9 PAIR TEST ONLY" : "SF7 mesh baseline";
constexpr const char* revision = "mesh-reconnect-20261029";

template<class Radio> void configure(Radio& radio) {
  radio.setSyncWord(syncWord);
  radio.setTxPower(txPower);
  radio.setSpreadingFactor(spreadingFactor);
  radio.setSignalBandwidth(bandwidth);
  radio.setCodingRate4(codingDenominator);
  // Match the existing library defaults. No TX-power increase or CRC-format migration.
  radio.setGain(0);
  radio.setPreambleLength(8);
}

static uint32_t validPackets = 0, lastPacketAt = 0;
static int lastRssi = 0, lastSender = 0;
static float lastSnr = 0;
static long lastFrequencyError = 0;

// Call only after radio-frame authentication succeeds. RSSI is never an admission cutoff.
static void record(int rssi, float snr, long frequencyError, int sender) {
  ++validPackets; lastPacketAt = millis(); lastRssi = rssi; lastSnr = snr;
  lastFrequencyError = frequencyError; lastSender = sender;
}

static String summary() {
  return String(profile) + "; 433 MHz; BW " + String(bandwidth) + " Hz; CR 4/" +
    String(codingDenominator) + "; TX " + String(txPower) + " dBm; " + revision;
}

// A trailing comma lets both local /status handlers append their existing fields.
static String statusFields() {
  String json = "\"radio_profile\":\"" + String(profile) + "\",\"radio_revision\":\"" + revision + "\",";
  json += "\"radio_frequency_hz\":" + String(frequency) + ",\"radio_sf\":" + String(spreadingFactor) + ",";
  json += "\"radio_bandwidth_hz\":" + String(bandwidth) + ",\"radio_coding_rate\":\"4/" + String(codingDenominator) + "\",";
  json += "\"radio_tx_dbm\":" + String(txPower) + ",\"radio_valid_packets\":" + String(validPackets) + ",";
  json += "\"radio_last_sender\":" + (validPackets ? String(lastSender) : String("null")) + ",";
  json += "\"radio_last_rssi\":" + (validPackets ? String(lastRssi) : String("null")) + ",";
  json += "\"radio_last_snr\":" + (validPackets ? String(lastSnr, 2) : String("null")) + ",";
  json += "\"radio_last_frequency_error_hz\":" + (validPackets ? String(lastFrequencyError) : String("null")) + ",";
  json += "\"radio_last_age_ms\":" + (validPackets ? String(uint32_t(millis() - lastPacketAt)) : String("null")) + ",";
  return json;
}
}
