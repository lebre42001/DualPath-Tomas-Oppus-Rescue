# Whole-mesh connection and reconnection update

This firmware applies to **NODE_1 and every field node NODE_2–NODE_10**. It
improves scheduling and recovery; no measured increase in RF range or guaranteed
all-pairs direct connection is claimed. Nodes still use reachable relays when
they cannot hear the gateway directly. NODE_11 remains disabled.

## Behavior

- The first field-node HELLO is scheduled within about one second after radio
  initialization. While there is no peer, attempts are spaced about 1.8–3 s for
  20 s, then about 14–15.2 s to limit continuous discovery traffic. Actual delivery
  depends on radio/channel availability. Losing all fresh peers reopens the fast
  discovery window. First-time discovery is staggered by identity and random seed.
- A newly restored gateway route schedules a staggered heartbeat promptly instead
  of waiting a whole heartbeat period. Saved SOS can retry promptly on restoration,
  with a five-second guard against repeated recovery bursts. Routine heartbeats
  have a small random phase variation to avoid recurring collisions.
- A bounded RAM queue holds up to 24 waiting packets plus one on-air packet.
  Ordinary control traffic is limited to 16 waiting slots, leaving emergency
  capacity. SOS/ACK have priority; after three emergency transmissions a due
  control packet can proceed, so discovery/routes are not indefinitely starved.
- Repeated queued heartbeats/beacons are coalesced; old control packets expire.
  Queue drops/expiry are reported honestly. The existing durable origin SOS and
  gateway inbox remain the recovery mechanism; queue acceptance is not delivery.
- Nonblocking random backoff replaces relay `delay()` calls. The scheduler checks
  an ongoing receive/RX_DONE before starting SX127x CAD. Busy CAD backs off with
  jitter; CAD timeout returns to RX. Authentication counters are allocated only
  at actual transmission, so priority scheduling cannot reorder reserved counters.
- TX is asynchronous. The main loop continues servicing the portal/LEDs/HTTP while
  the radio is transmitting. The radio itself is still half duplex. TX_DONE, not
  enqueue, changes a local report to **Sent by radio – waiting for server receipt**.
  A bounded TX timeout returns the radio to receive and keeps critical retries
  possible. The receiver resumes immediately after reading a packet.
- Final gateway ACKs use three spaced copies instead of three blocking bursts.
  The HTTP delivered callback is queued only after actual TX_DONE. The API's
  optional revision token prevents an older ACK from clearing a newer admin
  response, including changed notes within the same second. The callback means
  radio transmission completed, **not proof the destination received it**. Existing
  origin retry/server requeue behavior handles missing final responses.
- Gateway beacons include a boot identifier. Updated fields reject older sequence
  numbers and recently retired gateway boots, preventing a delayed old route from
  replacing a new one after restart. Plain legacy sequence numbers remain accepted,
  with a 30 s silence reset for an older gateway reboot.

## Preserved constraints

The deployed profile is still **433 MHz, SF7, BW125, CR4/5, 17 dBm, sync 0x34**.
The isolated SF9 pair-test profile remains optional. There is no RSSI admission
cutoff at -98 dBm, no TX-power increase, and no extended LED timeout to disguise
a lost link. Direct peer expiry remains 40 s; gateway-route expiry remains 30 s;
website heartbeat freshness/Unavailable timing is unchanged. HMAC keys, IDs,
report confirmation, anti-spam, radio packet format and pending NVS reports are
preserved. Only the GW sequence string gains a backwards-compatible boot prefix.

CAD detects matching LoRa activity, not every possible interferer. Hidden
transmitters can still collide. The single-channel radio has finite airtime;
large/long-hop networks can saturate it. Queue congestion can expire telemetry,
and an unstable antenna/power supply or obstructed path still needs a physical
fix. [RANGE_TEST.md](RANGE_TEST.md) covers the proposed 400 m link and antenna checks.

## Apply the update

1. Deploy the matching Laravel ACK revision-token change first. It needs no new
   database migration or credentials and still accepts older firmware callbacks.
2. Finish/acknowledge any real pending reports before a maintenance upload. Keep
   every private secrets header, battery calibration and the existing node identity.
   Do not rotate keys or erase NVS merely to install this firmware.
3. Upload `Arduino/Arduino/GatewayNode1/GatewayNode1.ino` to NODE_1, then the
   matching `Arduino/Arduino/FieldNodes/NODE_n_Field_Node/NODE_n_Field_Node.ino`
   to each physical NODE_2–NODE_10. **Arduino upload needed on every board.**
   Updating the website/repository cannot flash the ESP32 devices.
4. Use the default matching SF7 profile for the live mesh. Serial Monitor at
   115200 and the local dashboard should show `mesh-reconnect-20261029`. Do not
   mix the optional SF9 test profile into the live SF7 mesh.
5. Start NODE_1 with a working server connection, then bring up the fields and
   observe green peer evidence, yellow gateway-route indication and fresh website
   heartbeats. Move through each intended adjacent/skip link and verify both
   directions. Keep backup relay positions until the proposed links pass field tests.

## Validation and field evidence

Native tests exercise the real scheduler/driver policies with a mock clock/SPI:
priority, bounded backpressure, coalescing, receive protection, CAD busy/timeout,
TX_DONE-only completion, timeout retry, cancellation, spaced ACKs, all field
discovery identities, gateway restarts and millis rollover. Cryptographic tests
continue checking frame authentication and durable counters. Source-extracted
gateway inbox/LED tests and actual ESP32 compilation check integration. These
checks are software evidence, **not measured field range or packet-loss results**.

Use heartbeats and timing probes for initial site checks. Record node pair,
coordinates, antenna/cable/height, profile, both-direction RSSI/SNR, time until
peer/route confirmation, gaps and RTT across an observation window. Test power
loss/recovery and gateway internet loss separately. Report/ACK acceptance tests
belong in an isolated test database or a supervised legitimate incident flow.
Do not create fictitious incidents in the live rescue feed.

The expandable local **Radio diagnostics & range test** panel exposes queue depth,
actual TX completions, CAD deferrals/timeouts, TX failures, expired/dropped queue
entries, plus the prior authenticated-packet RSSI/SNR/age. All counters are since
restart and do not by themselves measure packet-loss percentage.

Hardware references: [Semtech SX1276/77/78/79 datasheet, Rev 7](https://cdn.sparkfun.com/assets/7/7/3/2/2/SX1276_Datasheet.pdf),
[pinned Arduino-LoRa 0.8.0](https://github.com/sandeepmistry/arduino-LoRa/tree/0.8.0).
