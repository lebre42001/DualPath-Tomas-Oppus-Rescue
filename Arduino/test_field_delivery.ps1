# Compile actual field-node ACK/recovery handlers against fake flash, radio and
# clock services for every deployed node number. No credentials or boards used.
param([string]$Compiler = '')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if (-not $Compiler) {
    $installed = Get-Command zig -ErrorAction SilentlyContinue
    $Compiler = if ($installed) { $installed.Source } else { Join-Path $repo 'tool/runtime/host-compiler/python/ziglang/zig.exe' }
}
$source = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'Arduino/FieldNode/FieldNode.ino')
$functions = foreach ($name in @('saveAcknowledgedState','restoreLocalResponses','retrySavedResponseCleanupIfNeeded','handleAck','handleAcknowledgmentRequest','requestMissingAcknowledgmentIfNeeded','savePendingState','trySendPending')) {
    $match = [regex]::Match($source, '(?ms)^(?:void|bool)\s+' + $name + '\([^)]*\)\s*\{.*?^\}')
    if (-not $match.Success) { throw "Missing firmware function: $name" }
    $match.Value
}
$harness = @'
#include <cassert>
#include <iostream>
#include <vector>
#include "Arduino.h"
#include "Preferences.h"
class FieldPreferences : public Preferences {
public:
  inline static bool failRemove=false;
  inline static std::vector<String> events;
  size_t putString(const char* key,const String& value){
    size_t result=Preferences::putString(key,value);
    if(result==value.length())events.push_back("write:"+String(key));
    return result;
  }
  bool remove(const char* key){
    if(failRemove)return false;
    bool result=Preferences::remove(key);
    if(result)events.push_back("remove:"+String(key));
    return result;
  }
};
#define Preferences FieldPreferences
#include "report_outbox.h"
#include "local_responses.h"
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "ack_recovery.h"
#undef Preferences
#ifndef FIELD_TEST_NODE
#define FIELD_TEST_NODE 2
#endif
const int NODE_NUMBER=FIELD_TEST_NODE;
String NODE_ID="NODE_"+String(NODE_NUMBER);
FieldPreferences prefs;
ReportOutbox localOutbox;
LocalResponses localResponses(NODE_NUMBER);
String latestMsgId,latestCreatedAt,latestStatus,latestAckMessage,latestAckAt;
String pendingPacket,pendingMsgId,serverReceiptMsgId,lastSosAttemptId;
uint32_t lastGatewaySeenMs=0,lastSosAttemptMs=0,lastResponseCleanupMs=0;
uint32_t lastAckRecoveryMs=0,ackRecoveryDelayMs=30000,sosRetryDelayMs=15000;
uint32_t ackRecoverySequence=0;
String probeBootId="deadbeef";
uint8_t recoveryNextSlot=0;
const uint32_t SOS_RETRY_INTERVAL_MS=15000;
const uint32_t ACK_RECOVERY_INTERVAL_MS=30000;
bool routeAvailable=true,radioInitialized=true;
std::vector<String> sentPackets,canceledPackets;
std::map<std::string,uint32_t> seenAt;
struct FakeMesh { void cancel(const String& key){canceledPackets.push_back(key);} bool busy(){return false;} } meshRadio;
struct Dummy { void println(const String&){} } Serial;
String getField(const String& packet,int n){return LocalResponses::field(packet,n);}
bool serverRouteAvailable(){return routeAvailable;}
bool sendLoRaPacket(const String& packet){sentPackets.push_back(packet);return true;}
long random(long low,long){return low;}
bool routeHasNode(const String& route,const String& node){return std::string((">"+route+">").c_str()).find(std::string((">"+node+">").c_str()))!=std::string::npos;}
String appendRoute(const String& route,const String& node){return routeHasNode(route,node)?route:route.length()?route+">"+node:node;}
bool recentlySeen(const String& key,unsigned long window=12000){
  const auto found=seenAt.find(key.c_str());
  if(found!=seenAt.end()&&uint32_t(millis()-found->second)<window)return true;
  seenAt[key.c_str()]=millis();return false;
}
// ACTUAL_FUNCTIONS
String id(int seq){return NODE_ID+"-"+String(seq);}
String report(int seq){return "SOS|"+id(seq)+"|"+NODE_ID+"|1794000000|R|Collision|NA|0|12|"+NODE_ID+"|Bridge|signed";}
String response(int seq,const String& at="2026-11-07T10:00:00Z",const String& text="Team responding",const String& status="ACKNOWLEDGED"){
  return meshSignOrigin("ACK|"+id(seq)+"|"+NODE_ID+"|NODE_1|"+status+"|"+at+"|12|NODE_1|"+text+"|Confirmed",true,NODE_NUMBER);
}
void reset(){
  Preferences::failWrites=false;FieldPreferences::failRemove=false;
  Preferences::strings.clear();Preferences::numbers.clear();FieldPreferences::events.clear();prefs.begin("field-test",false);
  localOutbox=ReportOutbox();localResponses=LocalResponses(NODE_NUMBER);
  latestMsgId=latestCreatedAt=latestStatus=latestAckMessage=latestAckAt="";
  pendingPacket=pendingMsgId=serverReceiptMsgId=lastSosAttemptId="";
  meshInvalidFrames=lastGatewaySeenMs=lastSosAttemptMs=lastAckRecoveryMs=lastResponseCleanupMs=0;
  recoveryNextSlot=0;ackRecoverySequence=0;ackRecoveryDelayMs=30000;sosRetryDelayMs=15000;fakeClock=100000;
  routeAvailable=radioInitialized=meshSecurityReady=true;
  sentPackets.clear();canceledPackets.clear();seenAt.clear();
}
void latest(int seq){
  latestMsgId=pendingMsgId=id(seq);pendingPacket=report(seq);latestCreatedAt="1794000000";latestStatus="SUBMITTED - UNDER ADMIN REVIEW";
  savePendingState();
}
void reboot(){
  localOutbox=ReportOutbox();localOutbox.restore(prefs);
  latestMsgId=prefs.getString("latest_msg","");latestCreatedAt=prefs.getString("latest_created","");
  latestStatus=prefs.getString("latest_status","");latestAckAt=prefs.getString("latest_ack_at","");latestAckMessage=prefs.getString("latest_ack","");
  int slot=localOutbox.find(latestMsgId);
  pendingPacket=slot>=0?localOutbox.items[slot].packet:String("");pendingMsgId=slot>=0?latestMsgId:String("");
  localResponses=LocalResponses(NODE_NUMBER);restoreLocalResponses();
}
int main(){
  reset();assert(localOutbox.add(prefs,report(1))&&localOutbox.add(prefs,report(2)));latest(2);
  handleAck(response(1));
  assert(localOutbox.find(id(1))<0&&localOutbox.find(id(2))>=0);
  assert(localResponses.find(id(1))>=0&&pendingMsgId==id(2)&&pendingPacket==report(2));
  reboot();assert(localResponses.find(id(1))>=0&&localOutbox.find(id(2))>=0);
  assert(localResponses.items[localResponses.find(id(1))].createdAt=="1794000000");

  reset();assert(localOutbox.add(prefs,report(1))&&localOutbox.add(prefs,report(2)));latest(2);
  handleAck(response(2,"2026-11-07T11:00:00Z","Second report handled"));
  handleAck(response(1,"2026-11-07T10:00:00Z","First report handled"));
  assert(localResponses.find(id(1))>=0&&localResponses.find(id(2))>=0);
  assert(latestMsgId==id(2)&&latestAckMessage=="Confirmed: Second report handled");

  reset();assert(localOutbox.add(prefs,report(1)));latest(1);Preferences::failWrites=true;
  handleAck(response(1));assert(localOutbox.find(id(1))>=0&&localResponses.find(id(1))<0);
  Preferences::failWrites=false;FieldPreferences::failRemove=true;handleAck(response(1));
  assert(localOutbox.find(id(1))>=0&&localResponses.find(id(1))>=0);
  FieldPreferences::failRemove=false;reboot();
  assert(localResponses.find(id(1))>=0&&localOutbox.find(id(1))<0);
  handleAck(response(1));assert(localOutbox.find(id(1))<0);
  size_t persisted=FieldPreferences::events.size(),removed=FieldPreferences::events.size();
  for(size_t i=0;i<FieldPreferences::events.size();++i){
    if(FieldPreferences::events[i].startsWith("write:localack")&&persisted==FieldPreferences::events.size())persisted=i;
    if(FieldPreferences::events[i].startsWith("remove:outbox")&&removed==FieldPreferences::events.size())removed=i;
  }
  assert(persisted<removed);

  reset();assert(localOutbox.add(prefs,report(1)));latest(1);FieldPreferences::failRemove=true;
  handleAck(response(1));assert(localResponses.find(id(1))>=0&&localOutbox.find(id(1))>=0);
  retrySavedResponseCleanupIfNeeded();assert(localOutbox.find(id(1))>=0);
  FieldPreferences::failRemove=false;fakeClock+=5001;retrySavedResponseCleanupIfNeeded();
  assert(localOutbox.find(id(1))<0&&!pendingPacket.length()&&latestStatus=="ACKNOWLEDGED");

  reset();latest(1);latestStatus="ACKNOWLEDGED";latestAckAt="2026-11-07T10:00:00Z";latestAckMessage="Existing admin response";
  savePendingState();reboot();assert(localResponses.find(id(1))>=0&&latestAckMessage=="Existing admin response");

  reset();assert(localOutbox.add(prefs,report(1)));latest(1);
  String forged=response(1);forged.replace("|Team responding|","|Forged response|");
  handleAck(forged);assert(localOutbox.find(id(1))>=0&&meshInvalidFrames==1);
  handleAck(response(1));assert(localOutbox.find(id(1))<0);
  const String first=response(1),newer=response(1,"2026-11-07T11:00:00Z","Resolved");
  handleAck(newer);int slot=localResponses.find(id(1));assert(localResponses.items[slot].packet==newer);
  handleAck(first);assert(localResponses.items[slot].packet==newer);
  handleAck(response(1,"2026-11-07T12:00:00Z","Server receipt","RECEIVED"));
  assert(latestStatus=="ACKNOWLEDGED"&&latestAckMessage=="Confirmed: Resolved");
  reboot();slot=localResponses.find(id(1));assert(localResponses.items[slot].packet==newer);

  reset();const int other=NODE_NUMBER==10?2:NODE_NUMBER+1;
  const String otherNode="NODE_"+String(other);
  const String relayBody="ACK|"+otherNode+"-42|"+otherNode+"|NODE_1|ACKNOWLEDGED|2026-11-07T10:00:00Z|12|NODE_1|Received|Confirmed";
  const String relayed=meshSignOrigin(relayBody,true,other);
  handleAck(relayed);assert(sentPackets.size()==1);
  assert(getField(sentPackets.back(),6)=="11"&&getField(sentPackets.back(),7)=="NODE_1>"+NODE_ID);
  handleAck(relayed);assert(sentPackets.size()==1);
  fakeClock+=20000;handleAck(relayed);assert(sentPackets.size()==2);
  String changed=relayBody;changed.replace("|Received|","|Resolved|");handleAck(meshSignOrigin(changed,true,other));
  assert(sentPackets.size()==3);

  reset();const String recovery=AckRecovery::request(id(42),NODE_NUMBER,"deadbeef-1");
  assert(recovery.length()&&AckRecovery::verify(recovery));
  String altered=recovery;altered.replace("-42|","-43|");assert(!AckRecovery::verify(altered));
  assert(!AckRecovery::request(id(0),NODE_NUMBER,"deadbeef-1").length());
  assert(!AckRecovery::request(NODE_ID+"-4294967296",NODE_NUMBER,"deadbeef-1").length());
  assert(!AckRecovery::request(NODE_ID+"-01",NODE_NUMBER,"deadbeef-1").length());
  assert(!AckRecovery::request(id(1),NODE_NUMBER,"BAD-NONCE").length());
  assert(!AckRecovery::request(id(1),other,"deadbeef-1").length());
  assert(!AckRecovery::request("NODE_1-1",1,"deadbeef-1").length());
  handleAcknowledgmentRequest(recovery);assert(sentPackets.empty());
  const String foreignRequest=AckRecovery::request(otherNode+"-42",other,"deadbeef-1");
  handleAcknowledgmentRequest(foreignRequest);assert(sentPackets.size()==1);
  assert(getField(sentPackets.back(),4)=="11"&&getField(sentPackets.back(),5)==otherNode+">"+NODE_ID);
  assert(AckRecovery::verify(sentPackets.back()));
  handleAcknowledgmentRequest(foreignRequest);assert(sentPackets.size()==1);
  handleAcknowledgmentRequest(AckRecovery::request(otherNode+"-42",other,"deadbeef-2"));assert(sentPackets.size()==2);
  fakeClock+=60001;handleAcknowledgmentRequest(foreignRequest);assert(sentPackets.size()==3);
  String looped=foreignRequest;looped.replace("|12|"+otherNode+"|","|11|"+otherNode+">"+NODE_ID+"|");
  handleAcknowledgmentRequest(looped);assert(sentPackets.size()==3);
  String expired=foreignRequest;expired.replace("|12|","|0|");handleAcknowledgmentRequest(expired);assert(sentPackets.size()==3);

  reset();assert(localOutbox.add(prefs,report(1)));latest(1);
  handleAck(response(1,"2026-11-07T10:00:00Z","Received","RECEIVED"));
  assert(localOutbox.isReceived(id(1))&&!localOutbox.nextPacket().length());
  sentPackets.clear();requestMissingAcknowledgmentIfNeeded();
  assert(!sentPackets.empty()&&getField(sentPackets.back(),0)=="ACKREQ"&&getField(sentPackets.back(),1)==id(1));
  assert(localOutbox.add(prefs,report(2)));latest(2);trySendPending();
  assert(getField(sentPackets.back(),0)=="SOS"&&getField(sentPackets.back(),1)==id(2));
  assert(localOutbox.receipt(prefs,id(2)));std::vector<String> requested;
  for(int n=0;n<8;++n){fakeClock+=60000;requestMissingAcknowledgmentIfNeeded();requested.push_back(getField(sentPackets.back(),1));}
  bool requestedFirst=false,requestedSecond=false;
  for(const auto& value:requested){requestedFirst|=value==id(1);requestedSecond|=value==id(2);}
  assert(requestedFirst&&requestedSecond);
  routeAvailable=false;const auto beforeOffline=sentPackets.size();fakeClock+=60000;requestMissingAcknowledgmentIfNeeded();
  assert(sentPackets.size()==beforeOffline);routeAvailable=true;fakeClock+=60000;requestMissingAcknowledgmentIfNeeded();
  assert(sentPackets.size()>beforeOffline);

  lastAckRecoveryMs=UINT32_MAX-20000;ackRecoveryDelayMs=30000;fakeClock=UINT32_MAX-10000;
  const auto beforeWrap=sentPackets.size();requestMissingAcknowledgmentIfNeeded();assert(sentPackets.size()==beforeWrap);
  fakeClock+=40000;requestMissingAcknowledgmentIfNeeded();assert(sentPackets.size()==beforeWrap+1);

  reset();assert(localOutbox.add(prefs,report(1)));latest(1);handleAck(response(1));
  assert(localOutbox.find(id(1))<0&&localResponses.find(id(1))>=0);
  fakeClock+=60000;requestMissingAcknowledgmentIfNeeded();
  assert(!sentPackets.empty()&&getField(sentPackets.back(),0)=="ACKREQ"&&getField(sentPackets.back(),1)==id(1));
  const String initialNonce=getField(sentPackets.back(),3);
  fakeClock+=60000;requestMissingAcknowledgmentIfNeeded();assert(getField(sentPackets.back(),3)!=initialNonce);
  handleAck(response(1,"2026-11-07T11:00:00Z","Resolved after reconnection"));
  assert(latestAckMessage=="Confirmed: Resolved after reconnection");
  std::cout<<"PASS NODE_"<<NODE_NUMBER<<": old/new responses, durable-before-clear, flash faults, reboot, stale receipts, recovery rotation, HMAC/relay safety and millis rollover.\n";
}
'@
$harness = $harness.Replace('// ACTUAL_FUNCTIONS', ($functions -join "`n"))
$runtime = Join-Path $repo 'tool/runtime'
$testSource = Join-Path $runtime 'field_delivery_test.cpp'
[IO.Directory]::CreateDirectory($runtime) | Out-Null
[IO.File]::WriteAllText($testSource, $harness)
foreach ($node in 2..10) {
    $testBinary = Join-Path $runtime "field_delivery_node$node.exe"
    & $Compiler c++ -std=c++17 "-DFIELD_TEST_NODE=$node" -I (Join-Path $PSScriptRoot 'tests/native') -I (Join-Path $PSScriptRoot 'Arduino/shared') -I (Join-Path $PSScriptRoot 'Arduino/FieldNode') $testSource -lbcrypt -o $testBinary
    if ($LASTEXITCODE -ne 0) { throw "NODE_$node field delivery test compilation failed." }
    & $testBinary
    if ($LASTEXITCODE -ne 0) { throw "NODE_$node field delivery regression failed." }
}
