/*
  STITCH ESP32 LoRa FIELD NODE firmware for NODE_2 ... NODE_10
  NODE_2..10: green/yellow/red LEDs with the same status algorithm
  No switch or GPS; battery voltage ADC enabled by default

  Hardware:
  - ESP32
  - SX1278 / Ra-02 / XL1278 LoRa module
  - 18650 battery
  - TPS63020 buck-boost verified at ~3.3 V

  Each field node can:
  1. Create its own open (no-password) Wi-Fi AP and emergency dashboard.
  2. Originate an emergency report while preserving the original button-press time.
  3. Queue one emergency report if no route to NODE_1/server is available.
  4. Send periodic heartbeat/status packets.
  5. Discover direct LoRa peers using HELLO and the gateway acknowledgment.
  6. Relay heartbeats, SOS reports, gateway beacons, network summaries, and ACKs.
  7. Accept routes that skip numbered nodes when radio reachability permits.
  8. Receive the server's manual acknowledgment and short message.
  9. Show the active-node count broadcast by NODE_1/server.

  IMPORTANT:
  - Change NODE_NUMBER below for each physical ESP32 (2 through 10).
  - All nodes must use the same LoRa frequency/settings.
  - Attach the LoRa antenna before transmitting.
  - LoRa VCC is 3.3 V only.
  - Battery sensing is enabled; valid voltage readings require divider wiring.
  - Website delay is measured as a complete LoRa + HTTPS + LoRa round trip.

  Required Arduino library:
  - LoRa by Sandeep Mistry
*/

#include <WiFi.h>
#include <WebServer.h>
#include <Preferences.h>
#include <SPI.h>
#include <LoRa.h>
#include "radio_profile.h"
#include <esp_system.h>
#include "battery_monitor.h"
#include "mesh_security.h"
#include "mesh_sx127x.h"
#include "report_guard.h"
#include "report_dashboard.h"

// =====================================================
// CHANGE ONLY THIS FOR EACH FIELD NODE
// Valid values: 2 ... 10
// =====================================================

const int NODE_NUMBER = 10;

// =====================================================
// LORA CONFIGURATION - MUST MATCH NODE_1
// =====================================================

const long LORA_FREQUENCY = AksyResRadio::frequency;

// ESP32 <-> SX1278 / Ra-02 / XL1278
const int LORA_SS   = 5;   // NSS / CS
const int LORA_SCK  = 18;  // SCK / SLCK
const int LORA_MISO = 19;
const int LORA_MOSI = 23;
const int LORA_RST  = 27;  // RST / REST
const int LORA_DIO0 = 26;

// All field nodes NODE_2..NODE_10 use the same active-high three-LED wiring.
const bool HAS_THREE_STATUS_LEDS = NODE_NUMBER >= 2 && NODE_NUMBER <= 10;
const int GREEN_LED_PIN = 25;          // Fresh direct LoRa peer
const int YELLOW_STATUS_LED_PIN = 32;  // Mesh route / radio activity
const int RED_EMERGENCY_LED_PIN = 33;  // This node's pending report
const int MESH_STATUS_LED_PIN = HAS_THREE_STATUS_LEDS ? YELLOW_STATUS_LED_PIN : GREEN_LED_PIN;

static_assert(!BATTERY_MONITOR_ENABLED ||
  (BATTERY_ADC_PIN != MESH_STATUS_LED_PIN &&
   (!HAS_THREE_STATUS_LEDS ||
    (BATTERY_ADC_PIN != GREEN_LED_PIN && BATTERY_ADC_PIN != RED_EMERGENCY_LED_PIN))),
  "Battery ADC conflicts with an active LED GPIO. Check this board's battery_config.h.");

// =====================================================
// TIMING
// =====================================================

// Peer discovery is less urgent than the heartbeat used by the website.
// A longer interval prevents nine field nodes from flooding the shared radio.
const unsigned long HEARTBEAT_INTERVAL_MS     = 10000;
const unsigned long PEER_TIMEOUT_MS           = 40000;
// Allow missed 5-second beacons while NODE_1 completes synchronous HTTPS work.
// Confirmation still expires after 30 seconds without a new gateway beacon.
const unsigned long SERVER_ROUTE_TIMEOUT_MS   = 30000;
const unsigned long NETWORK_SUMMARY_TIMEOUT_MS = 60000;
const unsigned long SOS_RETRY_INTERVAL_MS     = 15000;
const unsigned long LED_ACTIVITY_MS           = 700;
const unsigned long LED_FAST_TOGGLE_MS        = 125;
const unsigned long LED_SLOW_TOGGLE_MS        = 600;

// A repeated flooded packet is ignored for this period.
// Source nodes retry SOS later than this, so legitimate retries can pass.
const unsigned long SEEN_PACKET_WINDOW_MS     = 7000;

// =====================================================
// OBJECTS / BASIC STATE
// =====================================================

WebServer localServer(80);
Preferences prefs;
MeshSX127xPort meshRadioPort;
void onRadioTransmitted(const String& packet);
MeshLink::Pump<MeshSX127xPort> meshRadio(meshRadioPort,onRadioTransmitted);
MeshLink::Discovery peerDiscovery;
MeshLink::BeaconTracker gatewayBeacons;

String NODE_ID;
String LOCAL_AP_SSID;

unsigned long lastHeartbeatMs = 0;
unsigned long heartbeatIntervalMs = HEARTBEAT_INTERVAL_MS;
unsigned long lastGatewaySeenMs = 0;
unsigned long lastNetworkSummarySeenMs = 0;
unsigned long lastSosAttemptMs = 0;
unsigned long sosRetryDelayMs = SOS_RETRY_INTERVAL_MS;
unsigned long lastLedActivityMs = 0;
unsigned long lastLedToggleMs = 0;
bool ledActivityPending = false;
bool ledActivityActive = false;
bool ledBlinkState = false;
unsigned long lastEmergencyLedToggleMs = 0;
bool emergencyLedBlinkState = false;
bool emergencyLedWasPending = false;

uint32_t heartbeatSequence = 0;
const unsigned long PROBE_INTERVAL_MS = 90000;
const unsigned long PROBE_TIMEOUT_MS = 60000;
unsigned long lastProbeMs = 0;
unsigned long probeStartedMs = 0;
unsigned long lastRoundTripAtMs = 0;
long lastRoundTripMs = -1;
uint32_t probeSequence = 0;
String probeBootId;
String pendingProbeId;
String lastRoundTripSample;

// Best known route from this node toward NODE_1/server.
String gatewayId = "";
String gatewayPath = "";
int gatewayHopCount = 999;

// Used so a better path for the same gateway beacon can replace a worse path.
String lastGatewaySequence = "";
int bestHopForGatewaySequence = 999;

