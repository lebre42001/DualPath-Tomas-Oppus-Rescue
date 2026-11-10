#include <cassert>
#include <iostream>
#include <cstdlib>
#include <ctime>
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "remote_restart.h"

Preferences prefs;
RemoteRestart::Target gatewayRestart;
unsigned int fakeEpoch=1794000000;
time_t fakeTime(time_t*){return fakeEpoch;}
String getField(const String& p,int n){return meshField(p,n);}
bool routeAvailable=true,radioAccepts=true,httpAccepts=true,replaceDuringHttp=false;
String replyId="1",replyNode="NODE_2",replyBoot="aabb",replacement;
int replyExpiry=1794000300,replyRemaining=300;
struct Request {String method,path,body;};
std::vector<Request> requests;
std::vector<String> sent;
bool serverRouteAvailable(){return routeAvailable;}
bool sendLoRaPacket(const String& p){if(!radioAccepts)return false;sent.push_back(p);return true;}
String extractJsonString(const String&,const String& key){if(key=="id")return replyId;if(key=="node_id")return replyNode;if(key=="expected_boot")return replyBoot;return "";}
int extractJsonInt(const String&,const String& key,int){return key=="expires_in_seconds"?replyRemaining:replyExpiry;}
bool rememberRestartReceipt(const String& packet);
bool requestApi(const String& method,const String& path,const String& body,String& result){
  requests.push_back({method,path,body});result="test";
  if(replaceDuringHttp){replaceDuringHttp=false;assert(rememberRestartReceipt(replacement));}
  return httpAccepts;
}
#define time fakeTime
#include "gateway_restart.h"
#undef time

