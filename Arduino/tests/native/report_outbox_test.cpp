#include <cassert>
#include <iostream>
#include "report_outbox.h"
static String report(uint32_t seq){return "SOS|NODE_2-"+String(seq)+"|NODE_2|1793145600|R|Collision|NA|0|12|NODE_2|Bridge|signed";}
int main(){
  Preferences prefs; prefs.begin("outbox-test",false); ReportOutbox queue;
  for(uint32_t n=1;n<=16;++n)assert(queue.add(prefs,report(n)));
  assert(queue.full());assert(!queue.add(prefs,report(17)));assert(queue.count()==16);
  assert(queue.nextPacket()==report(1));
  assert(queue.receipt(prefs,"NODE_2-1"));
  assert(queue.count()==16 && queue.nextPacket()==report(2)); // no admin ACK or clock advance
  ReportOutbox reopened;reopened.restore(prefs);assert(reopened.nextPacket()==report(2));
  assert(reopened.isReceived("NODE_2-1"));
  assert(!reopened.full());assert(reopened.add(prefs,report(17))); // no admin response needed for capacity either
  assert(reopened.find("NODE_2-1")<0);assert(reopened.acknowledge(prefs,"NODE_2-1"));
  assert(reopened.nextPacket()==report(2)); // reused slots never reorder reports
  Preferences::failWrites=true;
  assert(!reopened.receipt(prefs,"NODE_2-2"));assert(!reopened.isReceived("NODE_2-2"));
  assert(!reopened.acknowledge(prefs,"NODE_2-3"));assert(reopened.find("NODE_2-3")>=0);
  Preferences::failWrites=false;
  for(uint32_t n=2;n<=17;++n){assert(reopened.nextPacket()==report(n));assert(reopened.receipt(prefs,"NODE_2-"+String(n)));}
  assert(!reopened.nextPacket().length());assert(reopened.count()==16);
  assert(reopened.acknowledge(prefs,"NODE_2-10"));
  Preferences::failWrites=true;assert(!reopened.add(prefs,report(18)));Preferences::failWrites=false;
  ReportOutbox afterFailure;afterFailure.restore(prefs);assert(afterFailure.count()==15 && afterFailure.find("NODE_2-18")<0);
  for(uint32_t n=18;n<=100;++n){assert(!afterFailure.full());assert(afterFailure.add(prefs,report(n)));assert(afterFailure.receipt(prefs,"NODE_2-"+String(n)));}
  assert(afterFailure.count()==16); // bounded cache, continued submission without any admin ACK
  std::cout<<"PASS: 16 immediate reports, FIFO delivery after receipt without admin wait, persistent recovery, slot reuse, independent ACKs and flash failures.\n";
}
