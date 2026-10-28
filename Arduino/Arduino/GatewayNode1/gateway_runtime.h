#pragma once
#include <Arduino.h>
#include <freertos/FreeRTOS.h>
#include <freertos/queue.h>
#include <freertos/task.h>

// Strings stay owned by one task at a time; queues transfer only job pointers.
struct GatewayApiWork {
  String method, path, body, response;
  int status = -1;
  unsigned long elapsedMs = 0;
};

struct GatewayRadioItem {
  String packet;
  int rssi = 0;
  float snr = 0;
  unsigned long attemptedAtMs = 0;
  unsigned long receivedAtMs = 0;
  bool attempted = false;
};
