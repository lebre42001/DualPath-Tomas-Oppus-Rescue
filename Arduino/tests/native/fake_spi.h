#pragma once
#include <cstdint>
#define LOW 0
#define HIGH 1
#define MSBFIRST 1
#define SPI_MODE0 0
struct SPISettings {SPISettings(uint32_t,int,int){}};
struct TestSPI {
  uint8_t registers[128]={};int phase=0;uint8_t address=0;bool writing=false;
  void beginTransaction(SPISettings){phase=0;}
  void endTransaction(){}
  uint8_t transfer(uint8_t value){
    if(phase++==0){writing=value&0x80;address=value&0x7f;return 0;}
    if(!writing)return registers[address];
    if(address==0x12)registers[address]&=~value;else registers[address]=value;return 0;
  }
};
inline TestSPI SPI;
inline void digitalWrite(int,int){}
