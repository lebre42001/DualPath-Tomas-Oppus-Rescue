#include <cassert>
#include <iostream>
#define MESH_TEST_KEYS_HEADER "test_keys.h"
#include "mesh_security.h"
#include "radio_profile.h"
#include "fake_spi.h"
#include "mesh_sx127x.h"
int main(){
  assert(meshSecurityBegin());MeshSX127xPort port;port.begin(5);
  SPI.registers[0x12]=0x40;assert(port.receiving());
  SPI.registers[0x12]=0;SPI.registers[0x18]=0x02;assert(port.receiving());
  SPI.registers[0x18]=0x04;assert(port.receiving());
  SPI.registers[0x18]=0;assert(!port.receiving());
  SPI.registers[0x01]=0x8d;SPI.registers[0x12]=0x4d;port.startCad();
  assert(SPI.registers[0x01]==0x8f); // Preserve the long-range/LF bits, change only mode.
  assert(SPI.registers[0x12]==0x48); // CAD flags cleared; RX_DONE/TX_DONE preserved.
  SPI.registers[0x12]|=0x05;assert(port.flags()==0x4d);port.endCad();
  assert(SPI.registers[0x12]==0x48);
  const int before=LoRa.receiveCalls;assert(port.transmit("HELLO|NODE_2"));
  assert(LoRa.lastAsync);assert(LoRa.receiveCalls==before);assert(SPI.registers[0x12]==0x40);
  assert(meshTxCounter>0);auto frame=LoRa.tx;LoRa.inject(frame);assert(meshReadFrame(frame.size())=="HELLO|NODE_2");
  SPI.registers[0x12]|=0x08;port.endTx();assert(LoRa.receiveCalls==before+1&&SPI.registers[0x12]==0x40);
  const uint32_t timeout=port.txTimeout("HELLO|NODE_2");assert(timeout>500&&timeout<2000);
  std::cout<<"PASS: actual SX127x SPI register masks, RX preservation, CAD mode/LF bits, authenticated async frames, TX_DONE clearing and return to receive.\n";
}
