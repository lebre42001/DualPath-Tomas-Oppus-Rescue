# Compile the actual NODE_1 scheduler/ACK handlers against fake HTTP, flash and
# clock services. No credentials, radio hardware or serial upload are used.
param([string]$Compiler = '')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if (-not $Compiler) {
    $installed = Get-Command zig -ErrorAction SilentlyContinue
    $Compiler = if ($installed) { $installed.Source } else { Join-Path $repo 'tool/runtime/host-compiler/python/ziglang/zig.exe' }
}
$source = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'Arduino/GatewayNode1/GatewayNode1.ino')
$functions = foreach ($name in @('onRadioTransmitted','confirmTransmittedAcknowledgmentIfNeeded','pollPendingAcknowledgmentIfNeeded','loop')) {
    $match = [regex]::Match($source, '(?ms)^void\s+' + $name + '\([^)]*\)\s*\{.*?^\}')
    if (-not $match.Success) { throw "Missing firmware function: $name" }
    $match.Value
}
$timing = foreach ($name in @('ACK_POLL_INTERVAL_MS','ACK_CONFIRM_RETRY_MS')) {
    $match = [regex]::Match($source, '(?m)^const unsigned long ' + $name + '\s*=[^;]+;')
    if (-not $match.Success) { throw "Missing firmware timing constant: $name" }
    $match.Value
}
$harness = @'
#include <cassert>
#include <iostream>
#include <vector>
#include "report_outbox.h"
#include "local_responses.h"
#define NODE_ID "NODE_1"
// TIMING_CONSTANTS
Preferences prefs;
ReportOutbox localOutbox;
LocalResponses localResponses;
String radioAckToConfirm,radioAckConfirmToken,radioAckQueuedPacket,radioAckQueuedToken,lastRadioAckNotified;
String latestMsgId,latestCreatedAt,latestReportStatus,latestAckAt,latestAckMessage,pendingLocalPacket,pendingLocalMsgId;
bool ackConfirmAttempted=false,batchHeartbeatsSupported=true;
uint32_t lastAckConfirmAttemptMs=0,lastAckPollMs=0,lastApiFinishedMs=0,lastHeartbeatMs=0,apiRequestSequence=0;
int lastApiStatusCode=0; uint8_t nextApiTask=0;
bool remoteBusy=false,localBusy=false,earlyFailure=false;
int httpStatus=200,sosCalls=0,localCalls=0,ackCalls=0,confirmCalls=0,radioCalls=0;
String pendingPacket,pendingId,pendingToken;
std::vector<uint32_t> confirmTimes;
struct Dummy { void processNextRequest(){} void handleClient(){} void println(const String&){} } dnsServer,localServer,Serial;
struct Radio { bool busy=false; bool finalAckPending(){return busy;} } meshRadio;
void delay(uint32_t ms){fakeClock+=ms;}
void connectRouter(){} void serviceGatewayRadio(){++radioCalls;} void markLoRaActivity(){}
String getField(const String& value,int n){return LocalResponses::field(value,n);}
String urlEncode(const String& value){return value;}
bool serverRouteAvailable(){return true;}
int meshNodeNumber(const String&){return 2;}
String meshSignOrigin(const String& p,bool,int){return p;}
bool sendLoRaPacket(const String&){return true;}
String extractJsonString(const String&,const String& key){
  if(key=="packet")return pendingPacket;if(key=="msgId")return pendingId;if(key=="ackToken")return pendingToken;return "";
}
void http(){++apiRequestSequence;fakeClock+=1000;lastApiFinishedMs=millis();}
bool requestApi(const String&,const String& path,const String&,String&){
  if(earlyFailure)return false;
  if(path=="/acks/pending?gatewayId=NODE_1")++ackCalls;
  else {++confirmCalls;confirmTimes.push_back(millis());}
  http();lastApiStatusCode=httpStatus;return httpStatus>=200&&httpStatus<300;
}
void checkServerIfNeeded(){}
void sendGatewayHeartbeatIfNeeded(){if(millis()-lastHeartbeatMs>=10000){lastHeartbeatMs=millis();http();}}
void forwardQueuedSosIfNeeded(){if(remoteBusy){++sosCalls;http();}}
void retryLocalReportIfNeeded(){if(localBusy){++localCalls;http();}}
void pollNetworkSummaryIfNeeded(){} void forwardQueuedHeartbeatIfNeeded(){} void forwardQueuedProbeIfNeeded(){}
void forwardAcknowledgmentRequestIfNeeded(){}
// ACTUAL_FUNCTIONS
void reset(){
  Preferences::failWrites=false;Preferences::strings.clear();Preferences::numbers.clear();prefs.begin("gateway-test",false);
  localOutbox=ReportOutbox();localResponses=LocalResponses();fakeClock=100000;lastApiFinishedMs=0;lastHeartbeatMs=fakeClock;
  nextApiTask=0;apiRequestSequence=0;lastApiStatusCode=0;httpStatus=200;ackConfirmAttempted=false;
  radioAckToConfirm="";radioAckConfirmToken="";radioAckQueuedPacket="";radioAckQueuedToken="";lastRadioAckNotified="";
  pendingPacket="";pendingId="";pendingToken="";latestMsgId="";pendingLocalMsgId="";
  remoteBusy=false;localBusy=false;earlyFailure=false;meshRadio.busy=false;lastAckPollMs=0;
  sosCalls=localCalls=ackCalls=confirmCalls=radioCalls=0;confirmTimes.clear();
}
String report(int n){return "SOS|NODE_1-"+String(n)+"|NODE_1|1794000000|R|Collision|NA|0|12|NODE_1|Bridge|signed";}
String response(int n,const String& at="2026-11-07T10:00:00Z",const String& text="Team responding"){
  return "ACK|NODE_1-"+String(n)+"|NODE_1|NODE_1|ACKNOWLEDGED|"+at+"|12|NODE_1|"+text+"|Confirmed";
}
int main(){
  reset();remoteBusy=localBusy=true;
  for(int n=0;n<20000;++n)loop();
  assert(sosCalls>10&&localCalls>10&&ackCalls>10&&radioCalls==20000);
  std::cout<<"PASS continuous SOS traffic still services local reports and admin ACK polling.\n";

  reset();remoteBusy=localBusy=true;radioAckToConfirm="NODE_2-42";httpStatus=500;
  for(int n=0;n<20000;++n)loop();
  assert(confirmCalls>2&&sosCalls>10&&localCalls>10&&radioAckToConfirm=="NODE_2-42");
  for(size_t n=1;n<confirmTimes.size();++n)assert(uint32_t(confirmTimes[n]-confirmTimes[n-1])>=ACK_CONFIRM_RETRY_MS);
  httpStatus=200;fakeClock+=ACK_CONFIRM_RETRY_MS;confirmTransmittedAcknowledgmentIfNeeded();assert(!radioAckToConfirm.length());
  std::cout<<"PASS transient confirmation errors back off, retain the ACK and recover without blocking SOS.\n";

  for(int terminal : {404,409}){
    reset();radioAckToConfirm="NODE_2-42";httpStatus=terminal;
    confirmTransmittedAcknowledgmentIfNeeded();assert(!radioAckToConfirm.length());
    remoteBusy=true;httpStatus=200;for(int n=0;n<1000;++n)loop();assert(sosCalls>0&&ackCalls>0);
  }
  reset();radioAckToConfirm="NODE_2-42";lastApiStatusCode=404;earlyFailure=true;
  confirmTransmittedAcknowledgmentIfNeeded();assert(radioAckToConfirm=="NODE_2-42");
  earlyFailure=false;httpStatus=401;fakeClock+=5000;confirmTransmittedAcknowledgmentIfNeeded();assert(radioAckToConfirm.length());
  std::cout<<"PASS terminal deleted/unqueued responses retire; stale HTTP status and auth failures cannot discard an ACK.\n";

  reset();radioAckToConfirm="NODE_2-42";httpStatus=500;fakeClock=UINT32_MAX-2000;
  confirmTransmittedAcknowledgmentIfNeeded();const int before=confirmCalls;
  fakeClock+=1000;confirmTransmittedAcknowledgmentIfNeeded();assert(confirmCalls==before);
  fakeClock+=4000;confirmTransmittedAcknowledgmentIfNeeded();assert(confirmCalls==before+1);
  std::cout<<"PASS confirmation retry timing crosses millis rollover.\n";

  reset();latestMsgId=pendingLocalMsgId="NODE_1-2";pendingLocalPacket=report(2);
  assert(localOutbox.add(prefs,report(1))&&localOutbox.add(prefs,report(2)));
  pendingPacket=response(1);pendingId="NODE_1-1";pendingToken="revision-one";
  pollPendingAcknowledgmentIfNeeded();
  assert(localOutbox.find("NODE_1-1")<0&&localOutbox.find("NODE_1-2")>=0);
  assert(pendingLocalMsgId=="NODE_1-2"&&pendingLocalPacket==report(2));
  assert(localResponses.find("NODE_1-1")>=0&&radioAckToConfirm=="NODE_1-1"&&confirmCalls==0);
  LocalResponses rebooted;rebooted.restore(prefs);
  assert(rebooted.items[rebooted.find("NODE_1-1")].packet==pendingPacket);
  assert(rebooted.items[rebooted.find("NODE_1-1")].createdAt=="1794000000");
  confirmTransmittedAcknowledgmentIfNeeded();assert(!radioAckToConfirm.length()&&confirmCalls==1);
  std::cout<<"PASS older report response survives a newer submission and reboot before server delivery confirmation.\n";

  fakeClock+=20000;pendingPacket=response(2);pendingId="NODE_1-2";Preferences::failWrites=true;
  pollPendingAcknowledgmentIfNeeded();assert(localOutbox.find("NODE_1-2")>=0&&!radioAckToConfirm.length());
  Preferences::failWrites=false;fakeClock+=20000;pollPendingAcknowledgmentIfNeeded();
  assert(localOutbox.find("NODE_1-2")<0&&!pendingLocalPacket.length()&&latestAckMessage=="Confirmed: Team responding");
  std::cout<<"PASS failed flash write keeps the report pending; retry clears only the matching final ACK.\n";

  reset();for(int n=1;n<=16;++n)assert(localResponses.remember(prefs,response(n,"2026-11-07T10:00:00Z"),"1794000000"));
  Preferences::failWrites=true;assert(!localResponses.remember(prefs,response(17),""));
  assert(localResponses.find("NODE_1-1")>=0&&localResponses.find("NODE_1-17")<0);
  Preferences::failWrites=false;assert(localResponses.remember(prefs,response(17,"2026-11-07T11:00:00Z"),"1794000001"));
  assert(localResponses.find("NODE_1-17")>=0);
  assert(localResponses.remember(prefs,response(17,"2026-11-07T12:00:00Z","Report resolved"),""));
  int slot=localResponses.find("NODE_1-17");assert(localResponses.items[slot].createdAt=="1794000001");
  Preferences::failWrites=true;assert(localResponses.remember(prefs,localResponses.items[slot].packet,""));
  Preferences::failWrites=false;
  assert(!localResponses.remember(prefs,"ACK|NODE_2-1|NODE_2|NODE_1|ACKNOWLEDGED|now|12|NODE_1|bad",""));
  std::cout<<"PASS bounded response cache, revision updates, unchanged-write deduplication, flash failure and origin validation.\n";

  reset();radioAckQueuedPacket="ACK|NODE_2-42|NODE_2|NODE_1|ACKNOWLEDGED|now|12|NODE_1|Responding";
  radioAckQueuedToken="revision-one";onRadioTransmitted(radioAckQueuedPacket);
  assert(radioAckToConfirm=="NODE_2-42"&&!ackConfirmAttempted);
  ackConfirmAttempted=true;onRadioTransmitted(radioAckQueuedPacket);assert(ackConfirmAttempted);
  radioAckQueuedToken="revision-two";onRadioTransmitted(radioAckQueuedPacket);assert(!ackConfirmAttempted);
  std::cout<<"PASS repeated radio copies cannot reset retry backoff; a new response revision can.\n";
}
'@
$harness = $harness.Replace('// ACTUAL_FUNCTIONS', ($functions -join "`n"))
$harness = $harness.Replace('// TIMING_CONSTANTS', ($timing -join "`n"))
$testSource = Join-Path $repo 'tool/runtime/gateway_scheduling_test.cpp'
$testBinary = Join-Path $repo 'tool/runtime/gateway_scheduling_test.exe'
[IO.File]::WriteAllText($testSource, $harness)
& $Compiler c++ -std=c++17 -I (Join-Path $PSScriptRoot 'tests/native') -I (Join-Path $PSScriptRoot 'Arduino/shared') -I (Join-Path $PSScriptRoot 'Arduino/GatewayNode1') $testSource -o $testBinary
if ($LASTEXITCODE -ne 0) { throw 'Gateway scheduler test compilation failed.' }
& $testBinary
if ($LASTEXITCODE -ne 0) { throw 'Gateway scheduler regression failed.' }