void reset(){
  Preferences::strings.clear();Preferences::numbers.clear();Preferences::failWrites=false;prefs.begin("gateway-test",false);
  gatewayRestart=RemoteRestart::Target();gatewayRestart.begin(prefs,"aabb");
  fakeClock=100000;fakeEpoch=1794000000;replyExpiry=fakeEpoch+300;replyRemaining=300;replyId="1";replyNode="NODE_2";replyBoot="aabb";
  routeAvailable=radioAccepts=httpAccepts=true;replaceDuringHttp=false;requests.clear();sent.clear();replacement="";
  for(int n=0;n<=10;++n){restartCommandIds[n]=restartCommandBoots[n]=restartReceipts[n]="";restartCommandExpiries[n]=restartCommandStarts[n]=restartCommandBudgets[n]=restartReceiptStage[n]=0;}
  nextRestartReceipt=1;lastRestartPollMs=lastRestartPostMs=0;
}
void post(){fakeClock+=3001;postRemoteRestartReceiptsIfNeeded();}
int main(){
  reset();routeAvailable=false;pollRemoteRestartIfNeeded();assert(requests.empty());
  routeAvailable=true;fakeEpoch=0;pollRemoteRestartIfNeeded();assert(requests.empty());
  fakeClock+=5001;fakeEpoch=1794000000;pollRemoteRestartIfNeeded();assert(requests.size()==1&&sent.size()==1);
  assert(RemoteRestart::verify(sent.back())&&meshField(sent.back(),0)=="RSTQ");
  fakeClock+=5001;replyId="2";replyNode="NODE_3";pollRemoteRestartIfNeeded();
  const auto prior=sent.size();handleGatewayRestartPacket(RemoteRestart::make("RSTREADY","1",2,"aabb","a1b2"));
  assert(sent.size()==prior+1&&meshField(sent.back(),0)=="RSTCOMMIT");
  handleGatewayRestartPacket(RemoteRestart::make("RSTREADY","1",2,"ccdd","a1b2"));assert(sent.size()==prior+1);
  fakeEpoch=1794000400;handleGatewayRestartPacket(RemoteRestart::make("RSTREADY","1",2,"aabb","a1b2"));assert(sent.size()==prior+1);
  fakeEpoch=1793999900;fakeClock+=300001;
  handleGatewayRestartPacket(RemoteRestart::make("RSTREADY","1",2,"aabb","a1b2"));assert(sent.size()==prior+1&&!restartCommandFresh(2));
  std::cout<<"PASS internet/time/expiry gates and slow per-target challenges survive other pending command offers.\n";

  reset();auto accepted=RemoteRestart::make("RACCEPT","7",2,"aabb"),booted=RemoteRestart::make("RBOOT","7",2,"aabb","ccdd");
  auto forged=accepted;forged.replace("RACCEPT|7|","RACCEPT|8|");assert(!rememberRestartReceipt(forged));
  Preferences::failWrites=true;assert(!rememberRestartReceipt(accepted));Preferences::failWrites=false;
  assert(rememberRestartReceipt(accepted));httpAccepts=false;post();assert(restartReceipts[2]==accepted);
  httpAccepts=true;post();assert(!restartReceipts[2].length());
  assert(rememberRestartReceipt(booted));restartReceipts[2]="";restoreRestartReceipts();assert(restartReceipts[2]==booted);
  assert(rememberRestartReceipt(accepted)&&restartReceipts[2]==booted); // Never downgrade boot proof.
  post();assert(restartReceiptStage[2]==1&&requests.back().body.indexOf("accepted")>=0);
  radioAccepts=false;post();assert(restartReceiptStage[2]==2&&requests.back().body.indexOf("restarted")>=0&&restartReceipts[2]==booted);
  const auto httpCount=requests.size();radioAccepts=true;post();assert(requests.size()==httpCount&&!restartReceipts[2].length());
  assert(RemoteRestart::verify(sent.back())&&meshField(sent.back(),0)=="RDONE");
  std::cout<<"PASS signed durable receipts, HTTP failure/retry, phase ordering, reload, radio-confirmation backpressure.\n";

  reset();assert(rememberRestartReceipt(accepted));replacement=booted;replaceDuringHttp=true;post();
  assert(restartReceipts[2]==booted&&restartReceiptStage[2]==0&&prefs.getString("rst-receipt-2","")==booted);
  post();post();assert(!restartReceipts[2].length());
  std::cout<<"PASS boot proof received while HTTP accepted is in flight cannot be deleted or downgraded.\n";

  reset();replyNode="NODE_1";replyId="9";pollRemoteRestartIfNeeded();
  assert(gatewayRestart.armed&&!gatewayRestart.guardStarted()&&restartReceipts[1].length());
  httpAccepts=false;post();assert(!gatewayRestart.guardStarted()&&!gatewayRestart.restartDue(fakeClock+10000));
  httpAccepts=true;post();assert(gatewayRestart.guardStarted()&&!gatewayRestart.restartDue(fakeClock));
  assert(gatewayRestart.restartDue(fakeClock+2000));
  gatewayRestart=RemoteRestart::Target();gatewayRestart.begin(prefs,"eeff");assert(!gatewayRestart.armed&&gatewayRestart.afterBoot=="eeff");
  assert(rememberRestartReceipt(gatewayRestart.receipt()));post();post();assert(gatewayRestart.complete&&!restartReceipts[1].length());
  std::cout<<"PASS NODE_1 requires HTTP accepted before reboot guard and retains proof until postboot HTTP confirmation.\n";
  reset();replyNode="NODE_1";pollRemoteRestartIfNeeded();assert(gatewayRestart.armed);
  fakeEpoch=replyExpiry+1;post();assert(!gatewayRestart.armed&&!gatewayRestart.restartDue(fakeClock+10000)&&gatewayRestart.complete);
  RemoteRestart::Target cancelled;cancelled.begin(prefs,"bbcc");assert(cancelled.complete&&!cancelled.receipt().length());
  reset();replyNode="NODE_1";pollRemoteRestartIfNeeded();fakeClock+=31000;post();
  assert(!gatewayRestart.armed&&gatewayRestart.complete&&!gatewayRestart.restartDue(fakeClock+2000));
  std::cout<<"PASS late server acceptance after command expiry or 30-second local execution window cannot trigger a stale NODE_1 reboot.\n";
  reset();replyNode="NODE_1";pollRemoteRestartIfNeeded();Preferences::failWrites=true;
  gatewayRestart=RemoteRestart::Target();gatewayRestart.begin(prefs,"ccdd");assert(!gatewayRestart.afterBoot.length()&&!gatewayRestart.receipt().length());
  Preferences::failWrites=false;const auto recovered=gatewayRestart.dueReceipt(fakeClock);
  assert(RemoteRestart::verify(recovered)&&meshField(recovered,0)=="RBOOT"&&gatewayRestart.afterBoot=="ccdd");
  assert(rememberRestartReceipt(recovered));post();post();assert(gatewayRestart.complete);
  std::cout<<"PASS initial NODE_1 postboot-proof NVS failure retries through periodic receipt service and recovers.\n";
}
