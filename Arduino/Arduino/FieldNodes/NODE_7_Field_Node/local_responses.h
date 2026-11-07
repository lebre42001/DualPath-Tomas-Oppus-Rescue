#pragma once
#include <Arduino.h>
#include <Preferences.h>

// A bounded display cache, separate from delivery slots and their LED state.
// The caller must successfully remember an authenticated response BEFORE it
// clears that report's outbox entry or confirms delivery to the server.
class LocalResponses {
 public:
  static const uint8_t Capacity = 16;
  struct Item { String packet, createdAt; };
  Item items[Capacity];

  explicit LocalResponses(uint8_t owner = 1) : ownerNumber(owner) {}

  static String field(const String& value, int index) {
    int start = 0;
    for (int i = 0; i < index; ++i) {
      const int end = value.indexOf('|', start);
      if (end < 0) return "";
      start = end + 1;
    }
    const int end = value.indexOf('|', start);
    return end < 0 ? value.substring(start) : value.substring(start, end);
  }
  static bool valid(const String& packet, uint8_t owner = 1) {
    if (owner < 1 || owner > 10 || field(packet, 0) != "ACK") return false;
    const String node = "NODE_" + String(owner), prefix = node + "-";
    const String id = field(packet, 1);
    if (!id.startsWith(prefix) || id.length() == prefix.length() || field(packet, 2) != node) return false;
    for (size_t i = prefix.length(); i < id.length(); ++i) if (id[i] < '0' || id[i] > '9') return false;
    return (field(packet, 4) == "ACKNOWLEDGED" || field(packet, 4) == "REJECTED") && field(packet, 5).length();
  }
  // Relay paths, TTL and transport signatures can differ for the same response.
  // Only the response's actual content defines its revision and wears flash.
  static String revision(const String& packet) {
    return field(packet, 1) + "|" + field(packet, 4) + "|" + field(packet, 5) + "|" + field(packet, 8) + "|" + field(packet, 9);
  }
  String key(int slot) const { return "localack" + String(slot); }
  int find(const String& msgId) const {
    for (int i = 0; i < Capacity; ++i) if (items[i].packet.length() && field(items[i].packet, 1) == msgId) return i;
    return -1;
  }
  void restore(Preferences& prefs) {
    for (int i = 0; i < Capacity; ++i) {
      const String value = prefs.getString(key(i).c_str(), "");
      const int end = value.indexOf('|');
      items[i] = Item();
      if (end >= 0 && valid(value.substring(end + 1), ownerNumber)) {
        items[i].createdAt = value.substring(0, end);
        items[i].packet = value.substring(end + 1);
      }
    }
  }
  bool remember(Preferences& prefs, const String& packet, const String& createdAt) {
    if (!valid(packet, ownerNumber) || createdAt.indexOf('|') >= 0) return false;
    int slot = find(field(packet, 1));
    // Server ACK timestamps are UTC ISO-8601. A delayed older packet must not
    // replace a later response, even when its radio authentication is valid.
    if (slot >= 0 && field(packet, 5) < field(items[slot].packet, 5)) return false;
    const String submitted = slot >= 0 && items[slot].createdAt.length() ? items[slot].createdAt : createdAt;
    if (slot >= 0 && revision(items[slot].packet) == revision(packet) && items[slot].createdAt == submitted) return true;
    if (slot < 0) for (int i = 0; i < Capacity; ++i) if (!items[i].packet.length()) { slot = i; break; }
    if (slot < 0) {
      slot = 0;
      for (int i = 1; i < Capacity; ++i)
        if (field(items[i].packet, 5) < field(items[slot].packet, 5)) slot = i;
    }
    // One NVS value commits both the message and its submit time together.
    // A write failure leaves the previous in-memory and persisted item intact.
    const String value = submitted + "|" + packet;
    if (prefs.putString(key(slot).c_str(), value) != value.length()) return false;
    items[slot].packet = packet; items[slot].createdAt = submitted;
    return true;
  }

 private:
  uint8_t ownerNumber;
};
