# Restart one LoRa node from the admin website

This feature applies to NODE_1/Gateway and NODE_2 through NODE_10. It requires
the matching web update **and an Arduino firmware upload to the physical boards**.
Publishing the website does not update an ESP32 that is already deployed.

## Operator steps

1. Sign in as an administrator and open **LoRa Mesh**.
2. Expand the specific node. Its **Restart** action appears above its details.
3. Read the confirmation, then choose **Restart node**.
4. Close the queued confirmation with **OK** and watch that node's restart status.

The button is enabled only after the node and gateway advertise the updated
restart protocol in a recent authenticated heartbeat. If it says to upload the
firmware first, the installed board has not advertised this capability. A recent
heartbeat is required even when a node had been seen on an earlier day.

| Display | Meaning |
| --- | --- |
| Queued | The server saved the administrator's command; delivery is pending. |
| Sent | The gateway sent the request; target acceptance is not confirmed. |
| Restarting | The specific node authenticated and accepted the command. |
| Restart confirmed | A signed receipt for that command arrived from a new boot. |
| Expired | No post-boot confirmation arrived within the command window. |
| Failed | The gateway reported that the request could not complete. |

The current firmware normally moves from Queued directly to Restarting, then
Restart confirmed; Sent is an available API phase and is not a required screen
step. Expired is shown when no confirmation arrives in time, even if a device
may have restarted. A later authenticated receipt can still confirm that command.

The command delivery window is five minutes. Repeated requests for the same
active node return the same command, and requests are limited to one per node
every two minutes. The current connection label remains separate: a confirmed
restart does not imply that the radio or internet connection is now healthy.

## What the operator should expect

- Restarting a field node briefly drops its local Wi-Fi and any radio route
  passing through it. People on its portal may need to reconnect.
- Restarting NODE_1 briefly interrupts the whole mesh's server bridge.
- Reports already saved in the node outbox or gateway inbox retain their
  existing message IDs and resume the normal retry process after startup.
- A form that has not yet been accepted and saved by the node can require
  reconnection and re-entry. A remote restart cannot guarantee preservation of
  unsaved browser input or a radio frame currently in flight.
- A powered-off node, exhausted battery, frozen CPU, broken radio, or missing
  gateway internet cannot be repaired by a website command. Restore the physical
  device/network first when the control path is unavailable.

## Firmware upload

Keep the existing private `arduino_secrets.h`, `mesh_secrets.h`, battery calibration,
and node IDs. Use the existing deployment keys; a restart update does not rotate
keys. Never publish private headers or keyed firmware binaries.

From the Laragon repository root, regenerate the matching field sketches:

```powershell
cd C:\laragon\www\TomasOppusRescue-main
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Arduino\generate_field_node_sketches.ps1
```

Upload these active sketches in Arduino IDE:

- NODE_1: `Arduino/Arduino/GatewayNode1/GatewayNode1.ino`
- NODE_2–NODE_10: each matching sketch under `Arduino/Arduino/FieldNodes/`

All field/relay boards need the new forwarding and heartbeat capability fields.
Preserve ESP32 Preferences/NVS during upload; do not erase flash or reset report
sequences. Normal remote restart uses `ESP.restart()` and keeps the existing
outboxes, gateway inbox, durable radio transmission counters, and calibration.

After uploading, confirm fresh heartbeats on the website before using Restart.
Verify one clearly designated test node first, including a saved pending report,
its returned admin response, and the signed restart confirmation. Software tests
and ESP32 compilation do not establish a successful physical radio restart.

## Control path and replay protection

The administrator's authenticated, CSRF-protected POST queues a targeted command
in Laravel. NODE_1 retrieves it through the existing authenticated gateway API.
Field nodes use a fresh short-lived challenge and target-specific HMAC to
authenticate the commit without relying on a field node wall clock. The gateway
also checks the command's server expiry and expected pre-restart boot ID.
The gateway needs a valid network time from its existing NTP setup; if it cannot
obtain trusted time, it will not dispatch a restart, and the request can expire.

The target saves a command high-water mark and pending receipt in one durable
Preferences record before accepting execution. Repeating the same command cannot
cause another restart. Its acceptance and post-boot receipt have distinct signed
messages. Laravel records restart confirmation only after matching acceptance
and a different boot ID for that same command. A normal heartbeat alone does not
confirm a remotely requested restart.

Retain the restart command table in database backups and preserve monotonic
command IDs. Restoring an older command sequence can cause devices to reject
commands as replays; do not reset device anti-replay state as a routine remedy.
The shared System Log records the requesting admin and authenticated result
phases without creating an incident or changing rescue status.
