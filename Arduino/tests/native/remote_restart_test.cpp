#include <cassert>
#include <iostream>
#include <cstdlib>
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "remote_restart.h"
#include "report_outbox.h"

using RemoteRestart::make;
String commit(Preferences& prefs,RemoteRestart::Target& target,const String& id,uint32_t now) {
  const String ready=target.receive(prefs,make("RSTQ",id,1,target.currentBoot),now);
  assert(RemoteRestart::verify(ready)&&meshField(ready,0)=="RSTREADY");
  return make("RSTCOMMIT",id,1,target.currentBoot,meshField(ready,4));
}
int main() {
  Preferences prefs;prefs.begin("node1",false);
  RemoteRestart::Target target;target.begin(prefs,"aabbcc");
  assert(!RemoteRestart::idValid("0")&&!RemoteRestart::idValid("01")&&!RemoteRestart::idValid("4294967296"));
  assert(RemoteRestart::idValid("4294967295"));
  auto request=make("RSTQ","1",1,"aabbcc");
  auto forged=request;forged.replace("RSTQ|1|","RSTQ|2|");
  assert(!target.receive(prefs,forged,100).length());
  assert(!target.receive(prefs,make("RSTQ","1",2,"aabbcc"),100).length());
  assert(!target.receive(prefs,make("RSTQ","1",1,"old"),100).length());
  const String first=commit(prefs,target,"1",100);
  assert(!target.receive(prefs,first,30100).length()&&!target.armed);
  const String fresh=commit(prefs,target,"1",30200);
  assert(first!=fresh&&!target.receive(prefs,first,30201).length());
  Preferences::failWrites=true;
  assert(!target.receive(prefs,fresh,30202).length()&&!target.armed&&prefs.getString("restart-v1","")=="");
  Preferences::failWrites=false;
  ReportOutbox outbox;assert(outbox.add(prefs,"SOS|NODE_1-9|NODE_1|1794000000|R|Injury|NA|0|12|NODE_1|Bridge|tag"));
  meshSecurityBegin();const auto counter=meshNextCounter();
  const String accepted=target.receive(prefs,fresh,30203);
  assert(RemoteRestart::verify(accepted)&&meshField(accepted,0)=="RACCEPT"&&target.armed);
  assert(!target.restartDue(999999)); // Queueing a receipt alone is not TX confirmation.
  target.transmitted(accepted,40000);
  assert(!target.restartDue(41999)&&target.restartDue(42000));
  assert(target.receive(prefs,fresh,42000)==accepted);
  assert(!target.accept(prefs,"2","aabbcc"));
  std::cout<<"PASS forged/wrong-target/stale-boot controls, fresh challenge, expiry, failed NVS and delayed radio guard.\n";

  RemoteRestart::Target rebooted;rebooted.begin(prefs,"ddeeff");
  assert(!rebooted.armed&&!rebooted.restartDue(999999));
  const String proof=rebooted.receipt();assert(RemoteRestart::verify(proof)&&meshField(proof,0)=="RBOOT");
  assert(meshField(proof,3)=="aabbcc"&&meshField(proof,4)=="ddeeff");
  assert(!rebooted.receive(prefs,make("RSTQ","2",1,"ddeeff"),50000).length()); // Preserve old pending proof.
  assert(!rebooted.receive(prefs,fresh,50000).length()||!rebooted.armed);
  assert(!rebooted.receive(prefs,make("RDONE","1",1,"aabbcc"),50000).length()&&!rebooted.complete);
  RemoteRestart::Target thirdBoot;thirdBoot.begin(prefs,"ccccdd");assert(thirdBoot.receipt()==proof);
  Preferences::failWrites=true;rebooted.receive(prefs,make("RDONE","1",1,"ddeeff"),50000);assert(!rebooted.complete);
  Preferences::failWrites=false;rebooted.receive(prefs,make("RDONE","1",1,"ddeeff"),50000);assert(rebooted.complete);
  assert(!rebooted.receipt().length()&&!rebooted.receive(prefs,fresh,50000).length());
  RemoteRestart::Target completed;completed.begin(prefs,"ffff");assert(completed.complete&&!completed.armed);
  assert(!completed.receive(prefs,make("RSTQ","1",1,"ffff"),50000).length());
  ReportOutbox restored;restored.restore(prefs);assert(restored.count()==1&&restored.find("NODE_1-9")>=0);
  assert(meshNextCounter()>counter);
  std::cout<<"PASS actual new-boot proof, confirmation retry, durable replay guard and pending SOS/security preservation.\n";

  const String wrapped=commit(prefs,completed,"2",UINT32_MAX-1000);
  assert(completed.receive(prefs,wrapped,1000).length()&&completed.armed);
  completed.acceptedByServer(1100);assert(!completed.restartDue(3099)&&completed.restartDue(3100));
  Preferences wrapStore;wrapStore.begin("wrap",false);RemoteRestart::Target wrap;wrap.begin(wrapStore,"abcd");
  assert(wrap.accept(wrapStore,"1","abcd",UINT32_MAX-3000));wrap.acceptedByServer(UINT32_MAX-1000);
  assert(!wrap.restartDue(500)&&wrap.restartDue(1000));
  assert(!wrap.restartDue(40000)&&wrap.cancelExpired(wrapStore,40000)&&!wrap.armed&&wrap.complete);
  RemoteRestart::Target cancelled;cancelled.begin(wrapStore,"cdef");assert(cancelled.complete&&!cancelled.receipt().length());
  auto relay=RemoteRestart::relay(make("RSTQ","3",2,"abcd"),"NODE_3");
  assert(RemoteRestart::verify(relay)&&meshField(relay,5)=="11");
  assert(!RemoteRestart::relay(relay,"NODE_3").length());
  const String maxRoute="NODE_1>NODE_2>NODE_3>NODE_4>NODE_5>NODE_6>NODE_7>NODE_8>NODE_9>NODE_10";
  auto largest=make("RSTCOMMIT","4294967295",10,"1234567890abcdef","1234567890abcdef");
  largest.replace("|NODE_1|","|"+maxRoute+"|");assert(meshCompact(largest).length()+34<=255);
  prefs.putString("restart-v1","bad|record");RemoteRestart::Target corrupt;corrupt.begin(prefs,"abcd");assert(!corrupt.accept(prefs,"3","abcd"));
  std::cout<<"PASS millis wrap, authenticated TTL relay, packet size and corrupt-storage fail-closed behavior.\n";
  for(int node=2;node<=10;++node) {
    Preferences store;store.begin(("target-"+String(node)).c_str(),false);
    RemoteRestart::Target device(node);device.begin(store,"aabb");
    const auto ready=device.receive(store,make("RSTQ","17",node,"aabb"),100);
    const auto ack=device.receive(store,make("RSTCOMMIT","17",node,"aabb",meshField(ready,4)),200);
    assert(RemoteRestart::verify(ack)&&meshField(ack,2)=="NODE_"+String(node));
    device.transmitted(ack,300);assert(device.restartDue(2300));
    RemoteRestart::Target again(node);again.begin(store,"ccdd");assert(meshField(again.receipt(),0)=="RBOOT");
    again.receive(store,make("RDONE","17",node,"ccdd"),500);assert(again.complete);
    assert(!again.receive(store,make("RSTQ","17",node,"ccdd"),600).length()&&!again.armed);
  }
  std::cout<<"PASS NODE_2 through NODE_10 targeted handshake, boot proof, confirmation and durable replay.\n";
}
