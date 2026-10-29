#include <cassert>
#include <iostream>
#include "mesh_link.h"

struct Port {
  uint32_t now=0,at=0;int phase=0,cads=0;bool rx=false,cadBusy=false,cadStuck=false,txStuck=false,failStart=false,rxRace=false;
  std::vector<String> packets;std::vector<uint32_t> starts;
  uint8_t flags(){return phase==1&&!cadStuck&&MeshLink::due(now,at)?uint8_t(4|(cadBusy?1:0)|(rxRace?0x40:0)):phase==2&&!txStuck&&MeshLink::due(now,at)?8:0;}
  bool receiving(){return rx;}
  void startCad(){++cads;phase=1;at=now+3;}void endCad(){phase=0;}
  bool transmit(const String& value){if(failStart)return false;packets.push_back(value);starts.push_back(now);phase=2;at=now+50;return true;}
  void endTx(){phase=0;}uint32_t txTimeout(const String&){return 200;}
};
static std::vector<String> completed;
static void sent(const String& value){completed.push_back(value);}
using Pump=MeshLink::Pump<Port>;
static void run(Pump& pump,Port& port,uint32_t start,uint32_t duration){for(uint32_t i=0;i<duration;++i){port.now=start+i;pump.tick(port.now);}}
static String sos(int n){return "SOS|NODE_2-"+String(n)+"|NODE_2|time|R|Crash|NA|0|12|NODE_2|Bridge|tag";}
int main(){
  {Port p;Pump q(p,sent);completed.clear();q.begin(5,0);assert(q.enqueue(sos(1),0));assert(completed.empty());
   p.rx=true;run(q,p,0,500);assert(p.cads==0&&p.packets.empty());assert(q.deferred>0);
   p.rx=false;run(q,p,500,1000);assert(completed.size()==1&&q.transmitted==1&&q.depth()==0);}
  {Port p;Pump q(p);q.begin(11,0);for(int i=0;i<50;++i)assert(q.enqueue("HB|NODE_2|"+String(i),i));
   assert(q.depth()==1&&q.coalesced==49);run(q,p,50,1000);assert(p.packets.size()==1&&p.packets[0]=="HB|NODE_2|49");}
  {Port p;Pump q(p);q.begin(1,0);for(int i=0;i<16;++i)assert(q.enqueue("PING|NODE_2|"+String(i),0));
   assert(!q.enqueue("PING|NODE_2|extra",0));for(int i=1;i<=9;++i)assert(q.enqueue(sos(i),0));assert(q.depth()==24);}
  {Port p;Pump q(p);q.begin(1,0);for(int i=0;i<24;++i)assert(q.enqueue(sos(i),0));assert(!q.enqueue(sos(99),0));assert(q.depth()==24);}
  {Port p;Pump q(p,sent);completed.clear();q.begin(8,0);for(int i=0;i<9;++i)q.enqueue(sos(i),0);q.enqueue("HELLO|NODE_3",0);
   run(q,p,0,2400);assert(completed.size()>=4);bool control=false;for(int i=0;i<4;++i)if(completed[i]=="HELLO|NODE_3")control=true;assert(control);}
  {Port p;Pump q(p);q.begin(13,0);p.cadBusy=true;q.enqueue(sos(1),0);run(q,p,0,1500);assert(p.packets.empty()&&q.deferred>0);
   p.cadBusy=false;run(q,p,1500,3000);assert(q.transmitted==1);}
  {Port p;Pump q(p,sent);completed.clear();q.begin(14,0);p.cadStuck=true;q.enqueue(sos(1),0);run(q,p,0,1500);
   assert(q.cadTimeouts>0&&completed.empty()&&q.depth()==1);p.cadStuck=false;run(q,p,1500,3000);assert(completed.size()==1);}
  {Port p;Pump q(p);q.begin(25,0);p.rxRace=true;q.enqueue(sos(1),0);run(q,p,0,1000);
   assert(p.packets.empty()&&q.deferred>0);p.rxRace=false;run(q,p,1000,3000);assert(q.transmitted==1);}
  {Port p;Pump q(p,sent);completed.clear();q.begin(15,0);p.txStuck=true;q.enqueue(sos(1),0);run(q,p,0,1000);
   assert(q.failed>0&&completed.empty());p.txStuck=false;run(q,p,1000,3000);assert(completed.size()==1);}
  {Port p;Pump q(p);q.begin(16,0);p.rx=true;q.enqueue("HB|NODE_2|1",0);run(q,p,0,4000);
   assert(q.expired==1&&q.depth()==0&&p.packets.empty());}
  {Port p;Pump q(p,sent);completed.clear();q.begin(17,0);
   q.enqueue("ACK|NODE_2-1|NODE_2|NODE_1|ACKNOWLEDGED|2026-10-29T00:00:00Z|12|NODE_1|Team on route|Confirmed|tag",0,3);
   assert(q.finalAckPending());run(q,p,0,6000);assert(completed.size()==3&&!q.finalAckPending());
   assert(p.starts[1]-p.starts[0]>=900&&p.starts[2]-p.starts[1]>=900);}
  {Port p;Pump q(p);q.begin(18,0);q.enqueue(sos(1),0);uint32_t t=0;
   for(;t<200&&p.phase!=1;++t)run(q,p,t,1);assert(p.phase==1);q.cancel("SOS:NODE_2-1");
   assert(p.phase==0&&!q.busy());run(q,p,t,3000);assert(q.depth()==0&&q.transmitted==0);}
  {Port p;Pump q(p);uint32_t start=UINT32_MAX-500;q.begin(19,start);q.enqueue(sos(1),start);run(q,p,start,2000);assert(q.transmitted==1&&q.depth()==0);}
  {MeshLink::Discovery d;d.begin(0,10,123);assert(!d.ready(0,false));int first=-1,count=0;
   for(uint32_t t=0;t<20000;++t)if(d.ready(t,false)){if(first<0)first=t;++count;}
   assert(first>=0&&first<1000&&count>=6&&count<=12);d.ready(20000,true);
   int reconnect=-1;for(uint32_t t=20001;t<21500;++t)if(d.ready(t,false)){reconnect=t;break;}assert(reconnect>20000&&reconnect<21000);
   MeshLink::Discovery wrap;wrap.begin(UINT32_MAX-500,2,1);bool found=false;for(uint32_t i=0;i<1000;++i)if(wrap.ready(UINT32_MAX-500+i,false))found=true;assert(found);}
  for(int node=2;node<=10;++node){
    MeshLink::Discovery d;d.begin(0,node,1234+node);int first=-1;
    for(uint32_t now=0;now<1200;++now)if(d.ready(now,false)&&first<0)first=now;
    assert(first>=0&&first<1000);
    MeshLink::BeaconTracker tracker;assert(tracker.accept("aa:100",0));assert(tracker.accept("aa:100",1));
    assert(!tracker.accept("aa:99",2));assert(tracker.accept("bb:1",3));assert(!tracker.accept("aa:101",4));
    assert(!tracker.accept("cc:4294967296",5));assert(!tracker.accept("cc:bad",5));assert(tracker.accept("bb:2",6));
    MeshLink::BeaconTracker legacy;assert(legacy.accept("100",0));assert(!legacy.accept("1",10));assert(legacy.accept("1",30001));
  }
  std::cout<<"PASS: queue backpressure/coalescing, SOS priority/control fairness, RX protection, CAD backoff/timeouts, TX_DONE-only confirmation, retry/expiry/cancellation, spaced ACKs, all-nine-node fast discovery, gateway reboot/stale-beacon handling and clock rollover.\n";
}
