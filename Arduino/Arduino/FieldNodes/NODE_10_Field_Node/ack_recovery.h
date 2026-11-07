#pragma once
#include "mesh_security.h"

// Read-only response recovery. Unlike SOS, this never creates an incident or
// relaxes the gateway's durable sequence/replay rules for report submission.
namespace AckRecovery {
inline String body(const String& packet) {
  return "ACKREQ2|" + meshField(packet, 1) + "|" + meshField(packet, 2) + "|" + meshField(packet, 3);
}
inline bool shape(const String& packet) {
  if (meshField(packet, 0) != "ACKREQ") return false;
  const String origin = meshField(packet, 2), id = meshField(packet, 1), nonce = meshField(packet, 3);
  const int node = meshNodeNumber(origin);
  if (node < 2 || !id.startsWith(origin + "-") || !nonce.length() || nonce.length() > 24) return false;
  const String digits = id.substring(origin.length() + 1);
  uint64_t seq = 0;
  if (!digits.length() || digits.length() > 10) return false;
  for (size_t i = 0; i < digits.length(); ++i) {
    if (digits[i] < '0' || digits[i] > '9') return false;
    seq = seq * 10 + digits[i] - '0';
  }
  if (!seq || seq > UINT32_MAX || String((uint32_t)seq) != digits) return false;
  for (size_t i = 0; i < nonce.length(); ++i) {
    const char c = nonce[i];
    if (!((c >= 'a' && c <= 'f') || (c >= '0' && c <= '9') || c == '-')) return false;
  }
  return true;
}
inline bool verify(const String& packet) {
  if (!shape(packet)) return false;
  const uint8_t* key = meshOriginKey(meshNodeNumber(meshField(packet, 2)));
  return key && meshEqual(meshField(packet, 6), meshTag(key, body(packet)));
}
inline String request(const String& id, int node, const String& nonce) {
  const String origin = "NODE_" + String(node);
  const String packet = "ACKREQ|" + id + "|" + origin + "|" + nonce + "|12|" + origin;
  if (!shape(packet)) return "";
  const uint8_t* key = meshOriginKey(node);
  return key ? packet + "|" + meshTag(key, body(packet)) : String("");
}
}
