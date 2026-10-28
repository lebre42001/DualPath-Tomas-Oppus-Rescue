#include <cassert>
#include <iostream>
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "mesh_security.h"
#include "report_guard.h"

static String sos(int origin,uint32_t seq,const String& message="Crash, injuries") {
  return "SOS|NODE_"+String(origin)+"-"+String(seq)+"|NODE_"+String(origin)+"|1793145600|R|"+message+"|NA|0|12|NODE_"+String(origin)+"|Bogo bridge";
}
int main(){
  assert(meshSecurityBegin());
  // RFC 4231 case 1; zero extending its 20-byte key is HMAC-equivalent.
  uint8_t key[32]={};for(int i=0;i<20;++i)key[i]=0x0b;
  assert(meshTag(key,String("Hi There"))=="sDRMYdjbOFNcqK_OrwvxKw");
  String original=meshSignOrigin(sos(2,1),false,2);
  assert(meshVerifyOrigin(original,false,2));assert(!meshVerifyOrigin(original,false,3));
  String relay=original;relay.replace("|0|12|NODE_2|","|1|11|NODE_2>NODE_3|");assert(meshVerifyOrigin(relay,false,2));
  String altered=original;altered.replace("Bogo bridge","False address");assert(!meshVerifyOrigin(altered,false,2));
  altered=original;altered.replace("1793145600","1793145601");assert(!meshVerifyOrigin(altered,false,2));
  assert(!meshVerifyOrigin(meshSignOrigin(sos(2,2),false,3),false,2));
  assert(meshAcceptSos(original,false));assert(meshAcceptSos(original,true));assert(meshAcceptSos(original,false));
  assert(meshAcceptSos(meshSignOrigin(sos(2,2),false,2),true));
  assert(!meshAcceptSos(original,false));
  assert(!meshAcceptSos(meshSignOrigin(sos(2,2,"Changed message"),false,2),false));
  assert(meshSecurityBegin());assert(!meshAcceptSos(original,false)); // Durable across reboot.
  const String ack=meshSignOrigin("ACK|NODE_2-2|NODE_2|NODE_1|ACKNOWLEDGED|2026-10-28T00:00:00Z|12|NODE_1|Team checking|Confirmed",true,2);
  assert(meshVerifyOrigin(ack,true,2));assert(!meshVerifyOrigin(ack,false,2));
  altered=ack;altered.replace("Confirmed","False report");assert(!meshVerifyOrigin(altered,true,2));
  assert(meshCompact(meshExpand(meshCompact("~N12 ~~ NODE_2")))==meshCompact("~N12 ~~ NODE_2"));
  assert(meshWriteFrame(original));auto frame=LoRa.tx;LoRa.inject(frame);assert(meshReadFrame(frame.size())==original);
  LoRa.inject(frame);assert(meshReadFrame(frame.size()).length()==0); // replay
  frame.back()^=1;LoRa.inject(frame);assert(meshReadFrame(frame.size()).length()==0);
  frame=LoRa.tx;frame[3]=2;LoRa.inject(frame);assert(meshReadFrame(frame.size()).length()==0); // forged sender
  LoRa.inject({'S','O','S','|'});assert(meshReadFrame(4).length()==0); // unsigned
  auto before=meshTxCounter;assert(meshSecurityBegin());assert(meshNextCounter()>before);
  Preferences::failWrites=true;meshTxCounter=meshTxLimit;assert(meshNextCounter()==0);assert(meshNextCounter()==0);Preferences::failWrites=false;
  assert(meshSecurityBegin());
  String msg,landmark;for(int i=0;i<70;++i)msg+='a';for(int i=0;i<32;++i)landmark+='b';
  assert(meshReportFits(msg,landmark,"4.20 V"));
  String worst=sos(10,UINT32_MAX,msg);worst.replace("Bogo bridge",landmark);String route;
  for(int n=1;n<=10;++n)route+=(n==1?"":">")+String("NODE_")+String(n);
  worst.replace("|0|12|NODE_10|","|10|12|"+route+"|");
  assert(meshWriteFrame(meshSignOrigin(worst,false,10)));assert(LoRa.tx.size()<=255);
  String excessive;for(int i=0;i<70;++i)excessive+='~';assert(!meshReportFits(excessive,landmark,"4.20 V"));
  assert(reportGuardAccept(1,0));assert(reportRetrySeconds(1,0)==60);assert(!reportGuardAccept(1,59999));
  assert(reportGuardAccept(2,1));assert(reportGuardAccept(1,60000));
  for(auto& c:reportClients)c={};
  assert(reportGuardAccept(1,UINT32_MAX-100));assert(reportRetrySeconds(1,100)==60); // millis rollover
  for(auto& c:reportClients)c={};
  for(uint32_t n=1;n<=16;++n)assert(reportGuardAccept(n,0));assert(!reportGuardAccept(17,0));assert(reportGuardAccept(17,60000));
  String body=reportConfirmationBody("Crash","Bridge","ROAD_ACCIDENT","1793145600");fakeClock=100;
  String token=reportPrepare(1,body);assert(token.length()==22);
  assert(!reportConsumeConfirmation(2,body,token));assert(!reportConsumeConfirmation(1,body+"changed",token));
  assert(reportConsumeConfirmation(1,body,token));assert(!reportConsumeConfirmation(1,body,token));
  token=reportPrepare(1,body);fakeClock+=120000;assert(!reportConsumeConfirmation(1,body,token));
  std::cout<<"PASS: HMAC vector, origin isolation, mutable routes, durable SOS replay, ACK integrity, authenticated frames, counter reboot/write failure, full mesh radio budget, cooldown/rollover/capacity, one-use confirmation.\n";
}
