# LoRa report protection (protocol v2)

The active firmware now requires a review-and-confirm step, a short description,
and the actual accident landmark. Existing incident options remain visible;
only Road Accident is enabled, and the node enforces that restriction on POST.
The node location in Laravel remains the installed board's coordinates; the
reported landmark is a separate field, not a claim of live GPS.

## Sender and admin behavior

- `/prepare` issues a random, two-minute, one-use confirmation bound to the
  exact report fields and local client IP. `/send` rejects changed, expired or
  reused confirmations. Nothing is sent on the radio by opening or cancelling
  the confirmation dialog.
- A node enforces 60 seconds between accepted new reports from a client IP,
  including after the preceding report is acknowledged. HTTP 429 includes
  `Retry-After`; the dashboard shows the countdown. The bounded table retains
  active limits instead of evicting them. Changing IP/device or rebooting the
  node can evade this friction; this is not verified civilian identity.
- The existing one-pending-report queue per node remains unchanged. While it
  is occupied, all clients see the pending-report indicator. That constraint
  can delay a second person's incident until an admin acknowledges the first.
  This update does not silently introduce a multi-report queue.
- Existing SOS retries keep the same message ID and exact body. They bypass
  new-submission admission control and still use Laravel's idempotency logic.
- Blocked submission and rejected radio-frame counters are sent in existing
  heartbeats. Admins can see them in LoRa Reports > Node protection indicators
  and expanded LoRa Mesh node details. Counts reset on node reboot; boot ID
  and last-reported time distinguish resets/stale values. No client IP is sent
  to the website. Noise/replay/repeated taps are not automatically labelled a
  prank. These indicators arrive only when the gateway can reach Laravel.
- Admin verification has exactly three decisions: Confirmed, Needs
  clarification, False report (initially Unverified). A reason is required and
  every changed decision is recorded with actor/time in report history. An
  identical save does not append another history entry or acknowledgment.
  Verification is independent of Pending/Responding/Done/Cancelled rescue
  progress. A verification save queues a signed response even while rescue
  status remains Pending. False report does not silently cancel responders.
- Printouts show verification and notes. The actual landmark follows the
  existing opt-in location-print setting. Free-text descriptions/notes can
  themselves contain personal information; review them before sharing.

## Radio authentication

The cryptographic primitive is HMAC-SHA-256, with independent random 256-bit
keys and 128-bit tags encoded as 22 base64url characters. HMAC authenticates
messages; it is not encryption or a public-key digital signature. It does not
prove that an authenticated person's reported accident actually happened.

Every radio transmission uses this binary envelope (maximum 255 bytes):

| Bytes | Meaning |
|---|---|
| 0–1 | ASCII `AR` |
| 2 | version `2` |
| 3 | transmitting node number, 1–10 |
| 4–11 | unsigned big-endian 64-bit transmission counter |
| 12… | compact UTF-8 protocol payload |
| last 22 | group-key HMAC over header and compact payload |

To compact existing routes, `~` is escaped as `~~`, then `NODE_` becomes `~N`.
Decoding is single-pass, so literal user text cannot become an injected node ID.
Counters are reserved in persistent blocks of 256 before use, avoiding reuse
after reboot without writing flash on every heartbeat. Authentication and
counter checks happen before packet dispatch, peer discovery or ACK handling.
Unsigned/modified packets and non-increasing counters are dropped. The link
receive-counter cache is RAM-only: after receiver reboot, recorded control
frames may pass again. The durable SOS check below prevents creating a new
incident from an older SOS. This does not prevent radio jamming.

Separate per-origin keys authenticate SOS immutable fields and final ACKs:

- Each field node holds only its own origin key plus the group key. NODE_1
  holds the origin-key table. Compromising a relay's group key does not expose
  another field node's origin key. A group-key compromise can still forge
  discovery/route/heartbeat traffic; rotate the group key after such compromise.
- SOS body: `SOS2|msgId|origin|unixSeconds|R|description|battery|landmark`.
  Wire SOS fields are the original 0–9 fields followed by landmark (10) and
  origin tag (11). `R` is Road Accident. Route/hop/TTL may change in transit.
