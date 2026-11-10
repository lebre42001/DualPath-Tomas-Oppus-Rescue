#pragma once
#include "mesh_security.h"

// Restart is a separate authenticated protocol, never a text/portal command.
// A fresh target challenge avoids depending on unsynchronized field clocks.
namespace RemoteRestart {
static const uint32_t ChallengeMs = 30000, GuardMs = 2000, ReceiptRetryMs = 10000;
inline bool idValid(const String& id) {
  uint64_t value = 0;
  if (!id.length() || id.length() > 10) return false;
  for (size_t i=0;i<id.length();++i) { if(id[i]<'0'||id[i]>'9')return false;value=value*10+id[i]-'0'; }
  return value && value<=UINT32_MAX && String((uint32_t)value)==id;
}
inline uint32_t number(const String& id) { return idValid(id)?(uint32_t)strtoull(id.c_str(),nullptr,10):0; }
inline bool hexValid(const String& value) {
  if(!value.length()||value.length()>16)return false;
  for(size_t i=0;i<value.length();++i)if(!((value[i]>='0'&&value[i]<='9')||(value[i]>='a'&&value[i]<='f')))return false;
  return true;
}
inline bool known(const String& type) { return type=="RSTQ"||type=="RSTREADY"||type=="RSTCOMMIT"||type=="RACCEPT"||type=="RBOOT"||type=="RDONE"; }
inline String body(const String& packet) {
  String result="RESTART1";
  for(int i=0;i<=4;++i)result+="|"+meshField(packet,i);
  return result;
}
inline bool shape(const String& packet) {
  const String type=meshField(packet,0),extra=meshField(packet,4);
  return known(type)&&idValid(meshField(packet,1))&&meshNodeNumber(meshField(packet,2))&&
    hexValid(meshField(packet,3))&&((type=="RSTQ"||type=="RACCEPT"||type=="RDONE")?extra=="-":hexValid(extra))&&
    meshField(packet,8).length()==0;
}
inline bool verify(const String& packet) {
  if(!shape(packet))return false;
  const uint8_t* key=meshOriginKey(meshNodeNumber(meshField(packet,2)));
  return key&&meshEqual(meshField(packet,7),meshTag(key,body(packet)));
}
inline String make(const String& type,const String& id,int node,const String& boot,const String& extra="-") {
  const bool fromGateway=type=="RSTQ"||type=="RSTCOMMIT"||type=="RDONE";
  const String packet=type+"|"+id+"|NODE_"+String(node)+"|"+boot+"|"+extra+"|12|NODE_"+String(fromGateway?1:node);
  const uint8_t* key=meshOriginKey(node);
  return shape(packet)&&key?packet+"|"+meshTag(key,body(packet)):String("");
}
inline String relay(const String& packet,const String& ownNode) {
  if(!shape(packet))return "";
  const int ttl=meshField(packet,5).toInt();const String route=meshField(packet,6);
  if(ttl<=0||ttl>12||(">"+route+">").indexOf(">"+ownNode+">")>=0)return "";
  String result;
  for(int i=0;i<=4;++i)result+=(i?"|":"")+meshField(packet,i);
  return result+"|"+String(ttl-1)+"|"+route+">"+ownNode+"|"+meshField(packet,7);
}

class Target {
public:
  explicit Target(int node=MESH_THIS_NODE):node_(node){}
  String id, beforeBoot, currentBoot, afterBoot;
  bool complete=true, armed=false;
  void begin(Preferences& prefs,const String& boot) {
    prefs_=&prefs;currentBoot=boot;
    const String saved=prefs.getString("restart-v1","");
    if(!saved.length())return;
    // Corrupt storage fails closed: no new remote restart may overwrite proof.
    const String savedId=meshField(saved,0),before=meshField(saved,1),done=meshField(saved,2),after=meshField(saved,3);
    validStorage_=idValid(savedId)&&hexValid(before)&&(done=="0"||done=="1")&&(!after.length()||(hexValid(after)&&after!=before));
    if(!validStorage_)return;
    id=savedId;beforeBoot=before;afterBoot=after;complete=done=="1";
    ensureProof();
  }
  String receive(Preferences& prefs,const String& packet,uint32_t now) {
    if(!validStorage_||!verify(packet)||meshNodeNumber(meshField(packet,2))!=node_)return "";
    const String type=meshField(packet,0),incoming=meshField(packet,1),boot=meshField(packet,3),extra=meshField(packet,4);
    if(type=="RDONE") {
      if(incoming==id&&afterBoot.length()&&boot==afterBoot&&!complete&&prefs.putString("restart-v1",id+"|"+beforeBoot+"|1|"+afterBoot))complete=true;
      return "";
    }
    if(type!="RSTQ"&&type!="RSTCOMMIT")return "";
    if(number(incoming)<=number(id))return incoming==id?receipt():String("");
    if(armed||(!complete&&id.length())||boot!=currentBoot)return "";
    if(type=="RSTQ") {
      if(challengeId_!=incoming||uint32_t(now-challengeAt_)>=ChallengeMs) {
        challengeId_=incoming;challenge_=String(esp_random(),HEX)+String(esp_random(),HEX);challengeAt_=now;
      }
      return make("RSTREADY",incoming,node_,currentBoot,challenge_);
    }
    if(incoming!=challengeId_||extra!=challenge_||uint32_t(now-challengeAt_)>=ChallengeMs)return "";
    if(!accept(prefs,incoming,boot,now))return "";
    return receipt();
  }
  bool accept(Preferences& prefs,const String& incoming,const String& boot,uint32_t now=millis()) {
    if(!validStorage_||!idValid(incoming)||(!complete&&id.length())||boot!=currentBoot||number(incoming)<=number(id))return false;
    // One atomic NVS string carries both high-water ID and pending boot proof.
    if(!prefs.putString("restart-v1",incoming+"|"+boot+"|0"))return false;
    id=incoming;beforeBoot=boot;afterBoot="";complete=false;armed=true;transmitted_=false;lastReceipt_=0;acceptedAt_=now;
    return true;
  }
  String receipt()const {
    if(!id.length()||complete)return "";
    if(currentBoot!=beforeBoot&&!afterBoot.length())return "";
    return make(afterBoot.length()?"RBOOT":"RACCEPT",id,node_,beforeBoot,afterBoot.length()?afterBoot:String("-"));
  }
  String dueReceipt(uint32_t now) {
    if(complete||!id.length()||(lastReceipt_&&uint32_t(now-lastReceipt_)<ReceiptRetryMs))return "";
    lastReceipt_=now;ensureProof();return receipt();
  }
  void transmitted(const String& packet,uint32_t now) {
    if(executionFresh(now)&&!transmitted_&&meshField(packet,0)=="RACCEPT"&&meshField(packet,1)==id&&verify(packet)) { transmitted_=true;transmittedAt_=now; }
  }
  void acceptedByServer(uint32_t now) { if(executionFresh(now)&&!transmitted_){transmitted_=true;transmittedAt_=now;} }
  bool executionFresh(uint32_t now)const { return armed&&uint32_t(now-acceptedAt_)<ChallengeMs; }
  bool restartDue(uint32_t now)const { return executionFresh(now)&&transmitted_&&uint32_t(now-transmittedAt_)>=GuardMs; }
  bool cancel(Preferences& prefs) {
    if(!armed||!finish(prefs))return false;
    armed=false;transmitted_=false;return true;
  }
  bool cancelExpired(Preferences& prefs,uint32_t now) { return armed&&!executionFresh(now)&&cancel(prefs); }
  bool guardStarted()const { return transmitted_; }
  bool finish(Preferences& prefs) {
    if(!id.length()||!prefs.putString("restart-v1",id+"|"+beforeBoot+"|1|"+afterBoot))return false;
    complete=true;return true;
  }
private:
  bool validStorage_=true,transmitted_=false;
  int node_=MESH_THIS_NODE;
  Preferences* prefs_=nullptr;
  String challengeId_,challenge_;
  uint32_t challengeAt_=0,acceptedAt_=0,transmittedAt_=0,lastReceipt_=0;
  void ensureProof() {
    // Persist the FIRST new boot, so a lost RDONE followed by another power
    // cycle repeats the identical receipt instead of inventing a new result.
    if(validStorage_&&!complete&&id.length()&&currentBoot!=beforeBoot&&!afterBoot.length()&&prefs_&&
       prefs_->putString("restart-v1",id+"|"+beforeBoot+"|0|"+currentBoot))afterBoot=currentBoot;
  }
};
}
