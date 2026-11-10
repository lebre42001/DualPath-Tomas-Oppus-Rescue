#pragma once

// Included after the HTTP/radio helpers. All state and NVS work stays on loop's
// task; the HTTP worker never touches restart state or calls ESP.restart().
String restartCommandIds[11],restartCommandBoots[11];
uint32_t restartCommandExpiries[11]={};
uint32_t restartCommandStarts[11]={},restartCommandBudgets[11]={};
unsigned long lastRestartPollMs=0,lastRestartPostMs=0;
String restartReceipts[11];
uint8_t restartReceiptStage[11]={};
uint8_t nextRestartReceipt=1;

bool restartCommandFresh(int node) {
  const time_t now=time(nullptr);
  return node>=1&&node<=10&&restartCommandIds[node].length()&&restartCommandBudgets[node]&&
    uint32_t(millis()-restartCommandStarts[node])<restartCommandBudgets[node]&&
    now>=1700000000&&uint64_t(now)<restartCommandExpiries[node];
}

bool rememberRestartReceipt(const String& packet) {
  if(!RemoteRestart::verify(packet))return false;
  const String type=getField(packet,0);
  if(type!="RACCEPT"&&type!="RBOOT")return false;
  const int node=meshNodeNumber(getField(packet,2));
  const String existing=restartReceipts[node];
  if(existing.length()) {
    if(RemoteRestart::number(getField(packet,1))<RemoteRestart::number(getField(existing,1)))return false;
    if(getField(packet,1)==getField(existing,1)&&getField(existing,0)=="RBOOT"&&type=="RACCEPT")return true;
    if(packet==existing)return true;
  }
  const String key="rst-receipt-"+String(node);
  if(!prefs.putString(key.c_str(),packet))return false;
  restartReceipts[node]=packet;restartReceiptStage[node]=0;
  return true;
}

void restoreRestartReceipts() {
  for(int node=1;node<=10;++node) {
    const String saved=prefs.getString(("rst-receipt-"+String(node)).c_str(),"");
    if(RemoteRestart::verify(saved)&&meshNodeNumber(getField(saved,2))==node&&
       (getField(saved,0)=="RACCEPT"||getField(saved,0)=="RBOOT"))restartReceipts[node]=saved;
  }
}

void handleGatewayRestartPacket(const String& packet) {
  if(!RemoteRestart::verify(packet)){++meshInvalidFrames;return;}
  const String type=getField(packet,0);
  if(type=="RACCEPT"||type=="RBOOT") { rememberRestartReceipt(packet);return; }
  const int node=meshNodeNumber(getField(packet,2));
  if(type!="RSTREADY"||!restartCommandFresh(node)||getField(packet,1)!=restartCommandIds[node]||getField(packet,3)!=restartCommandBoots[node])return;
  sendLoRaPacket(RemoteRestart::make("RSTCOMMIT",restartCommandIds[node],node,restartCommandBoots[node],getField(packet,4)));
}

void pollRemoteRestartIfNeeded() {
  if(!serverRouteAvailable()||gatewayRestart.armed||millis()-lastRestartPollMs<5000)return;
  lastRestartPollMs=millis();
  // A trusted NTP clock is required even for a locally configured HTTP server.
  // Unknown time must never turn a stale queued radio command into a restart.
  if(time(nullptr)<1700000000)return;
  const uint32_t started=millis();
  String response;
  if(!requestApi("GET","/commands/pending?gateway_id=NODE_1","",response))return;
  const String id=extractJsonString(response,"id"),nodeId=extractJsonString(response,"node_id"),boot=extractJsonString(response,"expected_boot");
  const int node=meshNodeNumber(nodeId);
  const uint32_t expiry=(uint32_t)extractJsonInt(response,"expires_at_epoch",0);
  const int remaining=extractJsonInt(response,"expires_in_seconds",0);
  if(!RemoteRestart::idValid(id)||!node||!RemoteRestart::hexValid(boot)||expiry<=uint32_t(time(nullptr))||remaining<=0||remaining>300) {
    return;
  }
  restartCommandIds[node]=id;restartCommandBoots[node]=boot;restartCommandExpiries[node]=expiry;
  // The server lifetime also expires on the monotonic clock. Starting before
  // HTTP conservatively consumes its network delay and NTP adjustments cannot
  // extend an authorization cached for an unreachable field node.
  restartCommandStarts[node]=started;restartCommandBudgets[node]=uint32_t(remaining)*1000;
  if(!restartCommandFresh(node))return;
  if(node==1) {
    if(boot!=gatewayRestart.currentBoot)return;
    if(gatewayRestart.accept(prefs,id,boot))rememberRestartReceipt(gatewayRestart.receipt());
    return;
  }
  sendLoRaPacket(RemoteRestart::make("RSTQ",id,node,boot));
}

void postRemoteRestartReceiptsIfNeeded() {
  // Recovery is independent of command expiry: these are acknowledgments of an
  // already durably consumed command, never authorization for a second reboot.
  if(millis()-lastRestartPostMs<3000)return;
  for(int scanned=0;scanned<10;++scanned) {
    const int node=nextRestartReceipt;nextRestartReceipt=node==10?1:node+1;
    const String packet=restartReceipts[node];
    if(!packet.length())continue;
    lastRestartPostMs=millis();
    const bool booted=getField(packet,0)=="RBOOT";
    const String id=getField(packet,1),before=getField(packet,3),after=getField(packet,4);
    const bool postBoot=booted&&restartReceiptStage[node]>=1;
    const String status=postBoot?"restarted":"accepted",boot=postBoot?after:before;
    if(restartReceiptStage[node]<2) {
      String response;
      const String body="{\"node_id\":\"NODE_"+String(node)+"\",\"status\":\""+status+"\",\"boot_id\":\""+boot+"\"}";
      if(!requestApi("POST","/commands/"+id+"/result",body,response))return;
      // Radio is serviced during HTTPS: a newer boot proof may have replaced
      // this accepted receipt while the request was in flight. Never clear it.
      if(restartReceipts[node]!=packet)return;
      if(node==1&&!booted) {
        if(restartCommandFresh(1)&&gatewayRestart.executionFresh(millis()))gatewayRestart.acceptedByServer(millis());
        else if(gatewayRestart.armed&&!gatewayRestart.cancel(prefs))return;
      }
      restartReceiptStage[node]=booted?(postBoot?2:1):2;
    }
    if(booted&&restartReceiptStage[node]<2)return;
    if(booted&&restartReceiptStage[node]==2) {
      if(node==1) { if(!gatewayRestart.finish(prefs))return; }
      else if(!sendLoRaPacket(RemoteRestart::make("RDONE",id,node,after)))return;
    }
    // Keep the durable proof until Laravel has confirmed every required phase.
    const String key="rst-receipt-"+String(node);
    if(!prefs.remove(key.c_str()))return;
    restartReceipts[node]="";restartReceiptStage[node]=0;
    return;
  }
}
