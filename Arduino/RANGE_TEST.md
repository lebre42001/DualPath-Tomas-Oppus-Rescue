# Investigating the NODE_5–NODE_7 radio link

The supplied primary NODE_5 and NODE_7 coordinates are about **400 m** apart
(straight-line haversine distance, not a surveyed road length). NODE_5's alternative
position is about **377 m** from NODE_7. The current code has **no -98 dBm cutoff**.
Green expires after **40 s without a valid authenticated packet from a direct
peer**. The final displayed RSSI is the last successfully decoded packet, not a
measurement of the packets that were subsequently lost. Green alone does not
confirm a gateway/server route or successful SOS delivery.

## What is known and what still needs testing

- User's antenna: nominal 433 MHz, 6 dBi, omnidirectional, approximately 6'5"–6'6"
  (about 2 m) above ground.
- Deployed profile: 433 MHz, SF7, 125 kHz BW, 4/5 coding, 17 dBm configured TX,
  sync word 0x34. These settings remain the default; no power increase was made.
- Antenna model, actual gain/VSWR, connector contact, cable loss, supply voltage
  under TX, interference, terrain and obstruction losses have not been measured.
- No physical range improvement or guaranteed 400 m link has been verified.

At 433 MHz over a flat 400 m path, the first Fresnel-zone midpoint radius is
approximately 8.3 m; 60% is about 5 m. This is a planning calculation, **not** a
survey or an assertion that every antenna must be 5 m high. A two-metre mounting
can leave substantial ground/obstruction effects even when both endpoints can
be seen. Try a safe, clear mounting around 5–6 m at both ends where practical,
then compare at the **same** test positions. Buildings/trees/unequal ground levels
can require different placement. Keep antennas vertical, away from the body and
nearby metal, with short suitable coax. Inspect matching centre contacts at every
SMA/RP-SMA/pigtail connection and verify stable regulated 3.3 V during radio TX.
Do not substitute 5 V or increase TX power to compensate for an unverified path.

## Firmware diagnostics

Upload the corresponding current sketch to each board using the existing private
headers. A website deployment **cannot flash the ESP32**. Preserve/acknowledge real
pending reports first. Keep the assigned NODE_NUMBER and matching private keys.

After a baseline build, Serial Monitor at 115200 prints:

```text
[RADIO] SF7 mesh baseline; 433 MHz; BW 125000 Hz; CR 4/5; TX 17 dBm; mesh-reconnect-20261029
```

In the phone's local node dashboard (`http://192.168.4.1` while joined to that
node), expand **Radio diagnostics & range test**. The same information is in
`/status`: active profile, last authenticated transmitter ID, RSSI, SNR, age,
frequency error, valid-packet count and authentication/replay rejections.
Counters are since reboot; no packet-loss percentage is inferred from them.
More than 40 s is labelled historical. No reading is represented by null.
These are local diagnostics; no new monitoring/admin API contract is required.

1. Baseline both directions at close range, then 100 m, 200 m and the desired
   NODE_5–NODE_7 positions. Record exact endpoints, antenna height and orientation,
   last sender, RSSI, SNR and reception age for at least five minutes per position.
   Export/copy snapshots manually; do not submit fictitious emergency incidents.
2. Repeat with improved placement/height at the same coordinates. Check both
   directions: hearing a peer once is not proof that the return path works.
3. If green drops, distinguish no valid packets, repeated authentication/replay
   rejections, board resets, and a missing gateway route. Check for duplicate node
   IDs and key mismatch when rejections increase. SNR/age are not available for
   a packet the receiver could not decode; a missing packet is not a -98 cutoff.
4. With the baseline network restored, validate actual gateway connectivity,
   legitimate report receipt and the returning admin ACK before widening spacing.
   Keep NODE_6 as a relay until the longer link is demonstrated to be reliable.

## Optional isolated two-board comparison

`Arduino/Arduino/shared/radio_profile.h` (from the repo root) is the source of truth and is copied into each
uploadable sketch by `generate_field_node_sketches.ps1`.

