#pragma once
// Host adapter uses Windows' cryptographic provider, not a replacement hash.
// ESP32 compilation uses the real mbedTLS shipped with the ESP32 core.
#define NOMINMAX
#include <windows.h>
#include <bcrypt.h>
#define MBEDTLS_MD_SHA256 1
struct mbedtls_md_info_t {};
inline const mbedtls_md_info_t* mbedtls_md_info_from_type(int){static mbedtls_md_info_t info;return &info;}
inline int mbedtls_md_hmac(const mbedtls_md_info_t*,const unsigned char* key,size_t keyLength,const unsigned char* input,size_t length,unsigned char* output){
  BCRYPT_ALG_HANDLE algorithm=nullptr;BCRYPT_HASH_HANDLE hash=nullptr;
  if(BCryptOpenAlgorithmProvider(&algorithm,BCRYPT_SHA256_ALGORITHM,nullptr,BCRYPT_ALG_HANDLE_HMAC_FLAG)<0)return -1;
  auto result=BCryptCreateHash(algorithm,&hash,nullptr,0,const_cast<PUCHAR>(key),(ULONG)keyLength,0);
  if(result>=0)result=BCryptHashData(hash,const_cast<PUCHAR>(input),(ULONG)length,0);
  if(result>=0)result=BCryptFinishHash(hash,output,32,0);
  if(hash)BCryptDestroyHash(hash);BCryptCloseAlgorithmProvider(algorithm,0);return result<0?-1:0;
}
