#pragma once
#include "mesh_link.h"

// LoRa 0.8.0 has no public CAD API. Poll the documented SX127x registers over
// the existing SPI bus. Main task only; no callbacks/interrupt String allocations.
// Semtech SX1276/77/78/79 Rev7: RegOpMode 0x01, IRQ 0x12, ModemStat 0x18.
class MeshSX127xPort {
public:
  void begin(int ss){ss_=ss;}
  uint8_t flags(){return read(0x12);}
  bool receiving(){return (flags()&0x40)||(read(0x18)&0x06);}
  void startCad(){write(0x12,0x05);write(0x01,(read(0x01)&0xf8)|0x07);}
  void endCad(){write(0x12,0x05);LoRa.receive();}
  bool transmit(const String& packet){write(0x12,0x08);return meshWriteFrame(packet,true);}
  void endTx(){write(0x12,0x08);LoRa.idle();LoRa.receive();}
  uint32_t txTimeout(const String& packet){
    // Explicit header, 8-symbol preamble, existing CRC-disabled configuration.
    const int bytes=meshCompact(packet).length()+34,sf=AksyResRadio::spreadingFactor;
    const int numerator=8*bytes-4*sf+28,denominator=4*sf;
    const uint32_t symbols=8+((numerator>0?(numerator+denominator-1)/denominator:0)*AksyResRadio::codingDenominator);
    const uint32_t symbolUs=(1UL<<sf)*1000000UL/AksyResRadio::bandwidth;
    return ((symbols*4+49)*symbolUs/4+999)/1000+500;
  }
private:
  int ss_=5;
  uint8_t read(uint8_t address){SPI.beginTransaction(SPISettings(8000000,MSBFIRST,SPI_MODE0));digitalWrite(ss_,LOW);SPI.transfer(address&0x7f);uint8_t value=SPI.transfer(0);digitalWrite(ss_,HIGH);SPI.endTransaction();return value;}
  void write(uint8_t address,uint8_t value){SPI.beginTransaction(SPISettings(8000000,MSBFIRST,SPI_MODE0));digitalWrite(ss_,LOW);SPI.transfer(address|0x80);SPI.transfer(value);digitalWrite(ss_,HIGH);SPI.endTransaction();}
};