- ACK body: `ACK2|msgId|origin|gateway|status|time|message|verificationLabel`.
  Wire ACK fields are the original 0–8 fields followed by verification label
  (9) and destination-origin tag (10). Relays preserve this tag. A field node
  checks it before clearing its pending report, and ignores older ACK times.
- NODE_1 persists the highest accepted report sequence and its origin tag for
  each sender after Laravel accepts the incident. Older IDs and conflicting
  content for the same ID are rejected across reboot; the exact current SOS
  may retry so Laravel can resend a lost ACK. Counter values are not compared
  to wall-clock time, allowing reports queued during an outage.
- Report admission checks the worst ten-node route against the actual radio
  byte budget. Overlong text is rejected before queueing, never truncated.

HMAC API reference: [Mbed TLS message digest API](https://mbed-tls.readthedocs.io/projects/api/en/v3.5.2/api/file/md_8h/).
Key-generation guidance: [OWASP Cryptographic Storage](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html).

## Local provisioning and coordinated deployment

Run from this Laragon repository:

```powershell
cd C:\laragon\www\TomasOppusRescue-main
powershell.exe -NoProfile -ExecutionPolicy Bypass -File Arduino\provision_mesh_security.ps1
powershell.exe -NoProfile -ExecutionPolicy Bypass -File Arduino\generate_field_node_sketches.ps1
```

The provisioning command preserves existing keys on repeat runs. The private
backup is `tool/runtime/mesh-security-keys.json`; uploadable private headers are
`mesh_secrets.h` in each sketch directory. Both are excluded from Git. Keep an
encrypted offline backup; never publish private headers or keyed firmware
binaries in GitHub releases. `-Rotate` deliberately changes the whole mesh's
keys, keeps a local dated backup, and requires re-uploading every node. It is
not a routine deployment command. Secrets in source firmware are not protected
against physical extraction; secure boot/flash encryption are separate work.

1. Deploy the Laravel migration/backend first. Existing records are retained;
   legacy ingests are explicitly labelled origin-not-verified during rollout.
   The gateway API token is still required. The web server trusts NODE_1's
   verification result; it does not independently possess the radio keys.
2. Finish and acknowledge existing queued reports before firmware migration.
   Do not erase node Preferences or reset existing report sequences. Legacy
   unsigned queued packets cannot be safely upgraded by inventing landmarks.
3. Upload the matching v2 sketch and private keys to NODE_1 and **all** field
   nodes together. A v2 node rejects legacy radio frames. Mixed firmware does
   not form a working mesh. Website deploy alone does not flash ESP32 boards.
4. Verify a clearly designated test through originating node, relay, server,
   admin verification and signed returning ACK. Check the pending LED clears
   only after the correct signed response. Also test reboot/offline retries.
5. Inspect indicators for `Authenticated firmware v2`; legacy labels or absent
   telemetry mean the installed firmware has not supplied these protections.

The template remains `Arduino/Arduino/FieldNode/FieldNode.ino`; generated
uploadable sketches are under `Arduino/Arduino/FieldNodes/`. Never copy another
node's private header into a generated sketch. The generator preserves each
board's private header and battery calibration.

## Validation

The ESP32 CLI compilation checks the actual mbedTLS/LoRa/Preferences APIs.
Native tests run the **same shared headers** with an in-memory radio/Preferences
and Windows BCrypt HMAC adapter; public synthetic test keys are not deployment
keys. With Zig available:

```powershell
zig c++ -std=c++17 -I Arduino/tests/native -I Arduino/Arduino/shared Arduino/tests/native/mesh_security_test.cpp -lbcrypt -o tool/runtime/test_mesh_security.exe
.\tool\runtime\test_mesh_security.exe
powershell.exe -NoProfile -ExecutionPolicy Bypass -File Arduino/test_led_indicators.ps1
```

Host tests and compilation do not establish a successful physical radio round
trip. Signed packets reduce forged traffic; human review is still needed to
identify deliberate false reports submitted through a legitimate open node.
