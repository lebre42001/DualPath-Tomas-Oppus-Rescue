#pragma once
#include <Arduino.h>
#include <Preferences.h>
#include <mbedtls/md.h>
#include <mbedtls/base64.h>
#if defined(MESH_TEST_KEYS_HEADER)
#include MESH_TEST_KEYS_HEADER
#elif __has_include("mesh_secrets.h")
#include "mesh_secrets.h"
#else
#error "Run Arduino/provision_mesh_security.ps1 locally before compiling. Never commit mesh_secrets.h."
#endif

// Group authentication protects every radio frame. Separate origin keys protect
// SOS content and final ACKs even from a compromised relay with the group key.
// These are HMAC authentication tags, not encryption or public-key signatures.
static String meshTag(const uint8_t* key, const uint8_t* data, size_t length) {
  uint8_t digest[32];
  if (mbedtls_md_hmac(mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), key, 32, data, length, digest)) return "";
  unsigned char encoded[25]; size_t used = 0;
  if (mbedtls_base64_encode(encoded, sizeof(encoded), &used, digest, 16)) return "";
  String tag; for (size_t i = 0; i < used; ++i) if (encoded[i] != '=') tag += (char)encoded[i];
  tag.replace("+", "-"); tag.replace("/", "_"); return tag;
}
static String meshTag(const uint8_t* key, const String& value) {
  return meshTag(key, (const uint8_t*)value.c_str(), value.length());
}
static bool meshEqual(const String& a, const String& b) {
  if (a.length() != 22 || b.length() != 22) return false;
  uint8_t diff = 0; for (size_t i = 0; i < 22; ++i) diff |= a[i] ^ b[i]; return diff == 0;
}
static String meshField(const String& data, int wanted) {
  int start = 0;
  for (int i = 0; i < wanted; ++i) { start = data.indexOf('|', start); if (start < 0) return ""; ++start; }
  int end = data.indexOf('|', start); return data.substring(start, end < 0 ? data.length() : end);
}
static int meshNodeNumber(const String& id) {
  for (int i = 1; i <= 10; ++i) if (id == "NODE_" + String(i)) return i; return 0;
}
static String meshOriginBody(const String& packet, bool ack) {
  // Immutable fields only: relays may change hops, TTL and route.
  String body = ack ? "ACK2" : "SOS2";
  const int sos[] = {1,2,3,4,5,6,10}; const int response[] = {1,2,3,4,5,8,9};
  for (int i = 0; i < 7; ++i) body += "|" + meshField(packet, ack ? response[i] : sos[i]);
  return body;
}
static String meshSignOrigin(const String& packet, bool ack, int origin) {
  const uint8_t* key = meshOriginKey(origin);
  if (!key) return "";
  return packet + "|" + meshTag(key, meshOriginBody(packet, ack));
}
static bool meshVerifyOrigin(const String& packet, bool ack, int origin) {
  const uint8_t* key = meshOriginKey(origin);
  return key && meshEqual(meshField(packet, ack ? 10 : 11), meshTag(key, meshOriginBody(packet, ack)));
}

