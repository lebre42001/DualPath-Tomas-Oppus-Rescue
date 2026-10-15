# Arduino LoRa Hardware Integration

The active mesh uses `GatewayNode1/GatewayNode1.ino` for NODE_1 and the configurable `FieldNode/FieldNode.ino` sketch for NODE_2 through NODE_10. The older `Sender_GPS`, `Reciever`, `Gateway1`, and `Gateway2` sketches are legacy prototypes and do not use the active mesh protocol.

## Active Mesh Flow

1. A phone joins the selected node's local Wi-Fi AP and is redirected to that ESP32's captive-portal dashboard. The user submits the emergency from the dashboard. This works without mobile data or internet.
2. NODE_2 through NODE_10 assign a persistent message ID and creation time, then keep the SOS packet queued on the originating node.
3. Nodes discover direct radio peers, relay heartbeats and SOS packets with route/TTL data, and suppress duplicate relays.
4. NODE_1 advertises a server route only after the configured Laravel API responds successfully. If the route disappears, field nodes retain pending SOS packets and retry when the route returns.
5. NODE_1 maps SOS and heartbeat packets to the official authenticated Laravel API. Laravel deduplicates reports, records node heartbeat/route state, and uses the static node registry for report coordinates.
6. When a responder moves a LoRa report from `received` to another response state, Laravel queues a manual ACK. NODE_1 polls it and broadcasts it through the mesh; the originating field node clears its saved SOS only after receiving that ACK.

### Finding and acknowledging a field report

Open `https://dualpath.bsit4bdevs.tech/monitoring/lora-mesh` as an admin. Each
node card now links its latest emergency report and offers **Acknowledge** while
that report is still `received`. **View LoRa reports** opens the complete report
list; searching the radio message ID (for example `NODE_2-1`) also finds it.
Viewing a report does not acknowledge it. Click **Acknowledge** to send the manual
review notification. Use Respond, Set Done, or Cancel with a response message
for subsequent decisions.

The phone must remain connected to the originating node's Wi-Fi and its local
dashboard to see the returned message/popup. A gateway broadcast is not proof
that the field node received the ACK. If the node retries a report that was
already reviewed, Laravel queues the ACK again without creating a second report.
Later status or response-message changes also queue a fresh notification. The
existing NODE_1 and field firmware support this backend update; no reupload is
needed. This does not add a phone push notification while disconnected from the
node. Fixed map coordinates still require actual installation coordinates in
`web_system/config/lora_nodes.php`.

The deployed design intentionally has no physical SOS button or switch. Emergency submission is always performed from the local dashboard after the user connects to the selected ESP32 node's Wi-Fi. If the captive portal does not open automatically, the user can browse to `http://192.168.4.1` while remaining connected to the node Wi-Fi. After submission, the field-node dashboard shows that the report is under admin review. When the admin or responder reviews it, the originating node displays a persistent review-completed popup with the result, response message, and review time.

Field nodes send a heartbeat every 10 seconds, so NODE_1 can mark them active on the monitoring page without a user opening the local dashboard. Heartbeats are scheduled before peer discovery, and field nodes learn peers from received `HELLO` packets without echoing field-to-field acknowledgments. This keeps NODE_2 through NODE_10 from flooding the shared LoRa channel when several boards start together. NODE_1 still acknowledges a field node's `HELLO`, allowing the field node to identify a direct gateway peer.

GPS is not required. Battery voltage sensing is optional and requires the divider described below. Coordinates are fixed per sender in `web_system/config/lora_nodes.php`. Configure `node_name`, `location_label`, `latitude`, and `longitude` for NODE_1 through NODE_10. `S01` remains as a legacy entry. Laravel ignores coordinates and satellite values carried in a received radio packet.

## Uploading the Field Nodes

Ready-to-upload sketches are generated in `Arduino/Arduino/FieldNodes/` for NODE_2 through NODE_10. Open the sketch whose folder and filename match the physical board, verify its `NODE_NUMBER`, and upload it. The shared source template remains `Arduino/Arduino/FieldNode/FieldNode.ino`.