- `AKSYRES_RADIO_PROFILE=0` (default): original SF7 / BW125 / CR4/5, 17 dBm.
- `AKSYRES_RADIO_PROFILE=1`: **isolated pair test only**, SF9 / BW125 / CR4/6,
  same 433 MHz, 17 dBm and sync word. Both paired boards must use profile 1.

The SF9 pair cannot communicate with the live SF7 mesh. Isolate the test pair,
use the same node identities/keys, and test discovery/telemetry without making
fake rescue reports. Missing gateway-route indication is expected when the SF7
gateway is outside this pair. Restore profile 0 before rejoining the deployed mesh.

Example build only, from this repo's root (use matching flags on the other board):

```powershell
& 'C:\Program Files\Arduino CLI\arduino-cli.exe' compile --fqbn esp32:esp32:esp32 --jobs 2 --build-property 'compiler.cpp.extra_flags=-DAKSYRES_RADIO_PROFILE=1' --output-dir tool/runtime/range-pair-node5 Arduino/Arduino/FieldNodes/NODE_5_Field_Node
```

This command does not upload. Generated binaries contain private keys and must
remain local/ignored. For a baseline build omit the extra profile flag. Do not
edit only one generated sketch's settings or mix profiles among deployed boards.

Do not promote SF9 to the full ten-node mesh solely because the pair succeeds.
A calculated 255-byte authenticated frame with an explicit header, 8-symbol
preamble and current CRC-disabled default takes about 395 ms in the baseline and
1,484 ms in the pair profile. These are formula estimates, not measured delays.
Multi-hop relays, heartbeat traffic and gateway broadcasts multiply airtime.
The existing 5 s beacon / 10 s heartbeat schedule is **not validated** for ten
nodes at SF9. Network-wide airtime planning, emergency/ACK prioritisation and
actual loss/latency tests must precede a deployment-profile change. Increasing
timeouts or keeping green lit does not repair the radio link.

## Confirmed server coordinates (2026-10-29)

The operator confirmed Cumajig for NODE_5 and 10.2932380, 124.9777614 for NODE_10.
The ten coordinates below are configured in `web_system/config/lora_nodes.php`.
The deployment migration corrects any existing saved location overrides once;
later admin edits continue to take precedence. Historical incident coordinates
are retained. NODE_11 is optional and is not added to the ten-node registry.

| Node | Latitude | Longitude | Note |
|---|---:|---:|---|
| NODE_1 | 10.304469 | 124.979829 | Gateway, MDRRMO office |
| NODE_2 | 10.3042205 | 124.9805496 | Confirmed |
| NODE_3 | 10.3035340 | 124.9813019 | Confirmed |
| NODE_4 | 10.3028159 | 124.9820385 | Confirmed |
| NODE_5 | 10.3025949 | 124.9823205 | Cumajig |
| NODE_6 | 10.3002690 | 124.9818665 | Confirmed |
| NODE_7 | 10.2990587 | 124.9816322 | Confirmed |
| NODE_8 | 10.2971533 | 124.9814451 | Confirmed |
| NODE_9 | 10.2960984 | 124.9801040 | Confirmed |
| NODE_10 | 10.2932380 | 124.9777614 | Confirmed |
| NODE_11 | 10.2932380 | 124.9777614 | Optional, not enabled |

Installed registry: `web_system/config/lora_nodes.php`. Saved Admin > Overview >
Node locations override config defaults. A node's fixed position is not proof of
the actual accident position; keep the incident landmark separate.

## Primary references

- [Semtech LoRa FAQ: link budget, propagation and RSSI/SNR](https://www.semtech.com/design-support/faq/faq-lora/P20).
- [Microchip AN1629: line of sight and Fresnel-zone clearance](https://ww1.microchip.com/downloads/jp/AppNotes/jp567134.pdf).
- [Arduino-LoRa API: matched radio parameters](https://github.com/sandeepmistry/arduino-LoRa/blob/master/API.md).
