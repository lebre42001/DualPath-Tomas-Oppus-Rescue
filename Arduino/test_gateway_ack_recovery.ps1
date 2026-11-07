# Compile the actual gateway recovery inbox/forwarder with synthetic public
# keys and fake HTTP/radio services. No credentials or radio boards are used.
param([string]$Compiler = '')
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
if (-not $Compiler) {
    $installed = Get-Command zig -ErrorAction SilentlyContinue
    $Compiler = if ($installed) { $installed.Source } else { Join-Path $repo 'tool/runtime/host-compiler/python/ziglang/zig.exe' }
}
$source = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'Arduino/GatewayNode1/GatewayNode1.ino')
$functions = foreach ($name in @('queueGatewayPacket', 'forwardAcknowledgmentRequestIfNeeded')) {
    $match = [regex]::Match($source, '(?ms)^void\s+' + $name + '\([^)]*\)\s*\{.*?^\}')
    if (-not $match.Success) { throw "Missing gateway function: $name" }
    $match.Value
}
$runtimeHeader = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'Arduino/GatewayNode1/gateway_runtime.h')
$item = [regex]::Match($runtimeHeader, '(?ms)^struct GatewayRadioItem \{.*?^\};')
if (-not $item.Success) { throw 'Missing GatewayRadioItem definition.' }
$harness = @'
#include <cassert>
#include <iostream>
#include <vector>
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "ack_recovery.h"
// ACTUAL_RADIO_ITEM
GatewayRadioItem sosInbox[11],heartbeatInbox[11],probeInbox[11],ackRequestInbox[11];
Preferences prefs;
int nextAckRequestNode=2,lastApiStatusCode=0;
uint32_t apiRequestSequence=0;
String radioAckToConfirm,radioAckQueuedPacket,radioAckQueuedToken;
bool routeAvailable=true,radioAccepts=true,earlyFailure=false,autoResponse=true,replacementDuringHttp=false;
int httpStatus=200,failNode=0;
String responsePacket,responseToken="revision-token";
std::vector<String> paths,methods,sentPackets;
struct Dummy { void println(const String&){} } Serial;
struct Radio { bool pending=false; bool finalAckPending(){return pending;} } meshRadio;
bool serverRouteAvailable(){return routeAvailable;}
void queueGatewayPacket(const String& packet,int rssi,float snr);
String getField(const String& packet,int n){return meshField(packet,n);}
String urlEncode(const String& value){return value;} // Validated node IDs contain only URL-safe characters.
String response(const String& id,const String& origin){
  return "ACK|"+id+"|"+origin+"|NODE_1|ACKNOWLEDGED|2026-11-07T10:00:00Z|12|NODE_1|Team responding|Confirmed";
}
bool requestApi(const String& method,const String& path,const String& body,String& result){
  if(earlyFailure)return false;
  assert(!body.length());++apiRequestSequence;methods.push_back(method);paths.push_back(path);
  const int senderStart=path.indexOf('&')+10;
  const int senderEnd=path.indexOf('&',senderStart);
  const String origin=path.substring(senderStart,senderEnd);
  const String id=path.substring(senderEnd+7);
  if(autoResponse)responsePacket=response(id,origin);
  if(replacementDuringHttp){
    replacementDuringHttp=false;
    queueGatewayPacket(AckRecovery::request("NODE_2-99",2,"deadbeef-99"),-40,9);
  }
  lastApiStatusCode=meshNodeNumber(origin)==failNode?500:httpStatus;
  result="fake HTTP response";
  return lastApiStatusCode>=200&&lastApiStatusCode<300;
}
String extractJsonString(const String&,const String& key){
  if(key=="packet")return responsePacket;
  if(key=="ackToken")return responseToken;
  return "";
}
bool sendLoRaPacket(const String& packet){
  if(!radioAccepts)return false;
  sentPackets.push_back(packet);meshRadio.pending=true;return true;
}
// ACTUAL_FUNCTIONS
String request(int node,int sequence=42,const String& nonce="deadbeef-1"){
  return AckRecovery::request("NODE_"+String(node)+"-"+String(sequence),node,nonce);
}
void reset(){
  for(int n=0;n<=10;++n)sosInbox[n]=heartbeatInbox[n]=probeInbox[n]=ackRequestInbox[n]=GatewayRadioItem();
  Preferences::strings.clear();Preferences::numbers.clear();Preferences::failWrites=false;prefs.begin("gateway-recovery",false);
  fakeClock=100000;nextAckRequestNode=2;apiRequestSequence=0;meshInvalidFrames=0;lastApiStatusCode=0;
  radioAckToConfirm=radioAckQueuedPacket=radioAckQueuedToken="";
  routeAvailable=radioAccepts=autoResponse=true;earlyFailure=meshRadio.pending=replacementDuringHttp=false;
  httpStatus=200;failNode=0;responsePacket="";responseToken="revision-token";
  methods.clear();paths.clear();sentPackets.clear();
}
void queue(int node,int sequence=42,const String& nonce="deadbeef-1"){
  queueGatewayPacket(request(node,sequence,nonce),-50,8);
}
int main(){
  reset();const String valid=request(2);String forged=valid;forged.replace("NODE_2-42|","NODE_2-43|");
  queueGatewayPacket(forged,-50,8);assert(meshInvalidFrames==1&&!ackRequestInbox[2].packet.length());
  queueGatewayPacket("ACKREQ|NODE_11-42|NODE_11|deadbeef-1|12|NODE_11|forged",-50,8);
  queueGatewayPacket("ACKREQ|NODE_2-0|NODE_2|deadbeef-1|12|NODE_2|forged",-50,8);
  assert(meshInvalidFrames==3&&paths.empty());
  queue(2);const uint32_t received=ackRequestInbox[2].receivedAtMs;ackRequestInbox[2].attempted=true;
  fakeClock+=100;queue(2,42,"deadbeef-2");
  assert(ackRequestInbox[2].packet==valid&&ackRequestInbox[2].receivedAtMs==received&&ackRequestInbox[2].attempted);
  queue(2,43);queue(3,99);
  assert(getField(ackRequestInbox[2].packet,1)=="NODE_2-43"&&!ackRequestInbox[2].attempted);
  assert(getField(ackRequestInbox[3].packet,1)=="NODE_3-99"&&Preferences::strings.empty());

  for(int node=2;node<=10;++node){
    reset();queue(node,17);forwardAcknowledgmentRequestIfNeeded();
    assert(methods.size()==1&&methods[0]=="GET");
    assert(paths[0]=="/acks/pending?gatewayId=NODE_1&senderId=NODE_"+String(node)+"&msgId=NODE_"+String(node)+"-17");
    assert(sentPackets.size()==1&&meshVerifyOrigin(sentPackets[0],true,node));
    assert(getField(sentPackets[0],1)=="NODE_"+String(node)+"-17");
    assert(radioAckQueuedPacket==sentPackets[0]&&radioAckQueuedToken==responseToken&&!ackRequestInbox[node].packet.length());
    assert(!sosInbox[node].packet.length()&&Preferences::strings.empty());
  }

  for(int malformed=0;malformed<3;++malformed){
    reset();queue(2,17);autoResponse=false;
    responsePacket=malformed==0?response("NODE_2-18","NODE_2"):
      malformed==1?response("NODE_2-17","NODE_3"):String("SOS|NODE_2-17|NODE_2|not an ACK");
    forwardAcknowledgmentRequestIfNeeded();
    assert(sentPackets.empty()&&ackRequestInbox[2].packet.length()&&!radioAckQueuedPacket.length());
  }

  reset();queue(2);radioAccepts=false;forwardAcknowledgmentRequestIfNeeded();
  assert(ackRequestInbox[2].packet.length()&&sentPackets.empty()&&!radioAckQueuedPacket.length());
  const size_t attempts=paths.size();forwardAcknowledgmentRequestIfNeeded();assert(paths.size()==attempts);
  fakeClock+=5001;radioAccepts=true;forwardAcknowledgmentRequestIfNeeded();assert(sentPackets.size()==1);

  reset();queue(2);routeAvailable=false;forwardAcknowledgmentRequestIfNeeded();
  assert(paths.empty()&&ackRequestInbox[2].packet.length());
  routeAvailable=true;httpStatus=500;forwardAcknowledgmentRequestIfNeeded();
  assert(ackRequestInbox[2].packet.length()&&sentPackets.empty());
  fakeClock+=5001;httpStatus=200;forwardAcknowledgmentRequestIfNeeded();assert(sentPackets.size()==1);

  for(int status:{404,422}){
    reset();queue(2);httpStatus=status;forwardAcknowledgmentRequestIfNeeded();
    assert(paths.size()==1&&!ackRequestInbox[2].packet.length()&&sentPackets.empty());
    reset();queue(2);lastApiStatusCode=status;earlyFailure=true;forwardAcknowledgmentRequestIfNeeded();
    assert(apiRequestSequence==0&&ackRequestInbox[2].packet.length());
  }
  for(int status:{401,403,409,429,500,503}){
    reset();queue(2);httpStatus=status;forwardAcknowledgmentRequestIfNeeded();
    assert(ackRequestInbox[2].packet.length()&&sentPackets.empty());
  }
  reset();queue(2);autoResponse=false;responsePacket="";forwardAcknowledgmentRequestIfNeeded();
  assert(!ackRequestInbox[2].packet.length()&&sentPackets.empty());

  reset();queue(2);queue(3);failNode=2;forwardAcknowledgmentRequestIfNeeded();
  assert(ackRequestInbox[2].packet.length()&&sentPackets.empty());
  forwardAcknowledgmentRequestIfNeeded();
  assert(paths.size()==2&&sentPackets.size()==1&&getField(sentPackets[0],2)=="NODE_3");
  assert(ackRequestInbox[2].packet.length()&&!ackRequestInbox[3].packet.length());

  reset();queue(2);radioAckQueuedPacket="existing packet";radioAckQueuedToken="existing token";meshRadio.pending=true;
  forwardAcknowledgmentRequestIfNeeded();assert(paths.empty()&&ackRequestInbox[2].packet.length());
  assert(radioAckQueuedPacket=="existing packet"&&radioAckQueuedToken=="existing token");
  meshRadio.pending=false;radioAckToConfirm="NODE_3-42";forwardAcknowledgmentRequestIfNeeded();
  assert(paths.empty()&&ackRequestInbox[2].packet.length());
  assert(radioAckQueuedPacket=="existing packet"&&radioAckQueuedToken=="existing token");
  radioAckToConfirm="";forwardAcknowledgmentRequestIfNeeded();assert(sentPackets.size()==1);

  reset();queue(2,17);replacementDuringHttp=true;forwardAcknowledgmentRequestIfNeeded();
  assert(sentPackets.size()==1&&getField(sentPackets[0],1)=="NODE_2-17");
  assert(getField(ackRequestInbox[2].packet,1)=="NODE_2-99");
  meshRadio.pending=false;forwardAcknowledgmentRequestIfNeeded();
  assert(sentPackets.size()==2&&getField(sentPackets[1],1)=="NODE_2-99");
  std::cout<<"PASS actual gateway ACK recovery: authenticated requests, per-origin coalescing, read-only exact GET lookup, signed responses, retry/error handling, fairness and ACK metadata ownership.\n";
}
'@
$harness = $harness.Replace('// ACTUAL_RADIO_ITEM', $item.Value).Replace('// ACTUAL_FUNCTIONS', ($functions -join "`n"))
$runtime = Join-Path $repo 'tool/runtime'
[IO.Directory]::CreateDirectory($runtime) | Out-Null
$testSource = Join-Path $runtime 'gateway_ack_recovery_test.cpp'
$testBinary = Join-Path $runtime 'gateway_ack_recovery_test.exe'
[IO.File]::WriteAllText($testSource, $harness)
& $Compiler c++ -std=c++17 -I (Join-Path $PSScriptRoot 'tests/native') -I (Join-Path $PSScriptRoot 'Arduino/shared') $testSource -lbcrypt -o $testBinary
if ($LASTEXITCODE -ne 0) { throw 'Gateway ACK recovery test compilation failed.' }
& $testBinary
if ($LASTEXITCODE -ne 0) { throw 'Gateway ACK recovery regression failed.' }