Before generating the field-node sketches, copy `Arduino/Arduino/FieldNode/field_node_secrets.example.h` to `field_node_secrets.h` in the same folder and replace the placeholder local-dashboard password. The configured file is ignored by Git. The generator copies that local header beside each generated sketch for hardware upload while only the safe example header is published.

If the shared field-node template changes, regenerate all nine sketches from the repository root:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Arduino\generate_field_node_sketches.ps1
```

Each node exposes a local AP named `Node 2 Emergency` through `Node 10 Emergency`. Change `LOCAL_AP_PASSWORD` in the ignored `FieldNode/field_node_secrets.h` before regeneration if a different local dashboard password is required.

## NODE_1 Gateway Setup

### Official HTTPS website

The official gateway destination is `https://dualpath.bsit4bdevs.tech`.
The local ignored `GatewayNode1/arduino_secrets.h` is configured for this origin,
with the existing Wi-Fi credentials and gateway token preserved. The authenticated
health, network-summary, and pending-ACK endpoints returned HTTP 200 on
2026-10-15 using that gateway token; the home and login pages also returned 200.
These checks do not confirm a physical ESP32 upload or a report round trip.

`server_root_ca.h` contains the public ISRG Root X1 certificate, verified against
the domain's trusted certificate chain. Use
`const char* SERVER_ROOT_CA_CERT = DUALPATH_ROOT_CA;` in `arduino_secrets.h`.
NODE_1 waits for internet time from NTP before making a verified HTTPS request.
If the hosting certificate chain changes to a different root authority, update
the public CA header from that authority's official source and upload again.

Open `Arduino/Arduino/GatewayNode1/GatewayNode1.ino` in Arduino IDE and upload
the sketch to NODE_1. Keep `arduino_secrets.h` and `server_root_ca.h` in that same
folder; the IDE compiles them with the sketch. Do not upload the headers as
separate sketches. Monitor at 115200 baud for:

```text
Laravel server: https://dualpath.bsit4bdevs.tech
[API GET] /health -> 200
[API POST] /heartbeat -> 200
```

Then log in to `https://dualpath.bsit4bdevs.tech/monitoring/lora-mesh` as an
admin/responder and check that NODE_1 becomes ACTIVE. NODE_2 through NODE_10
still communicate through LoRa to NODE_1; they need no domain or Wi-Fi changes
for this migration. Power them on and confirm their relayed heartbeats appear
online. To validate the complete report path, submit a clearly identified test
report from a node's local dashboard, review it on the hosted website, and
confirm the originating node displays the responder acknowledgment.

NODE_1's configured Wi-Fi must have internet access. The existing laptop mobile
hotspot can share the laptop's internet, but the local Laravel server and inbound
TCP 8000 firewall rule are no longer needed for this hosted destination. Changing
to an unrelated hotspot still requires its SSID/password in `arduino_secrets.h`.
The laptop must keep Mobile Hotspot switched on; being connected to the phone's
hotspot does not automatically enable the laptop's own hotspot. Disable
"When no devices are connected, automatically turn off mobile hotspot" in
Windows Mobile Hotspot settings if the gateway needs to reconnect later.
`Wi-Fi status code: 6` with `Router unavailable` means the Wi-Fi connection has
not succeeded, before any HTTPS/API request is made. Check that the configured
hotspot is on and its name/password still match before changing the certificate.

After uploading, the gateway's `/status` endpoint includes `server_base_url`,
`gateway_status`, `wifi_connected`, and `wifi_ip` for diagnosing the running
firmware. Verify `server_base_url` is `https://dualpath.bsit4bdevs.tech`.
If the laptop's local dashboard shows NODE_1 active while the hosted dashboard
does not, check whether the board is still running firmware with the old local
server URL; saving a new header on the laptop does not update a running ESP32.

If requests return 401/403, match the gateway token to the hosted
`LORA_INGEST_TOKEN`; never publish that value. A 429 means the API rate limit was
reached, while a negative status means no successful HTTP response was received.

### Configuration for another installation

Copy `Arduino/Arduino/GatewayNode1/arduino_secrets.example.h` to `arduino_secrets.h` in the same folder. Set the installation-specific values in the copied file before upload:

```cpp
const char* ROUTER_WIFI_SSID = "YOUR_WIFI_NAME";
const char* ROUTER_WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_BASE_URL = "http://YOUR_LARAGON_LAN_IP";
const char* LORA_GATEWAY_TOKEN = "SET_THE_SAME_LORA_INGEST_TOKEN";
```

For local/offline-network use, include port `8000` in the Laragon computer's LAN address, for example `http://192.168.1.23:8000`. NODE_1 and the computer must be on the same router or phone hotspot. Do not use `localhost` or `127.0.0.1` because those addresses point back to the ESP32 itself. For an HTTPS deployment, set the HTTPS URL and configure `SERVER_ROOT_CA_CERT` with the trusted root certificate PEM; certificate checking is not disabled. Set the same `LORA_INGEST_TOKEN` value in Laravel. Keep real credentials out of source control.

### Current Laragon demo network

The verified local setup uses the laptop's Windows Mobile Hotspot as the stable network between NODE_1 and Laravel:

```text
Any upstream Wi-Fi or phone hotspot
            -> laptop
            -> Windows Mobile Hotspot
            -> NODE_1
            -> Laravel at http://192.168.137.1:8000
```

The laptop may change its upstream internet connection without changing the NODE_1 firmware. Keep the Windows Mobile Hotspot name and password unchanged, turn that hotspot on before powering NODE_1, and keep the Laravel server bound to `0.0.0.0:8000`. Windows Firewall must allow inbound TCP port `8000` from `192.168.137.0/24`. If Windows turns the mobile hotspot off while it has no clients, turn it on again before NODE_1 starts. Installation credentials remain only in the ignored `GatewayNode1/arduino_secrets.h` file.

For HTTPS, paste the trusted PEM as a C++ raw string:

```cpp
const char* SERVER_ROOT_CA_CERT = R"PEM(
-----BEGIN CERTIFICATE-----
PASTE_THE_TRUSTED_ROOT_CA_CERTIFICATE_HERE
-----END CERTIFICATE-----
)PEM";
```

NODE_1 also starts a captive portal on its `NODE_1_Gateway` Wi-Fi AP. Connect a phone to that network and open the page offered by the phone; if it does not open automatically, browse to `http://192.168.4.1`. The page shows the server route, nearby LoRa peers, and active-node count, and can submit an emergency from NODE_1 itself. NODE_1 stores that local report in ESP32 Preferences and retries it after the Laravel route returns. Its responder acknowledgment is shown in a popup and the report is cleared from the retry queue. The gateway uses the same LoRa pins and radio settings as the field nodes.

## Shared Radio Configuration

All active mesh boards must use the same radio configuration:

```text
Frequency: 433 MHz
Sync word: 0x34
CS/NSS: GPIO 5
SCK: GPIO 18
MISO: GPIO 19
MOSI: GPIO 23
RST: GPIO 27
DIO0: GPIO 26
Spreading factor: 7
Bandwidth: 125 kHz
Coding rate: 4/5
```

Use a 3.3 V supply for the SX1278/Ra-02/XL1278 and attach the antenna before transmitting.

## Battery voltage: 3.7 V lithium cell and TPS63020

Measure the single-cell battery before the TPS63020, not its regulated 3.3 V
output. The installed 10000 mAh capacity does not determine the current voltage
or remaining charge. The software displays measured volts; it does not invent a
remaining percentage or mAh value.

For each ESP32 Dev Module, add this divider on the battery side of the regulator:

```text
Battery BAT+ --- 100k ohm R1 ---+--- GPIO34 (ADC1)
                              |
                            100k ohm R2
                              |
Battery BAT- -----------------+--- ESP32 GND

Also connect a 100 nF capacitor between GPIO34 and GND.
```

Use 1% resistors. A 4.2 V fully charged cell produces about 2.1 V at GPIO34 with
this divider. Never wire battery positive directly to GPIO34. Keep grounds
common and verify the divider voltage with a multimeter before connecting the
ADC input. This example is for one 3.7 V lithium cell/parallel pack only; a pack
with cells in series requires a different divider design. GPIO34 is ADC1, so
it can be used while Wi-Fi is running. The sampler uses calibrated
`analogReadMilliVolts`, 16 samples, and a 10-second cache.

