#pragma once
#include <algorithm>
#include <cstdint>
#include <cstring>
#include <cstdio>
#include <map>
#include <string>
#include <type_traits>
#include <vector>
#define HEX 16
class String {
  std::string value;
public:
  String() = default;
  String(const char* s): value(s ? s : "") {}
  String(const std::string& s): value(s) {}
  String(double n, int decimals) { char buffer[64]; std::snprintf(buffer, sizeof(buffer), "%.*f", decimals, n); value=buffer; }
  template<class T, typename std::enable_if<std::is_integral<T>::value, int>::type = 0>
  String(T n, int radix=10) {
    if (radix == 16) { const char* digits="0123456789abcdef"; do { value.insert(value.begin(), digits[n&15]); n>>=4; } while(n); }
    else value=std::to_string(n);
  }
  size_t length() const { return value.length(); }
  const char* c_str() const { return value.c_str(); }
  char operator[](size_t i) const { return value[i]; }
  int indexOf(char c, size_t start=0) const { auto p=value.find(c,start); return p==std::string::npos ? -1 : (int)p; }
  bool startsWith(const String& s) const { return value.rfind(s.value,0)==0; }
  String substring(size_t start, size_t end=std::string::npos) const { return value.substr(start,end==std::string::npos?end:end-start); }
  void trim() { auto first=value.find_first_not_of(" \r\n\t"),last=value.find_last_not_of(" \r\n\t"); value=first==std::string::npos?"":value.substr(first,last-first+1); }
  void replace(const String& from,const String& to) { size_t p=0;while((p=value.find(from.value,p))!=std::string::npos){value.replace(p,from.length(),to.value);p+=to.length();} }
  String& operator+=(const String& s) { value+=s.value;return *this; }
  String& operator+=(char c) { value+=c;return *this; }
  friend String operator+(const String& a,const String& b){return a.value+b.value;}
  friend bool operator==(const String& a,const String& b){return a.value==b.value;}
  friend bool operator!=(const String& a,const String& b){return !(a==b);}
  friend bool operator<(const String& a,const String& b){return a.value<b.value;}
};
inline uint32_t fakeClock = 0, fakeRandom = 1;
inline uint32_t millis(){return fakeClock;}
inline uint32_t esp_random(){return ++fakeRandom;}
struct FakeRadio {
  std::vector<uint8_t> tx,rx;size_t at=0;
  int receiveCalls=0;bool lastAsync=false;
  void idle(){} void beginPacket(){tx.clear();}void receive(){++receiveCalls;}
  void write(const uint8_t* data,size_t length){tx.assign(data,data+length);}
  int endPacket(bool async=false){lastAsync=async;return 1;}int available(){return at<rx.size();}int read(){return rx[at++];}
  void inject(const std::vector<uint8_t>& data){rx=data;at=0;}
};
inline FakeRadio LoRa;
