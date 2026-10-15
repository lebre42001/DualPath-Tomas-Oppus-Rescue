# LoRa Mesh Hardware Deployment Checklist

This checklist covers the active official mesh: NODE_1 gateway and NODE_2 through NODE_10 field nodes. The website uses fixed coordinates, so no GPS module is required. The final design has no physical SOS button or switch; users submit emergencies from the ESP32 captive-portal dashboard.

## 1. Shared hardware wiring

Wire every SX1278, Ra-02, or compatible 433 MHz LoRa module to its ESP32 as follows:

| LoRa pin | ESP32 pin |
| --- | --- |
| NSS / CS | GPIO 5 |
| SCK / SLCK | GPIO 18 |
| MISO | GPIO 19 |
| MOSI | GPIO 23 |
| RST / RESET | GPIO 27 |
| DIO0 | GPIO 26 |
| VCC | Regulated 3.3 V |
| GND | Common GND |

Attach the correct 433 MHz antenna before powering or transmitting. Do not supply 5 V to the LoRa module. If using a battery and TPS63020, verify approximately 3.3 V with a multimeter before connecting the radio.

## 2. Arduino software

Install the ESP32 board support package and `LoRa by Sandeep Mistry` in Arduino IDE. Select the correct ESP32 board and COM port.

All ten boards must keep the same radio settings: 433 MHz, sync word `0x34`, spreading factor 7, bandwidth 125 kHz, and coding rate 4/5.

## 3. NODE_1 gateway

Copy `Arduino/Arduino/GatewayNode1/arduino_secrets.example.h` to `arduino_secrets.h` in the same folder. In the copied file, set the router or hotspot SSID, its password, the Laravel server base URL, the NODE_1 local AP password, and a dedicated gateway token. The same token must be present as `LORA_INGEST_TOKEN` in `web_system/.env`. The real secrets file is ignored by source control.

For local use, start Laravel on `0.0.0.0:8000` and use the computer's LAN IPv4 address, including `:8000`. Keep NODE_1 and the computer connected to the same router or phone hotspot.

Upload the gateway sketch to the physical NODE_1 ESP32. Open Serial Monitor at 115200 baud. The first successful target is:

```text
[API GET] /health -> 200
[API POST] /heartbeat -> 200
```

## 4. NODE_2 through NODE_10

Use the ready sketches in `Arduino/Arduino/FieldNodes/`. Each physical board must receive the matching sketch and unique `NODE_NUMBER`. Field nodes do not need the router Wi-Fi password, server URL, or Laravel token. Their Wi-Fi is a local captive portal only; all server traffic travels by LoRa through NODE_1.

User flow for every field node:

1. Connect the phone to the matching `Node n Emergency` Wi-Fi (for example, `Node 2 Emergency`).
2. Stay connected even if the phone reports that the network has no internet.
3. Use the dashboard that opens automatically. If it does not open, browse to `http://192.168.4.1`.
4. Select the emergency type, enter the short message, and submit it.
5. Keep the phone connected to view the queued and under-review status. After the admin or responder reviews the report, confirm that the review-completed popup shows the result, response message, and review time.

Upload and test one board at a time, beginning with NODE_2. At 115200 baud, confirm that it starts its matching `Node n Emergency` AP and transmits `HELLO`, `HB`, and receives `GW` packets. Then repeat for NODE_3 through NODE_10.

## 5. Fixed coordinates

Edit `web_system/config/lora_nodes.php` and replace each placeholder label and null coordinate for NODE_1 through NODE_10 with the physical installation location. Use decimal latitude and longitude values. Coordinates stay on the server and are not transmitted by GPS hardware.

After editing the registry, run `php artisan optimize:clear` from `web_system/`.

## 6. Website verification order

Run the migration, clear Laravel caches, build the frontend, and start the local services. Log in as admin or responder and open `/monitoring/lora-mesh`.

Verify in this order:

1. NODE_1 becomes `ACTIVE`.
2. NODE_2 becomes `ACTIVE` and its route ends at NODE_1.
3. Power NODE_2 off for more than 30 seconds and confirm it becomes `UNREACHABLE`.
4. Power it on and confirm it returns to `ACTIVE`.
5. Repeat heartbeat checks for NODE_3 through NODE_10.
6. Submit one SOS from a field-node captive portal and confirm it appears once in the official website.
7. Acknowledge the report in the website and confirm the ACK returns to the originating node.

`UNREACHABLE` means no heartbeat or SOS was received for more than 30 seconds. It does not by itself prove a hardware failure.

## 7. Battery voltage and transmission timing

The battery/RTT update needs new firmware on NODE_1 and all field nodes. Apply
Laravel migrations during deployment and rebuild assets. Older boards remain
compatible for heartbeats, but show no timing sample until upgraded.

For a 3.7 V single-cell battery with TPS63020, sense BAT+ before the buck-boost
through a 100k/100k voltage divider into GPIO34, with a 100 nF capacitor to common
GND. See `Arduino/README.md` for the exact diagram and calibration steps. Keep
`BATTERY_MONITOR_ENABLED=false` until the divider is installed and verified;
then enable it per board and upload. The regulated 3.3 V output cannot indicate
remaining battery charge. The website reports measured battery voltage only.

The delay shown is a complete round trip to the website and back. NODE_1 uses
its HTTPS request/response; field nodes probe through LoRa, NODE_1, HTTPS, and
back through LoRa. It is not one-way latency or "seconds ago." Check for actual
millisecond samples and ensure old samples become expired after two minutes.