// Network summary from server/NODE_1.
int activeNodeCount = -1;
const int expectedNodeCount = 10;
uint16_t activeNodeMask = 0;
String lastNetworkTimestamp = "";

// Latest local emergency/report state.
String latestMsgId = "";
String latestCreatedAt = "";
String latestStatus = "NONE";
String latestAckMessage = "";
String latestAckAt = "";

// If non-empty, this exact packet must be preserved/retried until manual ACK.
String pendingPacket = "";
String pendingMsgId = "";
String serverReceiptMsgId = "";

// =====================================================
// DIRECT PEER TABLE
// =====================================================

struct PeerInfo {
  String nodeId;
  int rssi;
  unsigned long lastSeenMs;
};

const int MAX_PEERS = 10;
PeerInfo peers[MAX_PEERS];

// =====================================================
// RECENT PACKET CACHE FOR FLOODING / DUPLICATE CONTROL
// =====================================================

struct SeenPacket {
  String key;
  unsigned long seenAtMs;
};

const int SEEN_CACHE_SIZE = 50;
SeenPacket seenCache[SEEN_CACHE_SIZE];
int seenCacheWriteIndex = 0;

// =====================================================
// SIMPLE LOCAL DASHBOARD
// =====================================================

const char LOCAL_DASHBOARD[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LoRa Emergency Node</title>
<style>
body{font-family:Arial,sans-serif;margin:0;background:#f4f7f5;color:#14251a}
header{background:#116530;color:white;padding:18px;text-align:center}
main{max-width:520px;margin:auto;padding:14px}
.card{background:white;padding:15px;margin-bottom:12px;border-radius:14px;box-shadow:0 2px 8px #0001}
.label{font-size:13px;color:#647168}.value{font-size:19px;font-weight:bold;margin:4px 0 11px}
.ok{color:#13833c}.warn{color:#c67a00}.bad{color:#b52a25}
select,textarea,button{width:100%;box-sizing:border-box;padding:12px;margin-top:8px;border-radius:10px;border:1px solid #ccd6cf;font-size:16px}
textarea{min-height:80px}button{background:#116530;color:white;border:0;font-weight:bold}button:disabled{background:#8b948e}
.small{font-size:13px;color:#666;margin-top:8px}.peers{font-size:14px;line-height:1.5}
.modal{display:none;position:fixed;z-index:20;inset:0;background:#0009;align-items:center;justify-content:center;padding:18px}.modal.show{display:flex}.modal-box{background:#fff;width:100%;max-width:400px;padding:22px;border-radius:18px;box-shadow:0 12px 35px #0004}.modal-box h2{margin-top:0;color:#116530}.review-details{background:#f4f7f5;padding:12px;border-radius:10px;word-break:break-word}.review-details .label{margin-top:10px}.review-details .label:first-child{margin-top:0}
</style>
</head>
<body>
<header><h2>LoRa Emergency System</h2><div id="node">Connected Node</div></header>
<main>
<div class="card">
  <div class="label">LoRa Network</div><div id="network" class="value">Searching...</div>
  <div class="label">Nearby LoRa Nodes</div><div id="peers" class="peers">None detected</div>
  <div class="label">Active Nodes</div><div id="active" class="value">-- / 10</div>
  <div class="label">Server Route</div><div id="route" class="value">Checking...</div>
</div>
<div class="card">
  <h3>Emergency Report</h3>
  <select id="type">
    <option value="ROAD_ACCIDENT">Road Accident</option>
    <option value="MEDICAL_EMERGENCY">Medical Emergency</option>
    <option value="FIRE">Fire</option>
    <option value="OTHER">Other</option>
  </select>
  <textarea id="message" maxlength="70" placeholder="Enter a short emergency message"></textarea>
  <div class="small"><span id="remaining">70</span> characters remaining</div>
  <button id="sendButton" onclick="sendReport()">SEND / QUEUE EMERGENCY</button>
  <div id="hint" class="small"></div>
</div>
<div class="card">
  <h3>Latest Report</h3>
  <div class="label">Message ID</div><div id="msgid">None</div>
  <div class="label">Original Submitted Time</div><div id="created">None</div>
  <div class="label">Status</div><div id="status" class="value">None</div>
  <div class="label">Server Message</div><div id="ack">None</div>
</div>
</main>
<div id="reviewModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="reviewTitle">
  <div class="modal-box">
    <h2 id="reviewTitle">Report update</h2>
    <p id="reviewSummary">The emergency team sent an update.</p>
    <div class="review-details">
      <div class="label">Message ID</div><b id="modalMsgId">-</b>
      <div class="label">Review Result</div><b id="modalStatus">-</b>
      <div class="label">Admin Message</div><b id="modalMessage">-</b>
      <div class="label">Reviewed At</div><b id="modalTime">-</b>
    </div>
    <button onclick="closeReview()">OK</button>
  </div>
</div>
<script>
function pad(n){return String(n).padStart(2,'0')}
function buttonTime(){const d=new Date();const o=-d.getTimezoneOffset();const s=o>=0?'+':'-';const h=pad(Math.floor(Math.abs(o)/60));const m=pad(Math.abs(o)%60);return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds())+s+h+':'+m}
const messageBox=document.getElementById('message');messageBox.addEventListener('input',()=>{document.getElementById('remaining').textContent=70-messageBox.value.length});
const statusLabels={'READY TO SEND':'Saved locally - waiting for radio','QUEUED FOR RADIO':'Saved locally - waiting for a clear radio slot','QUEUED - WAITING FOR ROUTE':'Saved locally - waiting for LoRa route','SENT - WAITING FOR SERVER':'Sent by radio - waiting for server receipt','SUBMITTED - UNDER ADMIN REVIEW':'Received by server - under admin review','ACKNOWLEDGED':'Responder update received','REJECTED':'Report cancelled by admin'};
let shownReview='';
function closeReview(){if(shownReview)localStorage.setItem('field_review_seen_'+shownReview,'1');document.getElementById('reviewModal').classList.remove('show')}
function showReviewIfNeeded(x){
  if(!['ACKNOWLEDGED','REJECTED'].includes(x.report_status)||!x.message_id)return;
  const key='field_review_seen_'+x.message_id;
  if(shownReview===x.message_id||localStorage.getItem(key))return;
  shownReview=x.message_id;
  document.getElementById('reviewSummary').textContent=x.report_status==='REJECTED'?'The admin cancelled this report. Read the message below.':'The emergency team sent an update. Read the verification result and message below.';
  document.getElementById('modalMsgId').textContent=x.message_id;
  document.getElementById('modalStatus').textContent=statusLabels[x.report_status]||x.report_status;
  document.getElementById('modalMessage').textContent=x.ack_message||'Your emergency report has been reviewed.';
  document.getElementById('modalTime').textContent=x.ack_at||'Time unavailable';
  document.getElementById('reviewModal').classList.add('show');
}
async function refresh(){try{
  const r=await fetch('/status',{cache:'no-store'});const x=await r.json();
  document.getElementById('node').textContent='Connected to '+x.node_id;
  document.getElementById('network').innerHTML=x.peer_count>0?'<span class="ok">LoRa Network Detected</span>':'<span class="warn">Searching for LoRa Nodes...</span>';
  document.getElementById('peers').textContent=x.peer_list||'None detected';
  document.getElementById('active').textContent=(x.active_nodes<0?'--':x.active_nodes)+' / '+x.expected_nodes;
  document.getElementById('route').innerHTML=x.server_route?'<span class="ok">Available</span>':'<span class="warn">Unavailable / Searching</span>';
  document.getElementById('msgid').textContent=x.message_id||'None';document.getElementById('created').textContent=x.created_at||'None';
  document.getElementById('status').textContent=statusLabels[x.report_status]||x.report_status||'None';
  document.getElementById('ack').textContent=x.ack_message||'None';
  const b=document.getElementById('sendButton');b.disabled=x.pending;
  if(x.pending&&['QUEUED - WAITING FOR ROUTE','QUEUED FOR RADIO','READY TO SEND'].includes(x.report_status)){
    b.textContent='REPORT QUEUED';document.getElementById('hint').textContent=x.report_status==='QUEUED - WAITING FOR ROUTE'?'Report saved on this node. It will be submitted when a route becomes available.':'Report saved on this node. Waiting for a clear radio slot.';
  }else if(x.pending&&x.report_status==='SENT - WAITING FOR SERVER'){
    b.textContent='WAITING FOR SERVER';document.getElementById('hint').textContent='Radio transmission completed. Waiting for the server receipt; retries continue automatically.';
  }else if(x.pending){
    b.textContent='UNDER ADMIN REVIEW';document.getElementById('hint').textContent='Your report was submitted and is under admin review. Waiting for the review result.';
  }else if(x.server_route){
    b.textContent='SEND EMERGENCY';document.getElementById('hint').textContent='A route toward the server is available.';
  }else{
    b.textContent='QUEUE EMERGENCY';document.getElementById('hint').textContent='No confirmed server route. The report will be stored and sent automatically once a route becomes available.';
  }
  showReviewIfNeeded(x);
}catch(e){}}

</script>
</body>
</html>
)rawliteral";

// =====================================================
// HELPERS
// =====================================================

String cleanField(String value, int maxLen) {
  value.replace("|", " ");
  value.replace("\r", " ");
  value.replace("\n", " ");
  value.replace("\t", " ");
  value.trim();
  if (value.length() > maxLen) value = value.substring(0, maxLen);
  return value;
}

String jsonEscape(String value) {
  value.replace("\\", "\\\\");
  value.replace("\"", "\\\"");
  value.replace("\r", " ");
  value.replace("\n", " ");
  return value;
}

String getField(const String& data, int index) {
  int start = 0;
  for (int i = 0; i < index; i++) {
    int separator = data.indexOf('|', start);
    if (separator < 0) return "";
    start = separator + 1;
  }

  int end = data.indexOf('|', start);
  if (end < 0) end = data.length();
  return data.substring(start, end);
}

bool routeHasNode(const String& route, const String& nodeId) {
  String padded = ">" + route + ">";
  String token = ">" + nodeId + ">";
  return padded.indexOf(token) >= 0;
}

String appendRoute(String route, String nodeId) {
  if (route.length() == 0) return nodeId;
  if (routeHasNode(route, nodeId)) return route;
  return route + ">" + nodeId;
}

bool recentlySeen(const String& key, unsigned long windowMs = SEEN_PACKET_WINDOW_MS) {
  unsigned long now = millis();

  for (int i = 0; i < SEEN_CACHE_SIZE; i++) {
    if (seenCache[i].key == key) {
      if (now - seenCache[i].seenAtMs < windowMs) return true;
      seenCache[i].seenAtMs = now;
      return false;
    }
  }

  seenCache[seenCacheWriteIndex].key = key;
  seenCache[seenCacheWriteIndex].seenAtMs = now;
  seenCacheWriteIndex = (seenCacheWriteIndex + 1) % SEEN_CACHE_SIZE;
  return false;
}

// =====================================================
// DIRECT PEER DISCOVERY
// =====================================================

void updatePeer(String peerId, int rssi) {
  peerId.trim();
  if (peerId.length() == 0 || peerId == NODE_ID) return;

  unsigned long now = millis();

  for (int i = 0; i < MAX_PEERS; i++) {
    if (peers[i].nodeId == peerId) {
      peers[i].rssi = rssi;
      peers[i].lastSeenMs = now;
      return;
    }
  }

  // Reuse an empty or expired slot.
  for (int i = 0; i < MAX_PEERS; i++) {
    if (peers[i].nodeId.length() == 0 || now - peers[i].lastSeenMs > PEER_TIMEOUT_MS) {
      peers[i].nodeId = peerId;
      peers[i].rssi = rssi;
      peers[i].lastSeenMs = now;
      return;
    }
  }
}

int activePeerCount() {
  int count = 0;
  unsigned long now = millis();
  for (int i = 0; i < MAX_PEERS; i++) {
    if (peers[i].nodeId.length() > 0 && now - peers[i].lastSeenMs <= PEER_TIMEOUT_MS) count++;
  }
  return count;
}

bool hasFreshLoRaPeer() {
  // Only direct radio discoveries count; the server's active-node total does not.
  return activePeerCount() > 0;
}

String peerCsv(bool includeRssi = false) {
  String result = "";
  unsigned long now = millis();

  for (int i = 0; i < MAX_PEERS; i++) {
    if (peers[i].nodeId.length() == 0 || now - peers[i].lastSeenMs > PEER_TIMEOUT_MS) continue;

    if (result.length() > 0) result += ",";
    result += peers[i].nodeId;
    if (includeRssi) result += "(" + String(peers[i].rssi) + ")";
  }

  return result;
}

// =====================================================
// LORA TRANSMISSION
// =====================================================

void markLoRaActivity() {
  // Start the short animation on the next loop pass, after any blocking
  // server work triggered by this packet has returned.
  ledActivityPending = true;
}

bool sendLoRaPacket(String payload) {
  const bool queued = meshSecurityReady && meshCompact(payload).length()+34<=255 && meshRadio.enqueue(payload,millis());
  if (!queued) Serial.println("[LORA] Radio queue full or packet exceeds authenticated frame budget; durable SOS will retry.");
  return queued;
}

void onRadioTransmitted(const String& packet) {
  markLoRaActivity();
  if (packet.startsWith("SOS|") && getField(packet,1)==pendingMsgId &&
      getField(packet,2)==NODE_ID && serverReceiptMsgId!=pendingMsgId && latestStatus!="SENT - WAITING FOR SERVER") {
    latestStatus="SENT - WAITING FOR SERVER";prefs.putString("latest_status",latestStatus);
    Serial.println("[RADIO TX] Local SOS transmitted; waiting for server receipt.");
  }
}

// =====================================================
// ROUTE / NETWORK STATE
// =====================================================

bool serverRouteAvailable() {
  return lastGatewaySeenMs > 0 && (millis() - lastGatewaySeenMs <= SERVER_ROUTE_TIMEOUT_MS);
}

bool hasConfirmedServerRoute() {
  return serverRouteAvailable();
}

bool hasPendingLocalEmergency() {
  // Only locally originated reports enter this persistent queue. Relaying an
  // SOS does not populate it; the existing matching responder ACK clears it.
  return pendingPacket.length() > 0;
}

void initializeLedIndicators() {
  pinMode(MESH_STATUS_LED_PIN, OUTPUT);
  digitalWrite(MESH_STATUS_LED_PIN, LOW);
  if (HAS_THREE_STATUS_LEDS) {
    pinMode(GREEN_LED_PIN, OUTPUT);
    pinMode(RED_EMERGENCY_LED_PIN, OUTPUT);
    digitalWrite(GREEN_LED_PIN, LOW);
    digitalWrite(RED_EMERGENCY_LED_PIN, LOW);
  }
}

void updateLedIndicators() {
  const unsigned long now = millis();
  if (ledActivityPending) {
    lastLedActivityMs = now;
    ledActivityActive = true;
    ledActivityPending = false;
  }

  // Confirmed routes stay solid even during radio traffic. Activity only
  // animates yellow while a peer exists but no server route is confirmed.
  const bool activityFresh = ledActivityActive && now - lastLedActivityMs < LED_ACTIVITY_MS;
  if (!activityFresh) ledActivityActive = false;
  const bool hasPeer = hasFreshLoRaPeer();
  // No peer means both green and yellow stay OFF on every valid field node,
  // including unanswered HELLOs.
  const bool solid = hasPeer && hasConfirmedServerRoute();
  const bool fastBlink = activityFresh && hasPeer && !solid;
  const bool slowBlink = hasPeer && !solid;

  if (!fastBlink && !slowBlink) {
    ledBlinkState = solid;
  } else {
    const unsigned long interval = fastBlink ? LED_FAST_TOGGLE_MS : LED_SLOW_TOGGLE_MS;
    if (now - lastLedToggleMs >= interval) {
      lastLedToggleMs = now;
      ledBlinkState = !ledBlinkState;
    }
  }
  digitalWrite(MESH_STATUS_LED_PIN, ledBlinkState ? HIGH : LOW);

  if (HAS_THREE_STATUS_LEDS) {
    // GPIO25 is physically green: received peer evidence lights it, and peer
    // expiry turns it off. Transmitting alone never establishes a peer.
    digitalWrite(GREEN_LED_PIN, hasPeer ? HIGH : LOW);

    // Independent timer: mesh traffic cannot reset or suppress pending SOS.
    const bool pending = hasPendingLocalEmergency();
    if (!pending) {
      emergencyLedBlinkState = false;
    } else if (!emergencyLedWasPending) {
      emergencyLedBlinkState = true;
      lastEmergencyLedToggleMs = now;
    } else if (now - lastEmergencyLedToggleMs >= LED_SLOW_TOGGLE_MS) {
      lastEmergencyLedToggleMs = now;
      emergencyLedBlinkState = !emergencyLedBlinkState;
    }
    emergencyLedWasPending = pending;
    digitalWrite(RED_EMERGENCY_LED_PIN, emergencyLedBlinkState ? HIGH : LOW);
  }
}

bool networkSummaryFresh() {
  return lastNetworkSummarySeenMs > 0 && (millis() - lastNetworkSummarySeenMs <= NETWORK_SUMMARY_TIMEOUT_MS);
}

// =====================================================
// HELLO / DIRECT PEER DISCOVERY
// HELLO is NOT relayed. It proves direct radio reachability.
// =====================================================

void sendHelloIfNeeded() {
  if (!peerDiscovery.ready(millis(),hasFreshLoRaPeer())) return;
  sendLoRaPacket("HELLO|" + NODE_ID);
}

void handleHello(String packet, int rssi) {
  String from = getField(packet, 1);
  if (from.length() == 0 || from == NODE_ID) return;

  // Receiving the peer's own HELLO already proves direct radio reachability.
  // Field nodes do not echo an ACK because nine simultaneous responders would
  // congest the channel and could hide the heartbeat that NODE_1 must forward.
  updatePeer(from, rssi);
}

void handleHelloAck(String packet, int rssi) {
  String from = getField(packet, 1);
  String target = getField(packet, 2);

  if (target == NODE_ID) updatePeer(from, rssi);
}

// =====================================================
// HEARTBEAT
// Format:
// HB|origin|battery|hop|ttl|route|peers|sequence[|rttMs|sampleId|sampleAgeMs]
// =====================================================

void sendHeartbeatIfNeeded() {
  if (millis() - lastHeartbeatMs < heartbeatIntervalMs) return;
  lastHeartbeatMs = millis();
  heartbeatIntervalMs = HEARTBEAT_INTERVAL_MS + random(0,1000);
  heartbeatSequence++;

  String packet =
    "HB|" + NODE_ID +
    "|" + measuredBatteryVoltage() + "|0|12|" + NODE_ID +
    "|" + peerCsv(false) +
    "|" + String(heartbeatSequence);

  if (lastRoundTripMs >= 0 && millis() - lastRoundTripAtMs <= 120000) {
    packet += "|" + String(lastRoundTripMs) + "|" + lastRoundTripSample +
      "|" + String(millis() - lastRoundTripAtMs);
  }

  if (!getField(packet, 10).length()) packet += "|NA|NA|NA";
  packet += reportGuardHeartbeat();
  // Distance is mutable routing metadata, after the existing guard fields.
  packet += "|" + String(serverRouteAvailable() ? gatewayHopCount : 0);
  sendLoRaPacket(packet);
}

bool heartbeatRelayAllowed(int advertisedDistance) {
  return serverRouteAvailable() && gatewayHopCount > 0 && advertisedDistance > gatewayHopCount;
}

void handleHeartbeat(String packet) {
  String origin = getField(packet, 1);
  String battery = getField(packet, 2);
  int hop = getField(packet, 3).toInt();
  int ttl = getField(packet, 4).toInt();
  String route = getField(packet, 5);
  String originPeers = getField(packet, 6);
  String sequence = getField(packet, 7);

  if (origin.length() == 0 || origin == NODE_ID || ttl <= 0) return;
  if (routeHasNode(route, NODE_ID)) return;

  // Routine heartbeats travel only toward a confirmed gateway, not through
  // every peer at the same distance. Legacy packets retain their TTL fallback.
  const String distance = getField(packet, 14);
  if (distance.length() && !heartbeatRelayAllowed(distance.toInt())) return;

  String key = "HB:" + origin + ":" + sequence;
  if (recentlySeen(key)) return;

  String newRoute = appendRoute(route, NODE_ID);
  String forwarded =
    "HB|" + origin +
    "|" + (battery.length() ? battery : "NA") +
    "|" + String(hop + 1) +
    "|" + String(ttl - 1) +
    "|" + newRoute +
    "|" + originPeers +
    "|" + sequence;

  forwarded += "|" + getField(packet, 8) + "|" + getField(packet, 9) + "|" + getField(packet, 10);
  if (getField(packet, 13).length()) forwarded += "|" + getField(packet, 11) + "|" + getField(packet, 12) + "|" + getField(packet, 13);
  if (distance.length()) forwarded += "|" + String(gatewayHopCount);

  sendLoRaPacket(forwarded);
}

// =====================================================
// End-to-end timing uses only this origin's monotonic clock. No synchronized
// clocks, estimated one-way delay, or fabricated link speed is needed.
// PING/PONG|origin|probeId|ttl|route
// =====================================================

void sendTimingProbeIfNeeded() {
  if (!serverRouteAvailable() || millis() - lastProbeMs < PROBE_INTERVAL_MS) return;
  lastProbeMs = millis();
  pendingProbeId = probeBootId + "-" + String(++probeSequence);
  probeStartedMs = millis();
  if (!sendLoRaPacket("PING|" + NODE_ID + "|" + pendingProbeId + "|12|" + NODE_ID)) {
    pendingProbeId = "";
  }
}

void handleTimingPacket(const String& packet) {
  const String type = getField(packet, 0);
  const String origin = getField(packet, 1);
  const String probeId = getField(packet, 2);
  const int ttl = getField(packet, 3).toInt();
  const String route = getField(packet, 4);
  if (!origin.startsWith("NODE_") || probeId.length() == 0 || probeId.length() > 24) return;

  if (origin == NODE_ID) {
    if (type == "PONG" && probeId == pendingProbeId &&
        millis() - probeStartedMs <= PROBE_TIMEOUT_MS) {
      lastRoundTripMs = (long)(millis() - probeStartedMs);
      lastRoundTripAtMs = millis();
      lastRoundTripSample = probeId;
      pendingProbeId = "";
      Serial.println("[TIMING] Website round trip: " + String(lastRoundTripMs) + " ms");
    }
    return;
  }
  if (ttl <= 0 || routeHasNode(route, NODE_ID)) return;
  if (recentlySeen(type + ":" + origin + ":" + probeId, 60000)) return;
  sendLoRaPacket(type + "|" + origin + "|" + probeId + "|" + String(ttl - 1) +
    "|" + appendRoute(route, NODE_ID));
}

// =====================================================
// GATEWAY / SERVER-ROUTE BEACON
// NODE_1 sends:
// GW|gateway|sequence|hop|ttl|route
// Every regular node can relay it. The first/best route is retained.
// =====================================================

void handleGatewayBeacon(String packet) {
  String gateway = getField(packet, 1);
  String sequence = getField(packet, 2);
  int hop = getField(packet, 3).toInt();
  int ttl = getField(packet, 4).toInt();
  String route = getField(packet, 5);

  if (gateway != "NODE_1" || sequence.length() == 0) return;
  if (routeHasNode(route, NODE_ID)) return;
  if (!gatewayBeacons.accept(sequence,millis())) return;

  int ourHopToGateway = hop + 1;
  const bool wasConnected = serverRouteAvailable();
  lastGatewaySeenMs = millis();
  if (!wasConnected) {
    // Announce recovery promptly, staggered across node identities.
    heartbeatIntervalMs = HEARTBEAT_INTERVAL_MS;
    lastHeartbeatMs = millis()-HEARTBEAT_INTERVAL_MS+250+NODE_NUMBER*80+random(0,350);
    if(pendingPacket.length()&&(lastSosAttemptMs==0||millis()-lastSosAttemptMs>=5000))lastSosAttemptMs=millis()-sosRetryDelayMs;
  }

  bool newSequence = sequence != lastGatewaySequence;
  if (newSequence) {
    lastGatewaySequence = sequence;
    bestHopForGatewaySequence = 999;
  }

  // Keep the best (fewest-hop) gateway path heard for this beacon sequence.
  if (ourHopToGateway < bestHopForGatewaySequence) {
    bestHopForGatewaySequence = ourHopToGateway;
    gatewayId = gateway;
    gatewayHopCount = ourHopToGateway;
    gatewayPath = appendRoute(route, NODE_ID);
  }

  if (ttl <= 0) return;

  // Relay a new sequence, or relay a better route for the same sequence.
  if (newSequence || ourHopToGateway <= bestHopForGatewaySequence) {
    String key = "GW:" + gateway + ":" + sequence + ":" + String(ourHopToGateway);
    if (recentlySeen(key)) return;

    String forwarded =
      "GW|" + gateway +
      "|" + sequence +
      "|" + String(hop + 1) +
      "|" + String(ttl - 1) +
      "|" + appendRoute(route, NODE_ID);

    sendLoRaPacket(forwarded);
  }
}

// =====================================================
// NETWORK SUMMARY FROM SERVER / NODE_1
// Format:
// NET|activeCount|bitmask|serverTimestamp
// Relayed once by each field node. It is not used to decide a specific
// node's health locally; the server computes that from heartbeat lastSeen.
// =====================================================

void handleNetworkSummary(String packet) {
  int count = getField(packet, 1).toInt();
  uint16_t mask = (uint16_t)getField(packet, 2).toInt();
  String timestamp = getField(packet, 3);

  if (timestamp.length() == 0) return;

  activeNodeCount = count;
  activeNodeMask = mask;
  lastNetworkTimestamp = timestamp;
  lastNetworkSummarySeenMs = millis();

  String key = "NET:" + timestamp;
  if (recentlySeen(key, 30000)) return;

  sendLoRaPacket(packet);
}

// =====================================================
// SOS REPORT RELAY
// Format:
// SOS|msgId|origin|createdAt|type|message|battery|hop|ttl|route
// =====================================================

void handleSos(String packet) {
  String msgId = getField(packet, 1);
  String origin = getField(packet, 2);
  String createdAt = getField(packet, 3);
  String type = getField(packet, 4);
  String message = getField(packet, 5);
  String battery = getField(packet, 6);
  int hop = getField(packet, 7).toInt();
  int ttl = getField(packet, 8).toInt();
  String route = getField(packet, 9);

  if (msgId.length() == 0 || origin.length() == 0 || ttl <= 0) return;

  // Own packet may return through flooding; do not relay it back into the mesh.
  if (origin == NODE_ID) return;
  if (routeHasNode(route, NODE_ID)) return;

  String key = "SOS:" + msgId;
  if (recentlySeen(key)) return;

  String forwarded =
    "SOS|" + msgId +
    "|" + origin +
    "|" + createdAt +
    "|" + type +
    "|" + message +
    "|" + (battery.length() ? battery : "NA") +
    "|" + String(hop + 1) +
    "|" + String(ttl - 1) +
    "|" + appendRoute(route, NODE_ID) +
    "|" + getField(packet, 10) + "|" + getField(packet, 11);

  sendLoRaPacket(forwarded);
}

// =====================================================
// SERVER ACK RELAY
// NODE_1/server format:
// ACK|msgId|origin|NODE_1|ACKNOWLEDGED|time|ttl|route|short message
// =====================================================

void saveAcknowledgedState(String status, String ackAt, String message) {
  meshRadio.cancel("SOS:"+pendingMsgId);
  latestStatus = status;
  latestAckAt = ackAt;
  latestAckMessage = message;

  // The user may create a new report only after the current report is ACKed.
  pendingPacket = "";
  pendingMsgId = "";

  prefs.remove("pending_packet");
  prefs.remove("pending_msg");
  prefs.putString("latest_status", latestStatus);
  prefs.putString("latest_ack", latestAckMessage);
  prefs.putString("latest_ack_at", latestAckAt);
}

void handleAck(String packet) {
  String msgId = getField(packet, 1);
  String origin = getField(packet, 2);
  String gateway = getField(packet, 3);
  String status = getField(packet, 4);
  String acknowledgedAt = getField(packet, 5);
  int ttl = getField(packet, 6).toInt();
  String route = getField(packet, 7);
  String shortMessage = getField(packet, 8);

  if (msgId.length() == 0 || origin.length() == 0) return;

  if (origin == NODE_ID) {
    if (!meshVerifyOrigin(packet, true, NODE_NUMBER)) { ++meshInvalidFrames; return; }
    if (status == "RECEIVED" && gateway == "NODE_1" && acknowledgedAt.length() &&
        pendingPacket.length() && pendingMsgId == msgId) {
      serverReceiptMsgId = msgId;
      meshRadio.cancel("SOS:"+msgId);
      lastSosAttemptMs = millis();
      sosRetryDelayMs = 60000 + random(0, 5000);
      latestStatus = "SUBMITTED - UNDER ADMIN REVIEW";
      prefs.putString("receipt_msg", msgId);
      prefs.putString("latest_status", latestStatus);
      lastGatewaySeenMs = millis();
      Serial.println("[RECEIPT] Server received " + msgId + "; waiting for responder review.");
      return; // A server receipt never clears the local SOS queue or red LED.
    }
    if (latestAckAt.length() && acknowledgedAt.compareTo(latestAckAt) < 0) return;
    // Only a matching final response from the established gateway may clear
    // the local queue (and consequently its emergency LED).
    if ((latestMsgId == msgId || pendingMsgId == msgId) &&
        gateway == "NODE_1" && acknowledgedAt.length() > 0 &&
        (status == "ACKNOWLEDGED" || status == "REJECTED")) {
      saveAcknowledgedState(
        status.length() ? status : "ACKNOWLEDGED",
        acknowledgedAt,
        getField(packet, 9) + ": " + (shortMessage.length() ? shortMessage : "Your emergency report has been acknowledged.")
      );

      Serial.println("[ACK] Report " + msgId + " acknowledged by server via " + gateway);
    }
    return;
  }

  if (ttl <= 0 || routeHasNode(route, NODE_ID)) return;

  String key = "ACK:" + msgId + ":" + status + ":" + acknowledgedAt;
  if (recentlySeen(key, 15000)) return;

  String forwarded =
    "ACK|" + msgId +
    "|" + origin +
    "|" + gateway +
    "|" + status +
    "|" + acknowledgedAt +
    "|" + String(ttl - 1) +
    "|" + appendRoute(route, NODE_ID) +
    "|" + shortMessage + "|" + getField(packet, 9) + "|" + getField(packet, 10);

  sendLoRaPacket(forwarded);
}

// =====================================================
// RECEIVE / DISPATCH LORA PACKETS
// =====================================================

void handleIncomingLoRa() {
  if (meshRadio.busy()) return;
  int packetSize = LoRa.parsePacket();
  if (!packetSize) return;
  markLoRaActivity();

  String packet = meshReadFrame(packetSize);
  const int rssi = LoRa.packetRssi();
  const float snr = LoRa.packetSnr();
  const long frequencyError = LoRa.packetFrequencyError();
  LoRa.receive(); // parsePacket leaves standby; resume listening before dispatch/NVS work.
  if (!packet.length()) {
    static bool logged = false; static unsigned long lastLog = 0;
    if (!logged || millis() - lastLog >= 5000) {
      logged = true; lastLog = millis();
      Serial.println("[LORA DROP] " + String(meshLastFrameError) + "; rejected=" + String(meshInvalidFrames));
    }
    return;
  }

  AksyResRadio::record(rssi, snr, frequencyError, meshLastFrameSender);
  if (meshLastFrameSender != MESH_THIS_NODE) updatePeer("NODE_" + String(meshLastFrameSender), rssi);

  Serial.print("[LORA RX RSSI ");
  Serial.print(rssi);
  Serial.print(" dBm, SNR ");
  Serial.print(snr, 2);
  Serial.print(" dB] ");
  Serial.println(packet);

  String type = getField(packet, 0);

  if (type == "HELLO") {
    handleHello(packet, rssi);
  } else if (type == "HELLO_ACK") {
    handleHelloAck(packet, rssi);
  } else if (type == "HB") {
    handleHeartbeat(packet);
  } else if (type == "GW") {
    handleGatewayBeacon(packet);
  } else if (type == "NET") {
    handleNetworkSummary(packet);
  } else if (type == "SOS") {
    handleSos(packet);
  } else if (type == "ACK") {
    handleAck(packet);
  } else if (type == "PING" || type == "PONG") {
    handleTimingPacket(packet);
  }
}

// =====================================================
// LOCAL REPORT CREATION / QUEUE / RETRY
// =====================================================

void savePendingState() {
  prefs.putString("latest_msg", latestMsgId);
  prefs.putString("latest_created", latestCreatedAt);
  prefs.putString("latest_status", latestStatus);
  prefs.putString("latest_ack", latestAckMessage);
  prefs.putString("latest_ack_at", latestAckAt);
  prefs.putString("pending_packet", pendingPacket);
  prefs.putString("pending_msg", pendingMsgId);
}

void trySendPending(bool forceNow = false) {
  if (pendingPacket.length() == 0) return;

  if (!serverRouteAvailable()) {
    if (serverReceiptMsgId != pendingMsgId && latestStatus != "QUEUED - WAITING FOR ROUTE") {
      latestStatus = "QUEUED - WAITING FOR ROUTE";
      prefs.putString("latest_status", latestStatus);
    }
    return;
  }

  if (!forceNow && millis() - lastSosAttemptMs < sosRetryDelayMs) return;

  lastSosAttemptMs = millis();
  sosRetryDelayMs = (serverReceiptMsgId == pendingMsgId ? 60000 : SOS_RETRY_INTERVAL_MS) + random(0, 5000);

  bool queued = sendLoRaPacket(pendingPacket);
  if (queued) {
    if (serverReceiptMsgId != pendingMsgId) {
      latestStatus = "QUEUED FOR RADIO";
      prefs.putString("latest_status", latestStatus);
    }
  }
}

void handleLocalSend() {
  String message = localServer.arg("message"), landmark = localServer.arg("landmark");
  String type = localServer.arg("type"), createdAt = localServer.arg("created_at");
  message.trim(); landmark.trim(); createdAt.trim();
  if (!meshSecurityReady || localServer.arg("confirmed") != "1" || type != "ROAD_ACCIDENT" ||
      !message.length() || message.length() > 70 || !landmark.length() || landmark.length() > 32 ||
      message.indexOf('|') >= 0 || landmark.indexOf('|') >= 0 || message.indexOf('\n') >= 0 ||
      landmark.indexOf('\n') >= 0 || message.indexOf('\r') >= 0 || landmark.indexOf('\r') >= 0 ||
      message.indexOf('\t') >= 0 || landmark.indexOf('\t') >= 0 || createdAt.length() != 10) {
    localServer.send(400, "text/plain", "Confirm a Road Accident report with description and actual location/landmark. Limits: 70 and 32 UTF-8 bytes."); return;
  }
  for (size_t i = 0; i < createdAt.length(); ++i) if (!isDigit(createdAt[i])) {
    localServer.send(400, "text/plain", "Invalid report time."); return;
  }
  if (!meshReportFits(message, landmark, measuredBatteryVoltage())) {
    localServer.send(400, "text/plain", "Shorten the description or landmark so the complete report fits the radio route."); return;
  }
  const uint32_t clientAddress = (uint32_t)localServer.client().remoteIP();
  const uint32_t retry = reportRetrySeconds(clientAddress, millis());
  if (retry) {
    reportRecordBlocked(); localServer.sendHeader("Retry-After", String(retry));
    localServer.send(429, "text/plain", "Anti-spam protection: wait " + String(retry) + " seconds. Your saved report continues retrying automatically."); return;
  }
  if (pendingPacket.length() > 0) {
    reportRecordBlocked(); localServer.send(409, "text/plain", "This node already has a report waiting for admin acknowledgment. Check Latest Report."); return;
  }
  if (!reportConsumeConfirmation(clientAddress, reportConfirmationBody(message, landmark, type, createdAt), localServer.arg("confirmation"))) {
    localServer.send(400, "text/plain", "Confirmation expired, already used, or report details changed. Review the report again."); return;
  }
  if (prefs.getUInt("seq", 0) == UINT32_MAX || !reportGuardAccept(clientAddress, millis())) {
    reportRecordBlocked(); localServer.sendHeader("Retry-After", "60");
    localServer.send(429, "text/plain", "Submission protection active. Wait 60 seconds or contact the emergency team."); return;
  }
  uint32_t sequence = prefs.getUInt("seq", 0) + 1;
  if (prefs.putUInt("seq", sequence) != 4) {
    localServer.send(503, "text/plain", "Could not save the report ID. Report was not submitted."); return;
  }

  latestMsgId = NODE_ID + "-" + String(sequence);
  latestCreatedAt = createdAt;
  latestAckMessage = "";
  latestAckAt = "";
  latestStatus = serverRouteAvailable() ? "READY TO SEND" : "QUEUED - WAITING FOR ROUTE";

  // The enabled sampler needs the physical divider for a valid battery voltage.
  // The exact createdAt received from the phone is permanently embedded here.
  pendingPacket =
    "SOS|" + latestMsgId +
    "|" + NODE_ID +
    "|" + createdAt +
    "|R|" + message +
    "|" + measuredBatteryVoltage() + "|0|12|" + NODE_ID + "|" + landmark;
  pendingPacket = meshSignOrigin(pendingPacket, false, NODE_NUMBER);

  pendingMsgId = latestMsgId;
  serverReceiptMsgId = "";
  prefs.remove("receipt_msg");
  savePendingState();

  if (serverRouteAvailable()) {
    trySendPending(true);
    localServer.send(200, "text/plain", "Report saved. Radio delivery is pending; this node will retry automatically. Wait for the server receipt in Latest report.");
  } else {
    localServer.send(200, "text/plain", "No confirmed server route. Report saved on this node and will auto-send when a route becomes available.");
  }
}

// =====================================================
// LOCAL STATUS API
// =====================================================

void handleLocalStatus() {
  int peerCount = activePeerCount();
  String peerList = peerCsv(true);

  int displayActiveCount = networkSummaryFresh() ? activeNodeCount : -1;

  String json = "{";
  json += AksyResRadio::statusFields();
  json += meshRadio.statusFields();
  json += reportGuardStatus((uint32_t)localServer.client().remoteIP());
  json += "\"battery\":\"" + measuredBatteryVoltage() + "\",";
  json += "\"round_trip_ms\":" + (lastRoundTripMs < 0 ? String("null") : String(lastRoundTripMs)) + ",";
  json += "\"node_id\":\"" + jsonEscape(NODE_ID) + "\",";
  json += "\"peer_count\":" + String(peerCount) + ",";
  json += "\"peer_list\":\"" + jsonEscape(peerList) + "\",";
  json += "\"active_nodes\":" + String(displayActiveCount) + ",";
  json += "\"expected_nodes\":" + String(expectedNodeCount) + ",";
  json += "\"server_route\":" + String(serverRouteAvailable() ? "true" : "false") + ",";
  json += "\"gateway_path\":\"" + jsonEscape(gatewayPath) + "\",";
  json += "\"pending\":" + String(pendingPacket.length() > 0 ? "true" : "false") + ",";
  json += "\"message_id\":\"" + jsonEscape(latestMsgId) + "\",";
  json += "\"created_at\":\"" + jsonEscape(latestCreatedAt) + "\",";
  json += "\"report_status\":\"" + jsonEscape(latestStatus) + "\",";
  json += "\"ack_message\":\"" + jsonEscape(latestAckMessage) + "\",";
  json += "\"ack_at\":\"" + jsonEscape(latestAckAt) + "\"";
  json += "}";

  localServer.send(200, "application/json", json);
}

// =====================================================
// LOCAL WI-FI AP
// =====================================================

void startLocalWiFi() {
  WiFi.mode(WIFI_AP);
  // Omitting the passphrase creates an open AP for civilian emergency access.
  if (!WiFi.softAP(LOCAL_AP_SSID.c_str())) {
    Serial.println("ERROR: Could not start the open local Wi-Fi access point.");
    return;
  }

  Serial.print("Local Wi-Fi: ");
  Serial.println(LOCAL_AP_SSID);
  Serial.println("Wi-Fi access: OPEN (no password required)");
  Serial.print("Local dashboard: http://");
  Serial.println(WiFi.softAPIP());
}

// =====================================================
// PERSISTENT STATE
// =====================================================

void loadPersistentState() {
  prefs.begin("fieldnode", false);

  // If this physical ESP32 is reassigned to a different NODE_NUMBER,
  // clear stale queued data from the previous identity.
  String storedNodeId = prefs.getString("node_id", "");
  if (storedNodeId.length() > 0 && storedNodeId != NODE_ID) {
    prefs.clear();
  }
  prefs.putString("node_id", NODE_ID);

  latestMsgId = prefs.getString("latest_msg", "");
  latestCreatedAt = prefs.getString("latest_created", "");
  latestStatus = prefs.getString("latest_status", "NONE");
  latestAckMessage = prefs.getString("latest_ack", "");
  latestAckAt = prefs.getString("latest_ack_at", "");
  pendingPacket = prefs.getString("pending_packet", "");
  pendingMsgId = prefs.getString("pending_msg", "");
  serverReceiptMsgId = prefs.getString("receipt_msg", "");
}

// =====================================================
// SETUP / LOOP
// =====================================================

void setup() {
  initializeLedIndicators();
  Serial.begin(115200);
  delay(1000);

  if (NODE_NUMBER < 2 || NODE_NUMBER > 10) {
    Serial.println("ERROR: NODE_NUMBER must be from 2 to 10 for this field-node firmware.");
    while (true) delay(1000);
  }

  NODE_ID = "NODE_" + String(NODE_NUMBER);
  LOCAL_AP_SSID = "Node " + String(NODE_NUMBER) + " Emergency";

  loadPersistentState();
  if (MESH_THIS_NODE != NODE_NUMBER || !meshSecurityBegin()) {
    Serial.println("ERROR: Mesh keys do not match node identity or security storage failed.");
    while (true) delay(1000);
  }
  updateLedIndicators();  // Restore pending-report indication after reboot.

  randomSeed(esp_random());
  probeBootId = String(esp_random(), HEX);

  // Randomize heartbeat/probe phase; discovery starts after the radio is ready.
  unsigned long now = millis();
  lastHeartbeatMs = now - HEARTBEAT_INTERVAL_MS + random(0, HEARTBEAT_INTERVAL_MS);
  lastProbeMs = now - PROBE_INTERVAL_MS + NODE_NUMBER * 1200 + random(0, 1000);

  startLocalWiFi();

  localServer.on("/", HTTP_GET, []() {
    String page = FPSTR(LOCAL_DASHBOARD);
    page.replace("</body>", String(FPSTR(REPORT_GUARD_SCRIPT)) + "</body>");
    localServer.sendHeader("Cache-Control", "no-store");
    localServer.send(200, "text/html", page);
  });
  localServer.on("/status", HTTP_GET, handleLocalStatus);
  localServer.on("/prepare", HTTP_POST, []() {
    if (localServer.arg("message").length() > 70 || localServer.arg("landmark").length() > 32) {
      localServer.send(400, "text/plain", "Report exceeds description or location limit."); return;
    }
    if (!meshReportFits(localServer.arg("message"), localServer.arg("landmark"), measuredBatteryVoltage())) {
      localServer.send(400, "text/plain", "Shorten the description or landmark so the complete report fits the radio route."); return;
    }
    const String token = reportPrepare((uint32_t)localServer.client().remoteIP(), reportConfirmationBody(
      localServer.arg("message"), localServer.arg("landmark"), localServer.arg("type"), localServer.arg("created_at")));
    localServer.sendHeader("Cache-Control", "no-store");
    if (!token.length()) { localServer.send(429, "text/plain", "Too many report confirmations. Try again shortly."); return; }
    localServer.send(200, "application/json", "{\"confirmation\":\"" + token + "\"}");
  });
  localServer.on("/send", HTTP_POST, handleLocalSend);
  localServer.begin();

  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_SS);
  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);

  if (!LoRa.begin(LORA_FREQUENCY)) {
    Serial.println("ERROR: LoRa initialization failed. Check 3.3 V power and NSS/SCK/MISO/MOSI/REST/DIO0 wiring.");
    while (true) {
      updateLedIndicators();
      delay(2);
    }
  }

  AksyResRadio::configure(LoRa);
  meshRadioPort.begin(LORA_SS);
  meshRadio.begin(esp_random());
  peerDiscovery.begin(millis(),NODE_NUMBER,esp_random());
  LoRa.receive();
  Serial.println("[RADIO] " + AksyResRadio::summary());

  Serial.println();
  Serial.println(NODE_ID + " field node ready.");
  Serial.println("Battery sensing: " + String(BATTERY_MONITOR_ENABLED ? "enabled" : "disabled (no voltage reading)"));
  if (BATTERY_MONITOR_ENABLED) {
    Serial.println("Battery ADC: GPIO" + String(BATTERY_ADC_PIN) + " (connect voltage divider for valid readings)");
  }
  Serial.println("Waiting for LoRa peers and NODE_1 server-route beacon...");
}

void loop() {
  meshRadio.tick(millis());
  handleIncomingLoRa();
  localServer.handleClient();

  // Telemetry is coalesced; the radio queue schedules SOS/ACK first and leaves
  // receive time between transmissions. Discovery is fast while disconnected.
  sendHeartbeatIfNeeded();
  sendHelloIfNeeded();
  trySendPending(false);
  sendTimingProbeIfNeeded();
  meshRadio.tick(millis());

  updateLedIndicators();

  delay(2);
}
