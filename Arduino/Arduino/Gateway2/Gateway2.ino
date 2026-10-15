#include <SPI.h>
#include <LoRa.h>

// Change this for each gateway
const String GATEWAY_ID = "G2";
const int GATEWAY_OFFSET_DELAY = 0;

// LoRa Pins
#define LORA_SS    5
#define LORA_RST   14
#define LORA_DIO0  4

String lastForwardedAlert = "";
unsigned long lastForwardTime = 0;

String getField(String data, char separator, int index) {
  int found = 0;
  int start = 0;

  for (int i = 0; i <= data.length(); i++) {
    if (data.charAt(i) == separator || i == data.length()) {
      if (found == index) {
        return data.substring(start, i);
      }
      found++;
      start = i + 1;
    }
  }

  return "";
}

int calculateForwardDelay(int rssi) {
  int baseDelay;

  if (rssi > -70) {
    baseDelay = 100;
  } else if (rssi > -90) {
    baseDelay = 250;
  } else {
    baseDelay = 500;
  }

  return baseDelay + GATEWAY_OFFSET_DELAY;
}

void forwardToReceiver(String alertMessage, int rssi, float snr) {
  String forwarded = "GW|";
  forwarded += GATEWAY_ID;
  forwarded += "|";
  forwarded += String(rssi);
  forwarded += "|";
  forwarded += String(snr, 1);
  forwarded += "|";
  forwarded += alertMessage;

  Serial.println("Forwarding:");
  Serial.println(forwarded);

  LoRa.beginPacket();
  LoRa.print(forwarded);
  LoRa.endPacket();
}

void forwardAckToSender(String message) {
  String command = getField(message, '|', 0);
  String gateway = getField(message, '|', 1);
  String senderId = getField(message, '|', 2);
  String seq = getField(message, '|', 3);

  if (gateway != GATEWAY_ID) {
    return;
  }

  String output = "";

  if (command == "NETACK") {
    output = "ACK|" + senderId + "|" + seq;
  } else if (command == "USERACK") {
    output = "ALERT_ACKNOWLEDGED|" + senderId + "|" + seq;
  }

  if (output.length() > 0) {
    Serial.println("Forwarding ACK to Sender:");
    Serial.println(output);

    delay(200);

    LoRa.beginPacket();
    LoRa.print(output);
    LoRa.endPacket();
  }
}

void setup() {
  Serial.begin(115200);

  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);

  if (!LoRa.begin(433E6)) {
    Serial.println("LoRa Init Failed!");
    while (1);
  }

  LoRa.setSyncWord(0x12);
  LoRa.enableCrc();

  Serial.println("Gateway Ready: " + GATEWAY_ID);
}

void loop() {
  int packetSize = LoRa.parsePacket();

  if (packetSize) {
    String message = "";

    while (LoRa.available()) {
      message += (char)LoRa.read();
    }

    int rssi = LoRa.packetRssi();
    float snr = LoRa.packetSnr();

    Serial.println("Received:");
    Serial.println(message);
    Serial.print("RSSI: ");
    Serial.print(rssi);
    Serial.print(" | SNR: ");
    Serial.println(snr);

    String command = getField(message, '|', 0);

    if (command == "ALERT") {
      String senderId = getField(message, '|', 1);
      String seq = getField(message, '|', 2);
      String uniqueId = senderId + "-" + seq;

      if (uniqueId != lastForwardedAlert || millis() - lastForwardTime > 10000) {
        lastForwardedAlert = uniqueId;
        lastForwardTime = millis();

        int forwardDelay = calculateForwardDelay(rssi);

        Serial.print("Forward delay: ");
        Serial.println(forwardDelay);

        delay(forwardDelay);

        forwardToReceiver(message, rssi, snr);
      } else {
        Serial.println("Duplicate ignored.");
      }
    }

    else if (command == "NETACK" || command == "USERACK") {
      forwardAckToSender(message);
    }
  }
}