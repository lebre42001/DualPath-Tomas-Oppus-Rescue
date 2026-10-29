#pragma once
#include <Arduino.h>

namespace MeshLink {
inline String field(const String& data, int wanted) {
  int start=0;for(int i=0;i<wanted;++i){start=data.indexOf('|',start);if(start<0)return "";++start;}
  int end=data.indexOf('|',start);return data.substring(start,end<0?data.length():end);
}
inline bool due(uint32_t now,uint32_t at){return int32_t(now-at)>=0;}
inline uint8_t priority(const String& type){return type=="SOS"||type=="ACK"?3:type=="HELLO"||type=="HELLO_ACK"||type=="GW"?2:1;}
inline uint32_t lifetime(const String& type){return type=="HB"?3000:type=="GW"||type=="HELLO"||type=="HELLO_ACK"?2500:type=="NET"?10000:45000;}
inline String key(const String& packet){
  String type=field(packet,0),result=type+":"+field(packet,1);
  if(type=="NET")return "NET";
  if(type=="ACK")result+=":"+field(packet,4);
  if(type=="HELLO_ACK"||type=="PING"||type=="PONG")result+=":"+field(packet,2);
  return result;
}

// The port owns CAD/TX register access. No blocking delays, ISR String work or
// authentication counters occur while a packet is merely waiting in this queue.
template<class Port> class Pump {
public:
  static constexpr int capacity=24, controlLimit=16;
  struct Item {String packet,key,type;uint32_t born=0,dueAt=0,order=0;uint8_t rank=0,copies=1,attempts=0;bool used=false;};
  uint32_t transmitted=0,failed=0,deferred=0,cadTimeouts=0,dropped=0,expired=0,coalesced=0;
  explicit Pump(Port& port,void(*sent)(const String&)=nullptr):port_(port),sent_(sent){}
  void begin(uint32_t seed,uint32_t now=millis()){rng_=seed?seed:0xa5f019d3;cooldown_=now;}
  bool busy()const{return state_!=Idle;}
  int depth()const{int n=state_==Tx?1:0;for(const auto& item:items_)if(item.used)++n;return n;}
  bool finalAckPending()const{
    if(state_==Tx&&active_.type=="ACK"&&field(active_.packet,4)!="RECEIVED")return true;
    for(const auto& item:items_)if(item.used&&item.type=="ACK"&&field(item.packet,4)!="RECEIVED")return true;
    return false;
  }
  void cancel(const String& id){
    for(int i=0;i<capacity;++i)if(items_[i].used&&items_[i].key==id){
      if(state_==Cad&&selected_==i){port_.endCad();state_=Idle;selected_=-1;}
      clear(i);
    }
  }
  bool enqueue(const String& packet,uint32_t now,uint8_t copies=1){
    if(!packet.length()){++dropped;return false;}
    const String type=field(packet,0),id=key(packet);const uint8_t rank=priority(type);
    if(state_==Tx&&active_.key==id&&active_.packet==packet){++coalesced;return true;}
    // A final signed response supersedes an unsent nonfinal server receipt.
    if(type=="ACK"&&field(packet,4)!="RECEIVED"){
      for(int i=0;i<capacity;++i)if(i!=selected_&&items_[i].used&&items_[i].type=="ACK"&&field(items_[i].packet,1)==field(packet,1)&&field(items_[i].packet,4)=="RECEIVED")clear(i);
    }
    for(int i=0;i<capacity;++i)if(items_[i].used&&items_[i].key==id){
      Item& item=items_[i];++coalesced;
      if(item.packet==packet)return true; // Duplicates cannot postpone a pending send.
      if(type=="SOS"&&packet.length()>=item.packet.length())return true;
      if(type=="ACK"&&field(packet,5)<field(item.packet,5))return true;
      if(type=="GW"&&field(packet,2)==field(item.packet,2)&&packet.length()>=item.packet.length())return true;
      item.packet=packet;item.born=now;item.copies=copies;return true;
    }
    int slot=space(rank);if(slot<0){++dropped;return false;}
    Item& item=items_[slot];item.packet=packet;item.key=id;item.type=type;item.born=now;
    item.rank=rank;item.copies=copies?copies:1;item.attempts=0;item.order=++order_;
    item.dueAt=now+randomRange(rank==3?15:35,rank==3?95:240);item.used=true;return true;
  }
  void tick(uint32_t now){
    if(state_==Tx){
      const uint8_t flags=port_.flags();const bool success=(flags&0x08)!=0;
      if(!success&&!due(now,deadline_))return;
      port_.endTx();state_=Idle;cooldown_=now+randomRange(25,90);
      if(success){++transmitted;if(active_.rank==3)++criticalBurst_;else criticalBurst_=0;if(sent_)sent_(active_.packet);}
      else ++failed;
      if((success&&active_.copies>1)||(!success&&active_.rank==3)){
        if(success)--active_.copies;
        restore(active_,now,success?randomRange(900,1600):randomRange(250,900));
      }
      active_=Item{};return;
    }
    if(state_==Cad){
      const uint8_t flags=port_.flags();
      if(flags&0x40){port_.endCad();state_=Idle;backoff(selected_,now);selected_=-1;return;}
      if(!(flags&0x04)&&!due(now,deadline_))return;
      const bool timedOut=!(flags&0x04),channelBusy=(flags&0x01)!=0;
      port_.endCad();state_=Idle;
      if(timedOut||channelBusy){if(timedOut)++cadTimeouts;backoff(selected_,now);selected_=-1;return;}
      const int index=selected_;selected_=-1;
      if(!items_[index].used)return;
      if(uint32_t(now-items_[index].born)>lifetime(items_[index].type)){++expired;clear(index);return;}
      active_=items_[index];clear(index);
      if(!port_.transmit(active_.packet)){++failed;restore(active_,now,randomRange(250,900));active_=Item{};return;}
      state_=Tx;deadline_=now+port_.txTimeout(active_.packet);return;
    }
    expire(now);if(!due(now,cooldown_))return;
    int choice=-1;bool controlDue=false;
    for(int i=0;i<capacity;++i)if(items_[i].used&&due(now,items_[i].dueAt)&&items_[i].rank<3)controlDue=true;
    for(int i=0;i<capacity;++i){const Item& item=items_[i];if(!item.used||!due(now,item.dueAt))continue;
      if(criticalBurst_>=3&&controlDue&&item.rank==3)continue;
      if(choice<0||item.rank>items_[choice].rank||(item.rank==items_[choice].rank&&int32_t(item.order-items_[choice].order)<0))choice=i;
    }
    if(choice<0)return;
    // Preserve a packet already being received, including an unconsumed RX_DONE.
    if(port_.receiving()){++deferred;items_[choice].dueAt=now+randomRange(15,55);return;}
    selected_=choice;state_=Cad;deadline_=now+75;port_.startCad();
  }
  String statusFields()const{
    return "\"radio_tx_queue\":"+String(depth())+",\"radio_tx_done\":"+String(transmitted)+
      ",\"radio_tx_failed\":"+String(failed)+",\"radio_channel_deferrals\":"+String(deferred)+
      ",\"radio_cad_timeouts\":"+String(cadTimeouts)+",\"radio_queue_dropped\":"+String(dropped)+
      ",\"radio_queue_expired\":"+String(expired)+",\"radio_queue_coalesced\":"+String(coalesced)+",";
  }
private:
  enum State{Idle,Cad,Tx};State state_=Idle;Port& port_;void(*sent_)(const String&);
  Item items_[capacity],active_;int selected_=-1;uint8_t criticalBurst_=0;
  uint32_t rng_=1,order_=0,deadline_=0,cooldown_=0;
  uint32_t randomRange(uint32_t low,uint32_t high){rng_^=rng_<<13;rng_^=rng_>>17;rng_^=rng_<<5;return low+rng_%(high-low);}
  void clear(int i){items_[i]=Item{};}
  void expire(uint32_t now){for(int i=0;i<capacity;++i)if(items_[i].used&&uint32_t(now-items_[i].born)>lifetime(items_[i].type)){++expired;clear(i);}}
  int space(uint8_t rank){
    int empty=-1,control=0,victim=-1;
    for(int i=0;i<capacity;++i){if(!items_[i].used){if(empty<0)empty=i;continue;}if(items_[i].rank<3)++control;
      if(i!=selected_&&items_[i].rank<rank&&(victim<0||items_[i].rank<items_[victim].rank))victim=i;
    }
    if(empty>=0&&(rank==3||control<controlLimit))return empty;
    if(victim>=0){++dropped;clear(victim);return victim;}return -1;
  }
  void backoff(int i,uint32_t now){
    ++deferred;if(!items_[i].used)return;
    if(items_[i].attempts<4)++items_[i].attempts;
    items_[i].dueAt=now+randomRange(35,80U<<items_[i].attempts);
  }
  void restore(const Item& item,uint32_t now,uint32_t delayMs){
    if(uint32_t(now-item.born)>lifetime(item.type)){++expired;return;}
    for(const auto& waiting:items_)if(waiting.used&&waiting.key==item.key)return;
    int slot=space(item.rank);if(slot<0){++dropped;return;}
    items_[slot]=item;items_[slot].used=true;items_[slot].dueAt=now+delayMs;items_[slot].order=++order_;
  }
};

class Discovery {
public:
  void begin(uint32_t now,uint8_t node,uint32_t seed){node_=node;seed_=seed;since_=now;next_=now+uint32_t(node)*61+seed%250;started_=true;}
  bool ready(uint32_t now,bool hasPeer){
    if(!started_)begin(now,node_,seed_);
    if(wasPeer_&&!hasPeer){since_=now;next_=now+100+(seed_++%450);}
    wasPeer_=hasPeer;if(!due(now,next_))return false;
    const bool acquiring=!hasPeer&&uint32_t(now-since_)<20000;
    seed_=seed_*1664525UL+1013904223UL;
    next_=now+(acquiring?1800:14000)+seed_%1200;return true;
  }
private:
  uint32_t since_=0,next_=0,seed_=1;uint8_t node_=2;bool wasPeer_=false,started_=false;
};

class BeaconTracker {
public:
  bool accept(const String& sequence,uint32_t now){
    int separator=sequence.indexOf(':');String epoch=separator<0?String("legacy"):sequence.substring(0,separator);
    String digits=separator<0?sequence:sequence.substring(separator+1);uint32_t number=0;
    if(!digits.length()||digits.length()>10||!epoch.length()||epoch.length()>8)return false;
    if(separator>=0)for(size_t i=0;i<epoch.length();++i){char c=epoch[i];if(!((c>='0'&&c<='9')||(c>='a'&&c<='f')))return false;}
    for(size_t i=0;i<digits.length();++i){char c=digits[i];if(c<'0'||c>'9'||number>(UINT32_MAX-uint32_t(c-'0'))/10)return false;number=number*10+uint32_t(c-'0');}
    if(current_==epoch){
      if(int32_t(number-last_)<0&&!(epoch=="legacy"&&uint32_t(now-seen_)>30000))return false;
    }else{
      for(int i=0;i<4;++i)if(retired_[i]==epoch&&uint32_t(now-retiredAt_[i])<120000)return false;
      if(current_.length()){retired_[write_]=current_;retiredAt_[write_]=now;write_=(write_+1)%4;}
    }
    current_=epoch;last_=number;seen_=now;return true;
  }
private:
  String current_,retired_[4];uint32_t retiredAt_[4]={},last_=0,seen_=0;int write_=0;
};
}
