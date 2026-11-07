#pragma once
#include <Arduino.h>
#include <Preferences.h>

// Separate, durable report slots. A server receipt releases the delivery lane;
// received reports may stay cached for their admin ACK. Only server-confirmed
// slots can be reused; an undelivered report is never overwritten.
class ReportOutbox {
 public:
  static const uint8_t Capacity = 16;
  struct Item { String packet; bool received = false; };
  Item items[Capacity];
  static String id(const String& packet) {
    const int end = packet.indexOf('|', 4);
    return end < 0 ? String("") : packet.substring(4, end);
  }
  static uint32_t sequence(const String& packet) {
    const String value = id(packet); const int dash = value.indexOf('-');
    uint32_t number = 0;
    for (size_t i = dash + 1; i < value.length(); ++i) number = number * 10 + value[i] - '0';
    return number;
  }
  String key(int slot) const { return "outbox" + String(slot); }
  int find(const String& msgId) const {
    for (int i = 0; i < Capacity; ++i) if (items[i].packet.length() && id(items[i].packet) == msgId) return i;
    return -1;
  }
  int count() const { int n = 0; for (const auto& item : items) if (item.packet.length()) ++n; return n; }
  bool full() const { for (const auto& item : items) if (!item.packet.length() || item.received) return false; return true; }
  bool isReceived(const String& msgId) const { const int i = find(msgId); return i >= 0 && items[i].received; }
  void restore(Preferences& prefs) {
    for (int i = 0; i < Capacity; ++i) {
      const String value = prefs.getString(key(i).c_str(), "");
      items[i].packet = value.length() > 2 && value.substring(2).startsWith("SOS|") ? value.substring(2) : String("");
      items[i].received = value.startsWith("1|");
    }
  }
  bool add(Preferences& prefs, const String& packet, bool received = false) {
    if (!packet.startsWith("SOS|") || !id(packet).length()) return false;
    if (find(id(packet)) >= 0) return true;
    int slot = -1;
    for (int i = 0; i < Capacity; ++i) if (!items[i].packet.length()) { slot = i; break; }
    if (slot < 0) for (int i = 0; i < Capacity; ++i) if (items[i].received &&
        (slot < 0 || sequence(items[i].packet) < sequence(items[slot].packet))) slot = i;
    if (slot >= 0) {
      const String value = String(received ? "1|" : "0|") + packet;
      if (prefs.putString(key(slot).c_str(), value) != value.length()) return false;
      items[slot].packet = packet; items[slot].received = received; return true;
    }
    return false;
  }
  bool receipt(Preferences& prefs, const String& msgId) {
    const int i = find(msgId); if (i < 0) return false;
    if (items[i].received) return true;
    const String value = "1|" + items[i].packet;
    if (prefs.putString(key(i).c_str(), value) != value.length()) return false;
    items[i].received = true; return true;
  }
  bool acknowledge(Preferences& prefs, const String& msgId) {
    const int i = find(msgId); if (i < 0) return true;
    if (!prefs.remove(key(i).c_str())) return false;
    items[i].packet = ""; items[i].received = false; return true;
  }
  String nextPacket() const {
    int oldest = -1;
    for (int i = 0; i < Capacity; ++i) if (items[i].packet.length() && !items[i].received &&
        (oldest < 0 || sequence(items[i].packet) < sequence(items[oldest].packet))) oldest = i;
    return oldest < 0 ? String("") : items[oldest].packet;
  }
};