static Preferences meshSecurityPrefs;
static uint64_t meshTxCounter = 0, meshTxLimit = 0;
static uint64_t meshRxCounter[11] = {};
static unsigned long meshInvalidFrames = 0;
// Actual authenticated radio transmitter, distinct from a relayed origin ID.
static uint8_t meshLastFrameSender = 0;
static const char* meshLastFrameError = "none";
static bool meshSecurityReady = false;
static bool meshSecurityBegin() {
  if (!meshSecurityPrefs.begin("mesh-auth-v2", false)) return false;
  meshTxCounter = meshSecurityPrefs.getULong64("tx-limit", 0);
  meshTxLimit = meshTxCounter;
  meshSecurityReady = true; return true;
}
// Reserve counters before using them, avoiding a flash write on every heartbeat
// while ensuring reboot never reuses a transmitted counter.
static uint64_t meshNextCounter() {
  if (!meshSecurityReady) return 0;
  if (meshTxCounter == meshTxLimit) {
    if (meshTxLimit > UINT64_MAX - 256) return 0;
    meshTxLimit += 256;
    if (meshSecurityPrefs.putULong64("tx-limit", meshTxLimit) != 8) { meshSecurityReady = false; return 0; }
  }
  return ++meshTxCounter;
}
static String meshCompact(String value) {
  value.replace("~", "~~"); value.replace("NODE_", "~N"); return value;
}
static String meshExpand(const String& value) {
  String result;
  for (size_t i = 0; i < value.length(); ++i) {
    if (value[i] != '~') { result += value[i]; continue; }
    if (++i == value.length()) return "";
    if (value[i] == '~') result += '~'; else if (value[i] == 'N') result += "NODE_"; else return "";
  }
  return result;
}
static bool meshReportFits(const String& message, const String& landmark, const String& battery) {
  String route;
  for (int n = 1; n <= 10; ++n) route += (n == 1 ? "" : ">") + String("NODE_") + String(n);
  const String worst = "SOS|NODE_10-4294967295|NODE_10|9999999999|R|" + message + "|" + battery +
    "|10|12|" + route + "|" + landmark + "|AAAAAAAAAAAAAAAAAAAAAA";
  return meshCompact(worst).length() + 34 <= 255;
}
static bool meshWriteFrame(const String& payload, bool async = false) {
  const String body = meshCompact(payload);
  // 12-byte authenticated header + 22-byte base64url tag; SX127x max = 255.
  if (body.length() == 0 || body.length() + 34 > 255) return false;
  const uint64_t counter = meshNextCounter(); if (!counter) return false;
  uint8_t frame[255]; frame[0] = 'A'; frame[1] = 'R'; frame[2] = 2; frame[3] = MESH_THIS_NODE;
  for (int i = 0; i < 8; ++i) frame[4+i] = (uint8_t)(counter >> (56 - i*8));
  memcpy(frame + 12, body.c_str(), body.length());
  const size_t length = 12 + body.length(); const String tag = meshTag(MESH_GROUP_KEY, frame, length);
  if (tag.length() != 22) return false;
  memcpy(frame + length, tag.c_str(), 22);
  LoRa.idle(); LoRa.beginPacket(); LoRa.write(frame, length + 22);
  const bool sent = LoRa.endPacket(async) == 1;
  if (!async || !sent) LoRa.receive();
  return sent;
}
static String meshReadFrame(int packetSize) {
  meshLastFrameSender = 0;
  meshLastFrameError = "none";
  uint8_t frame[255]; int used = 0;
  while (LoRa.available()) { int value = LoRa.read(); if (used < 255) frame[used++] = (uint8_t)value; }
  if (!meshSecurityReady || packetSize != used || used < 35 || frame[0] != 'A' || frame[1] != 'R' ||
      frame[2] != 2 || frame[3] < 1 || frame[3] > 10) { meshLastFrameError = "invalid frame/version/length"; ++meshInvalidFrames; return ""; }
  String received; for (int i = used-22; i < used; ++i) received += (char)frame[i];
  if (!meshEqual(received, meshTag(MESH_GROUP_KEY, frame, used-22))) { meshLastFrameError = "authentication failed (check matching mesh keys)"; ++meshInvalidFrames; return ""; }
  uint64_t counter = 0; for (int i = 0; i < 8; ++i) counter = (counter << 8) | frame[4+i];
  if (!counter || counter <= meshRxCounter[frame[3]]) { meshLastFrameError = "replayed counter or duplicate node identity"; ++meshInvalidFrames; return ""; }
  String body; for (int i = 12; i < used-22; ++i) body += (char)frame[i];
  const String payload = meshExpand(body); if (!payload.length()) { meshLastFrameError = "invalid compact payload"; ++meshInvalidFrames; return ""; }
  meshRxCounter[frame[3]] = counter;
  meshLastFrameSender = frame[3];
  return payload;
}

// Durable origin anti-replay at the gateway. Exact current SOS retries remain
// valid and reach Laravel's existing idempotency logic; modified/older IDs fail.
static bool meshAcceptSos(const String& packet, bool remember) {
  int origin = meshNodeNumber(meshField(packet, 2));
  if (!origin || !meshVerifyOrigin(packet, false, origin)) return false;
  const String id = meshField(packet, 1), prefix = "NODE_" + String(origin) + "-";
  if (!id.startsWith(prefix)) return false;
  const String digits = id.substring(prefix.length());
  uint64_t seq = 0;
  if (!digits.length() || digits.length() > 10) return false;
  for (size_t i = 0; i < digits.length(); ++i) {
    if (digits[i] < '0' || digits[i] > '9') return false;
    seq = seq * 10 + digits[i] - '0';
  }
  if (!seq || seq > UINT32_MAX || String((uint32_t)seq) != digits) return false;
  const String seqKey = "s" + String(origin), tagKey = "t" + String(origin);
  const uint64_t highest = meshSecurityPrefs.getULong64(seqKey.c_str(), 0);
  const String tag = meshField(packet, 11);
  if (seq < highest || (seq == highest && !meshEqual(tag, meshSecurityPrefs.getString(tagKey.c_str(), "")))) return false;
  if (remember && seq > highest) {
    if (meshSecurityPrefs.putString(tagKey.c_str(), tag) == 0 ||
        meshSecurityPrefs.putULong64(seqKey.c_str(), seq) != 8) return false;
  }
  return true;
}
