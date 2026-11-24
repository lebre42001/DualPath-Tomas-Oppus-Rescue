#include <cassert>
#include <iostream>
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "mesh_security.h"
#include "report_guard.h"
#include "report_identity.h"
#include "report_outbox.h"

int main() {
  assert(meshSecurityBegin());
  assert(reportIdentityMessage("  Juan  ", "  Crash  ") == "@N1:4:JuanCrash");
  assert(reportIdentityMessage("Jos\xc3\xa9 Ni\xc3\xb1o", "Crash") == "@N1:11:Jos\xc3\xa9 Ni\xc3\xb1oCrash");
  assert(reportIdentityMessage("O'Neil: Jr.", "Need help").length());
  for (const String& name : {String(""), String("  "), String("A|B"), String("A\nB"), String("A\tB"), String("A\x7f")})
    assert(!reportIdentityMessage(name, "Crash").length());
  String name, description, landmark;
  for (int i = 0; i < 24; ++i) name += 'a';
  for (int i = 0; i < 39; ++i) description += 'b';
  for (int i = 0; i < 32; ++i) landmark += 'c';
  const String message = reportIdentityMessage(name, description);
  assert(message.length() == 70);
  assert(!reportIdentityMessage(name + "x", "Crash").length());
  assert(!reportIdentityMessage(name, description + "x").length());
  assert(!reportIdentityMessage("Juan", "").length());
  assert(!reportIdentityMessage("Juan", "Crash|False").length());
  assert(meshReportFits(message, landmark, "4.20 V"));
  for (int origin = 1; origin <= 10; ++origin) {
    const String node = "NODE_" + String(origin);
    const String packet = meshSignOrigin("SOS|" + node + "-4294967295|" + node + "|1793145600|R|" +
      message + "|4.20 V|0|12|" + node + "|" + landmark, false, origin);
    assert(meshVerifyOrigin(packet, false, origin));
    // Same field reconstruction used by the unchanged old field-node relay.
    String route;
    for (int n = 1; n <= 10; ++n) route += (n == 1 ? "" : ">") + String("NODE_") + String(n);
    String relayed = "SOS";
    for (int field = 1; field <= 11; ++field) relayed += "|" +
      (field == 7 ? String("10") : field == 8 ? String("2") : field == 9 ? route : meshField(packet, field));
    assert(meshVerifyOrigin(relayed, false, origin));
    assert(meshWriteFrame(relayed) && LoRa.tx.size() <= 255);
    auto frame = LoRa.tx; LoRa.inject(frame);
    assert(meshReadFrame(frame.size()) == relayed);
    String changed = relayed; changed.replace(name, "Different reporter");
    assert(!meshVerifyOrigin(changed, false, origin));
    Preferences prefs; assert(prefs.begin(node.c_str(), false));
    ReportOutbox outbox; assert(outbox.add(prefs, packet));
    ReportOutbox restored; restored.restore(prefs);
    assert(restored.nextPacket() == packet);
  }
  fakeClock = 100;
  const String body = reportConfirmationBody(message, landmark, "ROAD_ACCIDENT", "1793145600");
  const String token = reportPrepare(1, body);
  assert(!reportConsumeConfirmation(1, reportConfirmationBody(reportIdentityMessage("Other", description), landmark, "ROAD_ACCIDENT", "1793145600"), token));
  assert(reportConsumeConfirmation(1, body, token));
  std::cout << "PASS: named reports retain old relay/auth/frame budget, UTF-8 bytes, confirmation binding and durable retry packets.\n";
}