Each uploadable sketch has `battery_config.h`, initially disabled:

```cpp
static const bool BATTERY_MONITOR_ENABLED = false;
static const int BATTERY_ADC_PIN = 34;
static const float BATTERY_DIVIDER_RATIO = 2.0f;
static const float BATTERY_CALIBRATION = 1.0f;
```

After physically installing/checking the divider, change the enable flag to
`true` in that board's `battery_config.h` and upload its matching sketch. If the
display differs from a multimeter, set `BATTERY_CALIBRATION` to the multimeter
battery voltage divided by the displayed battery voltage. `battery_monitor.h`
is generated from `Arduino/Arduino/shared/`; the generator preserves existing
per-board `battery_config.h` files. Disabled, near-zero, or saturated readings
remain `NA`; the admin sees **No voltage reading**, never a made-up charge level.

## Measured node-to-website delay

The admin monitor shows **Node ↔ website delay (RTT)**, in milliseconds:

- NODE_1 times an authenticated HTTPS health request plus its complete response,
  including connection/TLS setup. The next heartbeat sends that real sample.
- NODE_2 through NODE_10 send a staggered `PING` once per minute. NODE_1 sends it
  to the authenticated `/api/v1/lora/probe` endpoint; only the returned website
  `PONG` is sent back over LoRa. The originating board measures the full round
  trip with its monotonic clock and includes it in subsequent heartbeats.
- RTT includes LoRa airtime, relay delays, gateway work, HTTPS, and the return
  path. It is not one-way delay, the age of the last heartbeat, or radio bitrate.
  No clock synchronization or arbitrary division of RTT by two is used.
- The website keeps the measurement timestamp for a repeated sample instead of
  making it fresh on every heartbeat. Samples expire after two minutes. A failed
  or unanswered probe does not become a zero-millisecond measurement.

Upload the new gateway firmware and each matching generated field-node sketch.
Old firmware continues to send heartbeats but cannot supply the new timing
sample or relay `PING`/`PONG`. Upgrade intermediate relay nodes as well as the
origin. No real SOS incident is created by timing probes. Duplicate heartbeat
routes are suppressed at the gateway to conserve the existing API request limit.

After the upload, check `/status` on a field node for `round_trip_ms`; allow the
first beacon/probe exchange and next 10-second heartbeat. The admin monitor
refreshes every five seconds. Firmware and physically wired measurements must
both be present before the corresponding values can appear.

## Laravel Gateway Endpoints

NODE_1 uses the shared `X-STITCH-LORA-TOKEN` header with these `/api/v1/lora` endpoints:

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Confirm the official Laravel gateway route is available |
| `POST` | `/alerts` | Receive a deduplicated SOS report |
| `POST` | `/heartbeat` | Record NODE_1 and relayed field-node health |
| `POST` | `/probe` | Echo a field timing probe without creating an incident or heartbeat |
| `GET` | `/network-summary` | Get the active NODE_1–NODE_10 count and bitmask |
| `GET` | `/acks/pending?gatewayId=NODE_1` | Poll for responder ACK packets |
| `POST` | `/acks/{msgId}/delivered` | Confirm NODE_1 broadcast the queued ACK |

The API maps node coordinates from the static registry. Heartbeats carry peers,
route, hop count, RSSI/SNR, optional measured battery volts, and optional timing
samples. Battery stays `NA` until sensing is wired and enabled.

## Laravel Environment

Set the same shared token in Laravel:

```env
LORA_INGEST_TOKEN=replace-with-a-long-random-token
```

For Render, add the same value to the `stitch-web-demo` environment variables.

Apply the new mesh state/ACK schema in the local Laravel app before connecting the gateway:

```powershell
cd "C:\laragon\www\TomasOppusRescue-main\web_system"
php artisan migrate
```

## Legacy Sketches

`Sender_GPS`, `Reciever`, `Gateway1`, `Gateway2`, and `sketch_may21a` are kept as historical references. Their older `ALERT|...` packet format and radio pin configuration do not interoperate with the active NODE_1–NODE_10 mesh firmware. Do not mix those boards into the active mesh.
