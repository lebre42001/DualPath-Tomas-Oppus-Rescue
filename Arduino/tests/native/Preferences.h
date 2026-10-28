#pragma once
#include "Arduino.h"
class Preferences {
  std::string space;
public:
  inline static std::map<std::string,uint64_t> numbers;
  inline static std::map<std::string,String> strings;
  inline static bool failWrites=false;
  bool begin(const char* name,bool){space=name;return true;}
  uint64_t getULong64(const char* key,uint64_t fallback=0){auto p=numbers.find(space+key);return p==numbers.end()?fallback:p->second;}
  size_t putULong64(const char* key,uint64_t value){if(failWrites)return 0;numbers[space+key]=value;return 8;}
  String getString(const char* key,const char* fallback=""){auto p=strings.find(space+key);return p==strings.end()?String(fallback):p->second;}
  size_t putString(const char* key,const String& value){if(failWrites)return 0;strings[space+key]=value;return value.length();}
};
