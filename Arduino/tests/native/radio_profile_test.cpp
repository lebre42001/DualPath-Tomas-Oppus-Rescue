#include <cassert>
#include <iostream>
#include "radio_profile.h"
struct SettingsRadio {
  int sync=0,tx=0,sf=0,cr=0,gain=-1,preamble=0; long bw=0;
  uint8_t registers[128]={}; int failedRead=-1,ignoredWrite=-1,reads=0;
  SettingsRadio(){registers[0x42]=0x12;registers[0x01]=0x81;}
  uint8_t readRegister(int address){++reads;return address==failedRead?0xff:registers[address];}
  void writeRegister(int address,uint8_t value){if(address!=ignoredWrite)registers[address]=value;}
  void setSyncWord(int n){sync=n;writeRegister(0x39,n);} void setTxPower(int n){tx=n;}
  // These unsafe library calls must never be used by the profile startup.
  void setSpreadingFactor(int){assert(false);} void setSignalBandwidth(long){assert(false);}
  void setCodingRate4(int){assert(false);} void setGain(int n){gain=n;}
  void setPreambleLength(int n){preamble=n;writeRegister(0x20,n>>8);writeRegister(0x21,n);}
};
int main(){
  SettingsRadio radio;assert(AksyResRadio::configure(radio,radio));
  assert(radio.sync==0x34 && radio.tx==17 && radio.gain==0 && radio.preamble==8);
  assert(radio.registers[0x1d]==(AKSYRES_RADIO_PROFILE==1?0x74:0x72));
  assert(radio.registers[0x1e]==(AKSYRES_RADIO_PROFILE==1?0x90:0x70));
  assert(radio.registers[0x26]==0x04); // AGC on, LDRO/CRC unchanged for both profiles
  assert(AksyResRadio::spiFrequency==1000000);
  // Reproduce the reported invalid-bandwidth/SF startup state. Fixed values
  // repair it without asking LoRa 0.8.0 to divide by a hardware-derived zero.
  SettingsRadio invalid;invalid.registers[0x1d]=0xff;invalid.registers[0x1e]=0xff;
  assert(AksyResRadio::configure(invalid,invalid));
  for(int address:{0x42,0x01,0x1d,0x1e,0x26,0x31,0x37,0x39,0x20,0x21}){
    SettingsRadio broken;broken.failedRead=address;
    assert(!AksyResRadio::configure(broken,broken));assert(broken.reads<30);
  }
  for(int address:{0x1d,0x1e,0x26,0x31,0x37,0x39,0x21}){
    SettingsRadio broken;broken.ignoredWrite=address;
    assert(!AksyResRadio::configure(broken,broken));
  }
  SettingsRadio lost;lost.registers[0x42]=0;assert(!AksyResRadio::configure(lost,lost));
  assert(std::string(AksyResRadio::startupRegisters(lost).c_str()).find("version=0x0")!=std::string::npos);
  std::string empty=AksyResRadio::statusFields().c_str();
  assert(empty.find("\"radio_last_rssi\":null")!=std::string::npos);
  assert(empty.find("\"radio_last_snr\":null")!=std::string::npos);
  assert(empty.find("\"radio_last_age_ms\":null")!=std::string::npos);
  fakeClock=UINT32_MAX-10;AksyResRadio::record(-98,-12.5f,120,5);fakeClock=20;
  std::string measured=AksyResRadio::statusFields().c_str();
  assert(measured.find("\"radio_last_rssi\":-98")!=std::string::npos);
  assert(measured.find("\"radio_last_snr\":-12.50")!=std::string::npos);
  assert(measured.find("\"radio_last_age_ms\":31")!=std::string::npos);
  assert(measured.find("\"radio_valid_packets\":1")!=std::string::npos);
  assert(measured.find("\"radio_last_sender\":5")!=std::string::npos);
  std::cout<<"PASS profile "<<AKSYRES_RADIO_PROFILE<<": division-free verified startup, bad reads/ignored writes fail closed, matched settings, unchanged TX power, diagnostic age rollover.\n";
  std::cout<<"{"<<measured<<"\"test\":true}\n";
}
