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
// Keep library and direct-register transactions at the same conservative speed.
constexpr uint32_t spiFrequency = 1000000;
constexpr const char* profile = AKSYRES_RADIO_PROFILE == 1 ? "SF9 PAIR TEST ONLY" : "SF7 mesh baseline";
constexpr const char* revision = "mesh-cad-standby-20261113";

template<class Radio, class Registers> bool configure(Radio& radio, Registers& registers) {
  // LoRa 0.8.0 setSpreadingFactor/setSignalBandwidth call setLdoFlag(),
  // which divides by a value read over SPI. A corrupt/unsupported bandwidth
  // can become a zero divisor. Write these fixed profile values directly;
  // never calculate symbol duration from untrusted register readings.
  if (registers.readRegister(0x42) != 0x12 ||
      (registers.readRegister(0x01) & 0x87) != 0x81) return false;
  const uint8_t config1 = 0x70 | ((codingDenominator - 4) << 1); // 125 kHz, explicit header
  const uint8_t config2 = spreadingFactor << 4; // CRC off, preserve deployed framing
  constexpr bool lowDataRate = ((1UL << spreadingFactor) * 1000UL > bandwidth * 16UL);
  const uint8_t config3 = 0x04 | (lowDataRate ? 0x08 : 0); // automatic gain
  registers.writeRegister(0x1d, config1);
  registers.writeRegister(0x1e, config2);
  registers.writeRegister(0x26, config3);
  registers.writeRegister(0x31, 0xc3); // SF7/SF9 detection settings, SX127x datasheet
  registers.writeRegister(0x37, 0x0a);
  radio.setSyncWord(syncWord);
  radio.setTxPower(txPower);
  radio.setGain(0);
  radio.setPreambleLength(8);
  // Two bounded checks catch stuck/floating reads and ignored register writes.
  // No radio queue or receive loop starts unless the modem confirms the profile.
  for (int check = 0; check < 2; ++check) {
    if (registers.readRegister(0x42) != 0x12 ||
        (registers.readRegister(0x01) & 0x87) != 0x81 ||
        registers.readRegister(0x1d) != config1 ||
        registers.readRegister(0x1e) != config2 ||
        (registers.readRegister(0x26) & 0x0c) != config3 ||
        registers.readRegister(0x31) != 0xc3 ||
        registers.readRegister(0x37) != 0x0a ||
        registers.readRegister(0x39) != syncWord ||
        registers.readRegister(0x20) != 0 || registers.readRegister(0x21) != 8) return false;
  }
  return true;
}

template<class Registers> String startupRegisters(Registers& registers) {
  return "SPI=" + String(spiFrequency) + " Hz; version=0x" + String(registers.readRegister(0x42), HEX) +
    " (expected 0x12); mode=0x" + String(registers.readRegister(0x01), HEX) +
    "; config1=0x" + String(registers.readRegister(0x1d), HEX) +
    "; config2=0x" + String(registers.readRegister(0x1e), HEX) +
    "; config3=0x" + String(registers.readRegister(0x26), HEX);
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
