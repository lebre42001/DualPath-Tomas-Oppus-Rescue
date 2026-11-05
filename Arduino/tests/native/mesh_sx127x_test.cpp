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
  SPI.registers[0x12]=0;SPI.registers[0x18]=0x02;assert(!port.receiving());
  // A stale synchronization indication must not permanently starve the queue.
  MeshLink::Pump<MeshSX127xPort> pump(port);pump.begin(7,0);
  assert(pump.enqueue("HELLO|NODE_2",0));
  for(fakeClock=0;fakeClock<300&&!pump.busy();++fakeClock)pump.tick(fakeClock);
  assert(pump.busy()&&(SPI.registers[0x01]&7)==7);
  SPI.registers[0x12]|=0x04;pump.tick(fakeClock+1);
  SPI.registers[0x12]|=0x08;pump.tick(fakeClock+2);
  assert(pump.transmitted==1&&pump.depth()==0);
  SPI.registers[0x12]=0;SPI.registers[0x18]=0x04;fakeClock=1000;assert(port.receiving());
  fakeClock+=port.rxGuardMs()-1;assert(port.receiving());
  // A completion arriving at the guard deadline must still be drained first.
  SPI.registers[0x12]=0x40;++fakeClock;assert(port.receiving());
  SPI.registers[0x12]=0;assert(port.receiving());
  fakeClock+=port.rxGuardMs();int recoveredBefore=LoRa.receiveCalls;
  SPI.registers[0x12]=0xb0;assert(!port.receiving());
  assert(LoRa.receiveCalls==recoveredBefore+1&&SPI.registers[0x12]==0);
  assert(std::string(port.statusFields().c_str()).find("\"radio_rx_recoveries\":1")!=std::string::npos);
  // The same bounded recovery works through millis() wraparound.
  SPI.registers[0x18]=0;assert(!port.receiving());
  SPI.registers[0x18]=4;fakeClock=UINT32_MAX-100;assert(port.receiving());
  fakeClock+=port.rxGuardMs();assert(!port.receiving());
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
  std::cout<<"PASS: SX127x sync-only queue progress, bounded stuck-RX recovery/rollover, RX_DONE preservation, CAD masks, authenticated async frames and return to receive.\n";
}
