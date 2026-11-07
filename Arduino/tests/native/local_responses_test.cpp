#include <cassert>
#include <iostream>
#include "local_responses.h"
#include "report_outbox.h"

static String response(int node, int seq, const String& at = "2026-11-07T10:00:00+00:00", const String& text = "Team responding", const String& path = "NODE_1") {
  return "ACK|NODE_" + String(node) + "-" + String(seq) + "|NODE_" + String(node) + "|NODE_1|ACKNOWLEDGED|" + at + "|12|" + path + "|" + text + "|Confirmed";
}
static String report(int node, int seq) {
  return "SOS|NODE_" + String(node) + "-" + String(seq) + "|NODE_" + String(node) + "|1794000000|R|Collision|NA|0|12|NODE_" + String(node) + "|Bridge|signed";
}
int main() {
  for (int node = 1; node <= 10; ++node) {
    Preferences::strings.clear();Preferences::numbers.clear();Preferences::failWrites=false;
    Preferences prefs;prefs.begin("response-test",false);LocalResponses cache(node);ReportOutbox outbox;
    assert(outbox.add(prefs,report(node,1))&&outbox.add(prefs,report(node,2)));
    assert(cache.remember(prefs,response(node,1),"1794000000"));
    // The older report is durably saved before its independent outbox slot is removed.
    assert(outbox.acknowledge(prefs,"NODE_"+String(node)+"-1"));
    assert(outbox.find("NODE_"+String(node)+"-2")>=0);
    LocalResponses reopened(node);reopened.restore(prefs);
    const int first=reopened.find("NODE_"+String(node)+"-1");assert(first>=0);
    assert(reopened.items[first].packet==response(node,1)&&reopened.items[first].createdAt=="1794000000");
    assert(reopened.remember(prefs,response(node,1,"2026-11-07T11:00:00+00:00","Report resolved"),"wrong new submit time"));
    assert(reopened.items[first].createdAt=="1794000000");
    assert(!reopened.remember(prefs,response(node,1,"2026-11-07T10:00:00+00:00","Old retry"),""));
    assert(LocalResponses::field(reopened.items[first].packet,8)=="Report resolved");
    // A changed message is a revision even if server timestamps share a second.
    assert(reopened.remember(prefs,response(node,1,"2026-11-07T11:00:00+00:00","Final correction"),""));
    Preferences::failWrites=true;
    // A different relay path is still the same response: no flash write needed.
    assert(reopened.remember(prefs,response(node,1,"2026-11-07T11:00:00+00:00","Final correction","NODE_1>NODE_3"),""));
    assert(!reopened.remember(prefs,response(node,2),"1794000001"));
    assert(outbox.find("NODE_"+String(node)+"-2")>=0&&reopened.find("NODE_"+String(node)+"-2")<0);
    Preferences::failWrites=false;
    LocalResponses beforeRetry(node);beforeRetry.restore(prefs);
    assert(LocalResponses::field(beforeRetry.items[first].packet,8)=="Final correction");
    assert(beforeRetry.remember(prefs,response(node,2),"1794000001"));
    assert(outbox.acknowledge(prefs,"NODE_"+String(node)+"-2"));
    const int foreign=node==10?1:node+1;
    assert(!beforeRetry.remember(prefs,response(foreign,3),""));
    assert(!beforeRetry.remember(prefs,"ACK|NODE_"+String(node)+"-bad|NODE_"+String(node)+"|NODE_1|ACKNOWLEDGED|now|12|NODE_1|Bad", ""));
    assert(!beforeRetry.remember(prefs,response(node,3),"bad|time"));
    assert(!LocalResponses::valid(response(node,3),0));assert(!LocalResponses::valid(response(node,3),11));
    // Restore refuses another board's persisted messages even with valid syntax.
    LocalResponses other(foreign);other.restore(prefs);assert(other.find("NODE_"+String(node)+"-1")<0);
    for(int seq=3;seq<=16;++seq)assert(beforeRetry.remember(prefs,response(node,seq),"1794000000"));
    Preferences::failWrites=true;assert(!beforeRetry.remember(prefs,response(node,17),"1794000000"));Preferences::failWrites=false;
    assert(beforeRetry.remember(prefs,response(node,17,"2026-11-07T12:00:00+00:00"),"1794000002"));
    LocalResponses full(node);full.restore(prefs);int count=0;for(const auto& item:full.items)if(item.packet.length())++count;
    assert(count==16&&full.find("NODE_"+String(node)+"-17")>=0);
  }
  // Existing gateway code can still use the no-argument constructor.
  Preferences defaults;defaults.begin("defaults",false);LocalResponses gateway;
  assert(gateway.remember(defaults,response(1,1),"1794000000"));assert(!gateway.remember(defaults,response(2,1),""));
  std::cout<<"PASS NODE_1..NODE_10: durable independent responses, immutable submit times, stale-revision rejection, same-second updates, relay deduplication, flash failures, bounded cache and owner validation.\n";
}
