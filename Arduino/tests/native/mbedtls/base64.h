#pragma once
#include <cstddef>
inline int mbedtls_base64_encode(unsigned char* out,size_t capacity,size_t* length,const unsigned char* in,size_t size){
  const char* alphabet="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  *length=((size+2)/3)*4;if(capacity<=*length)return -1;size_t at=0;
  for(size_t i=0;i<size;i+=3){unsigned v=unsigned(in[i])<<16;if(i+1<size)v|=unsigned(in[i+1])<<8;if(i+2<size)v|=in[i+2];
    out[at++]=alphabet[(v>>18)&63];out[at++]=alphabet[(v>>12)&63];out[at++]=i+1<size?alphabet[(v>>6)&63]:'=';out[at++]=i+2<size?alphabet[v&63]:'=';
  }out[at]=0;return 0;
}
