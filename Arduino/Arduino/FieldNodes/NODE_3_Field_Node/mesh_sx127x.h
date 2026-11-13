#pragma once
#include "mesh_link.h"

// LoRa 0.8.0 has no public CAD API. Poll the documented SX127x registers over
// the existing SPI bus. Main task only; no callbacks/interrupt String allocations.
// Semtech SX1276/77/78/79 Rev7: RegOpMode 0x01, IRQ 0x12, ModemStat 0x18.
class MeshSX127xPort {
public:
  void begin(int ss){ss_=ss;}
  uint8_t readRegister(uint8_t address){return read(address);}
  void writeRegister(uint8_t address,uint8_t value){write(address,value);}
  uint8_t flags(){return read(0x12);}
  bool receiving(){
    // RX_DONE belongs to the receive handler, even if the modem looks idle.
    if(flags()&0x40){rxGuardActive_=false;return true;}
    // SignalSynchronized (bit 1) is a modem diagnostic, not proof that a
    // payload is still arriving. CAD below protects preambles/channel activity.
    if(!(read(0x18)&0x04)){rxGuardActive_=false;return false;}
    const uint32_t now=millis();
    if(!rxGuardActive_){rxGuardActive_=true;rxGuardAt_=now;return true;}
    if(uint32_t(now-rxGuardAt_)<rxGuardMs())return true;
    // A maximum-length frame cannot still be arriving after this interval.
    // Recover a stuck RX indication, then let the pump run CAD; never force TX
    // over a busy CAD result or discard an already completed packet.
    if(flags()&0x40){rxGuardActive_=false;return true;}
    LoRa.idle();write(0x12,0xb0);LoRa.receive();
    rxGuardActive_=false;++rxRecoveries_;return false;
  }
  void startCad(){
    // Semtech Rev7 Fig11 starts CAD from standby. A direct RX_SINGLE/CONT ->
    // CAD request left the observed Ra-02 stuck in mode 7 without CAD_DONE.
    // Keep the LoRa/LF bits and preserve RX_DONE for the receive handler.
    const uint8_t mode=read(0x01)&0xf8;
    write(0x01,mode|0x01);
    write(0x12,0x05);
    write(0x01,mode|0x07);
  }
  void endCad(){write(0x12,0x05);LoRa.receive();}
  bool transmit(const String& packet){write(0x12,0x08);return meshWriteFrame(packet,true);}
  void endTx(){write(0x12,0x08);LoRa.idle();LoRa.receive();}
  uint32_t txTimeout(const String& packet){return airtimeMs(meshCompact(packet).length()+34)+500;}
  uint32_t rxGuardMs()const{return airtimeMs(255)+150;}
  String statusFields()const{
    return "\"radio_rx_recoveries\":"+String(rxRecoveries_)+
      ",\"radio_rx_guard_ms\":"+String(rxGuardMs())+",";
  }
private:
  bool rxGuardActive_=false;uint32_t rxGuardAt_=0,rxRecoveries_=0;
  static uint32_t airtimeMs(int bytes){
    // Explicit header, 8-symbol preamble, existing CRC-disabled configuration.
    const int sf=AksyResRadio::spreadingFactor;
    const int numerator=8*bytes-4*sf+28,denominator=4*sf;
    const uint32_t symbols=8+((numerator>0?(numerator+denominator-1)/denominator:0)*AksyResRadio::codingDenominator);
    const uint32_t symbolUs=(1UL<<sf)*1000000UL/AksyResRadio::bandwidth;
    return ((symbols*4+49)*symbolUs/4+999)/1000;
  }
  int ss_=5;
  uint8_t read(uint8_t address){SPI.beginTransaction(SPISettings(AksyResRadio::spiFrequency,MSBFIRST,SPI_MODE0));digitalWrite(ss_,LOW);SPI.transfer(address&0x7f);uint8_t value=SPI.transfer(0);digitalWrite(ss_,HIGH);SPI.endTransaction();return value;}
  void write(uint8_t address,uint8_t value){SPI.beginTransaction(SPISettings(AksyResRadio::spiFrequency,MSBFIRST,SPI_MODE0));digitalWrite(ss_,LOW);SPI.transfer(address|0x80);SPI.transfer(value);digitalWrite(ss_,HIGH);SPI.endTransaction();}
};
