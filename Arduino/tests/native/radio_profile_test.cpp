#include <cassert>
#include <iostream>
#include "radio_profile.h"
struct SettingsRadio {
  int sync=0,tx=0,sf=0,cr=0,gain=-1,preamble=0; long bw=0;
  void setSyncWord(int n){sync=n;} void setTxPower(int n){tx=n;}
  void setSpreadingFactor(int n){sf=n;} void setSignalBandwidth(long n){bw=n;}
  void setCodingRate4(int n){cr=n;} void setGain(int n){gain=n;}
  void setPreambleLength(int n){preamble=n;}
};
int main(){
  SettingsRadio radio;AksyResRadio::configure(radio);
  assert(radio.sync==0x34 && radio.tx==17 && radio.bw==125000 && radio.gain==0 && radio.preamble==8);
  assert(radio.sf==(AKSYRES_RADIO_PROFILE==1?9:7));
  assert(radio.cr==(AKSYRES_RADIO_PROFILE==1?6:5));
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
  std::cout<<"PASS profile "<<AKSYRES_RADIO_PROFILE<<": matched settings, unchanged TX power, null missing data, weak-packet diagnostics, monotonic age rollover.\n";
  std::cout<<"{"<<measured<<"\"test\":true}\n";
}
