/*
  NODE_1 LoRa mesh gateway for the official Laravel API.

  Field-node traffic stays on LoRa until this gateway has a working route to
  the configured Laravel server. SOS packets remain pending at their origin
  until a responder acknowledgment returns through this gateway.

  Copy arduino_secrets.example.h to arduino_secrets.h, then configure Wi-Fi,
  server URL, gateway token, and (for HTTPS) the server root CA certificate.

  Active-high LEDs: green GPIO25 = direct LoRa peer, yellow GPIO32 = Laravel
  route / LoRa activity, red GPIO33 = local report awaiting responder ACK.
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>
#include <SPI.h>
#include <LoRa.h>
#include "radio_profile.h"
#include <time.h>
#include <esp_system.h>
#include "server_root_ca.h"
#include "battery_monitor.h"
#include "mesh_security.h"
#include "mesh_sx127x.h"
#include "report_guard.h"
#include "report_dashboard.h"
#include "gateway_runtime.h"

#if __has_include("arduino_secrets.h")
#include "arduino_secrets.h"
#else
#error "Copy arduino_secrets.example.h to arduino_secrets.h and configure NODE_1 before compiling."
#endif

#define NODE_ID "NODE_1"

const char* LOCAL_AP_SSID = "NODE_1_Gateway";

const long LORA_FREQUENCY = AksyResRadio::frequency;
const int LORA_SS = 5;
const int LORA_SCK = 18;
const int LORA_MISO = 19;
const int LORA_MOSI = 23;
const int LORA_RST = 27;
const int LORA_DIO0 = 26;
const int GREEN_LED_PIN = 25;          // Fresh direct LoRa peer
const int YELLOW_STATUS_LED_PIN = 32;  // Laravel API availability / LoRa traffic
const int RED_EMERGENCY_LED_PIN = 33;  // NODE_1's own pending report only

static_assert(!BATTERY_MONITOR_ENABLED ||
  (BATTERY_ADC_PIN != GREEN_LED_PIN && BATTERY_ADC_PIN != YELLOW_STATUS_LED_PIN &&
   BATTERY_ADC_PIN != RED_EMERGENCY_LED_PIN),
  "Battery ADC conflicts with an active gateway LED GPIO. Check battery_config.h.");

const unsigned long SERVER_CHECK_INTERVAL_MS = 30000;
const unsigned long HEARTBEAT_INTERVAL_MS = 10000;
const unsigned long BEACON_INTERVAL_MS = 5000;
const unsigned long NETWORK_POLL_INTERVAL_MS = 30000;
const unsigned long ACK_POLL_INTERVAL_MS = 10000;
const unsigned long SERVER_ROUTE_TIMEOUT_MS = 30000;
const unsigned long LOCAL_REPORT_RETRY_INTERVAL_MS = 15000;
const unsigned long LED_ACTIVITY_MS = 700;
const unsigned long LED_FAST_TOGGLE_MS = 125;
const unsigned long LED_SLOW_TOGGLE_MS = 600;

WiFiClient plainClient;
WiFiClientSecure secureClient;
WebServer localServer(80);
DNSServer dnsServer;
Preferences prefs;
MeshSX127xPort meshRadioPort;
void onRadioTransmitted(const String& packet);
MeshLink::Pump<MeshSX127xPort> meshRadio(meshRadioPort,onRadioTransmitted);
String radioAckToConfirm;
String radioAckConfirmToken, radioAckQueuedPacket, radioAckQueuedToken, lastRadioAckNotified;
String gatewayBeaconBoot;
const byte DNS_PORT = 53;

unsigned long lastServerCheckMs = 0;
unsigned long lastServerSuccessMs = 0;
unsigned long lastHeartbeatMs = 0;
unsigned long lastBeaconMs = 0;
unsigned long lastNetworkPollMs = 0;
unsigned long lastAckPollMs = 0;
unsigned long lastWifiReconnectMs = 0;
unsigned long lastLocalReportRetryMs = 0;
unsigned long lastLedActivityMs = 0;
unsigned long lastLedToggleMs = 0;
bool ledActivityPending = false;
bool ledActivityActive = false;
bool ledBlinkState = false;
unsigned long lastEmergencyLedToggleMs = 0;
bool emergencyLedBlinkState = false;
bool emergencyLedWasPending = false;
unsigned long gatewaySequence = 0;
String gatewayStatus = "Starting";
String lastForwardedMessage = "None";
String latestMsgId = "";
String latestCreatedAt = "";
String latestReportStatus = "NONE";
String latestAckMessage = "";
String latestAckAt = "";
String pendingLocalPacket = "";
String pendingLocalMsgId = "";
int activeNodeCount = -1;
long websiteRoundTripMs = -1;
unsigned long websiteRoundTripAtMs = 0;
String websiteRoundTripSample;
String lastProbeIds[11];
String lastHeartbeatSequences[11];
unsigned long lastHeartbeatForwardedAtMs[11] = {};
QueueHandle_t apiWorkQueue = nullptr;
QueueHandle_t apiResultQueue = nullptr;
bool apiRequestBusy = false;
bool apiWorkerReady = false;
uint32_t apiRequestSequence = 0;
unsigned long lastApiFinishedMs = 0;
int lastApiStatusCode = 0;
bool batchHeartbeatsSupported = true;
GatewayRadioItem sosInbox[11], heartbeatInbox[11], probeInbox[11];
int nextSosNode = 2, nextHeartbeatNode = 2, nextProbeNode = 2;
uint8_t routerPhase = 0;
bool routerEverAttempted = false, routerWasConnected = false;
unsigned long routerPhaseAtMs = 0;

struct PeerInfo {
  String nodeId;
  int rssi;
  unsigned long lastSeenMs;
};

const int MAX_PEERS = 10;
const unsigned long PEER_TIMEOUT_MS = 40000;
PeerInfo peers[MAX_PEERS];

const char LOCAL_DASHBOARD[] PROGMEM = R"rawliteral(
<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#116530"><title>LoRa Emergency NODE 1</title>
<style>
*{box-sizing:border-box}body{font:16px Arial,sans-serif;margin:0;background:#f4f7f5;color:#14251a}header{background:#116530;color:white;padding:18px;text-align:center}main{max-width:560px;margin:auto;padding:14px}.card{background:white;padding:16px;margin-bottom:12px;border-radius:14px;box-shadow:0 2px 8px #0001}.label{font-size:13px;color:#647168}.value{font-size:18px;font-weight:bold;margin:4px 0 11px}.ok{color:#13833c}.warn{color:#c67a00}.bad{color:#b52a25}select,textarea,button{width:100%;padding:12px;margin-top:8px;border-radius:10px;border:1px solid #ccd6cf;font-size:16px}textarea{min-height:84px}button{background:#116530;color:white;border:0;font-weight:bold}button:disabled{background:#8b948e}.small{font-size:13px;color:#666;margin-top:8px}.modal{display:none;position:fixed;z-index:10;inset:0;background:#0009;align-items:center;justify-content:center;padding:18px}.modal.show{display:flex}.box{background:#fff;width:100%;max-width:400px;padding:22px;border-radius:18px}.box h2{color:#116530}.ack{background:#f4f7f5;padding:12px;border-radius:10px;word-break:break-word}
</style></head><body><header><h2>LoRa Emergency System</h2><div>Connected to NODE 1</div></header><main>
<section class="card"><div class="label">Laravel server</div><div id="server" class="value">Checking...</div><div class="label">Nearby LoRa nodes</div><div id="peers">None detected</div><div class="label" style="margin-top:10px">Active nodes</div><div id="active" class="value">-- / 10</div></section>
<section class="card"><h3>Emergency report</h3><select id="type"><option value="ROAD_ACCIDENT">Road Accident</option><option value="MEDICAL_EMERGENCY">Medical Emergency</option><option value="FIRE">Fire</option><option value="FLOOD">Flood</option><option value="SECURITY">Security</option><option value="OTHER">Other</option></select><textarea id="message" maxlength="70" placeholder="Describe the emergency briefly"></textarea><div class="small"><span id="remaining">70</span> characters remaining</div><button id="send" onclick="sendReport()">SEND EMERGENCY</button><div id="hint" class="small"></div></section>
<section class="card"><h3>Latest report</h3><div class="label">Message ID</div><div id="msgid">None</div><div class="label" style="margin-top:8px">Submitted</div><div id="created">None</div><div class="label" style="margin-top:8px">Status</div><div id="status" class="value">None</div><div class="label">Responder message</div><div id="ack">None</div></section></main>
<div id="ackModal" class="modal"><div class="box"><h2>Report acknowledged</h2><p>Your report has a response from the emergency team.</p><div class="ack"><div class="label">Report</div><b id="modalId">-</b><div class="label" style="margin-top:10px">Message</div><b id="modalMessage">-</b><div class="label" style="margin-top:10px">Time</div><b id="modalTime">-</b></div><button onclick="closeAck()">OK</button></div></div>
<script>
const $=id=>document.getElementById(id);let shownAck='';$('message').addEventListener('input',()=>$('remaining').textContent=70-$('message').value.length);
function localTime(){const d=new Date(),p=n=>String(n).padStart(2,'0'),off=-d.getTimezoneOffset(),sign=off>=0?'+':'-';return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+'T'+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds())+sign+p(Math.floor(Math.abs(off)/60))+':'+p(Math.abs(off)%60)}
function closeAck(){localStorage.setItem('node1_ack_seen_'+shownAck,'1');$('ackModal').classList.remove('show')}
async function refresh(){try{const r=await fetch('/status',{cache:'no-store'}),x=await r.json();$('server').innerHTML=x.server_online?'<span class="ok">Connected</span>':'<span class="warn">Unavailable</span>';$('peers').textContent=x.peer_list||'None detected';$('active').textContent=(x.active_nodes<0?'--':x.active_nodes)+' / 10';$('msgid').textContent=x.message_id||'None';$('created').textContent=x.created_at||'None';$('status').textContent=x.report_status||'None';$('ack').textContent=x.ack_message||'None';$('send').disabled=x.pending;$('send').textContent=x.pending?'WAITING FOR RESPONDER':'SEND EMERGENCY';$('hint').textContent=x.pending?'This report is saved until a responder acknowledgment arrives.':(x.server_online?'Server available.':'Server unavailable; NODE 1 will retry the saved report.');if((x.report_status==='ACKNOWLEDGED'||x.report_status==='REJECTED')&&x.message_id&&x.message_id!==shownAck&&!localStorage.getItem('node1_ack_seen_'+x.message_id)){shownAck=x.message_id;$('modalId').textContent=x.message_id;$('modalMessage').textContent=x.ack_message||'Your report has a responder update.';$('modalTime').textContent=x.ack_at||'-';$('ackModal').classList.add('show')}}catch(e){}}

</script></body></html>
)rawliteral";

String jsonEscape(const String& value) {
  String output;
  output.reserve(value.length() + 8);
  for (unsigned int index = 0; index < value.length(); index++) {
    const unsigned char character = value[index];
    if (character == '\\') output += "\\\\";
    else if (character == '"') output += "\\\"";
    else if (character == '\r' || character == '\n' || character == '\t') output += ' ';
    else if (character >= 0x20) output += (char)character;
  }
  return output;
}

String urlEncode(const String& value) {
  const char* hex = "0123456789ABCDEF";
  String output;
  for (unsigned int i = 0; i < value.length(); i++) {
    const unsigned char character = value[i];
    if ((character >= 'a' && character <= 'z') ||
        (character >= 'A' && character <= 'Z') ||
        (character >= '0' && character <= '9') ||
        character == '-' || character == '_' || character == '.' || character == '~') {
      output += (char)character;
    } else {
      output += '%';
      output += hex[character >> 4];
      output += hex[character & 0x0F];
    }
  }
  return output;
}

String getField(const String& data, int index) {
  int start = 0;
  for (int field = 0; field < index; field++) {
    int separator = data.indexOf('|', start);
    if (separator < 0) return "";
    start = separator + 1;
  }
  int end = data.indexOf('|', start);
  if (end < 0) end = data.length();
  return data.substring(start, end);
}

String appendGatewayToRoute(String route) {
  route.trim();
  if (route.length() == 0) return NODE_ID;
  String paddedRoute = ">" + route + ">";
  if (paddedRoute.indexOf(">" NODE_ID ">") >= 0) return route;
  return route + ">" NODE_ID;
}

String extractJsonString(const String& json, const String& key) {
  String token = "\"" + key + "\":\"";
  int start = json.indexOf(token);
  if (start < 0) return "";
  start += token.length();

  String output;
  bool escaped = false;
  for (int index = start; index < json.length(); index++) {
    const char character = json[index];
    if (escaped) {
      if (character == 'n') output += '\n';
      else output += character;
      escaped = false;
    } else if (character == '\\') {
      escaped = true;
    } else if (character == '"') {
      break;
    } else {
      output += character;
    }
  }
  return output;
}

int extractJsonInt(const String& json, const String& key, int fallback = -1) {
  String token = "\"" + key + "\":";
  int start = json.indexOf(token);
  if (start < 0) return fallback;
  start += token.length();
  while (start < json.length() && json[start] == ' ') start++;
  int end = start;
  if (end < json.length() && json[end] == '-') end++;
  while (end < json.length() && isDigit(json[end])) end++;
  if (end == start) return fallback;
  return json.substring(start, end).toInt();
}

void updatePeer(const String& nodeId, int rssi) {
  if (nodeId.length() == 0 || nodeId == NODE_ID) return;
  const unsigned long now = millis();
  for (int index = 0; index < MAX_PEERS; index++) {
    if (peers[index].nodeId == nodeId) {
      peers[index].rssi = rssi;
      peers[index].lastSeenMs = now;
      return;
    }
  }
  for (int index = 0; index < MAX_PEERS; index++) {
    if (peers[index].nodeId.length() == 0 || now - peers[index].lastSeenMs > PEER_TIMEOUT_MS) {
      peers[index].nodeId = nodeId;
      peers[index].rssi = rssi;
      peers[index].lastSeenMs = now;
      return;
    }
  }
}

bool hasFreshLoRaPeer() {
  const unsigned long now = millis();
  for (int index = 0; index < MAX_PEERS; index++) {
    if (peers[index].nodeId.length() > 0 && now - peers[index].lastSeenMs <= PEER_TIMEOUT_MS) return true;
  }
  return false;
}

String peerList() {
  String result;
  const unsigned long now = millis();
  for (int index = 0; index < MAX_PEERS; index++) {
    if (peers[index].nodeId.length() == 0 || now - peers[index].lastSeenMs > PEER_TIMEOUT_MS) continue;
    if (result.length() > 0) result += ", ";
    result += peers[index].nodeId + " (" + String(peers[index].rssi) + " dBm)";
  }
  return result;
}

String peerIdsJson() {
  String result = "[";
  bool first = true;
  const unsigned long now = millis();
  for (int index = 0; index < MAX_PEERS; index++) {
    if (peers[index].nodeId.length() == 0 || now - peers[index].lastSeenMs > PEER_TIMEOUT_MS) continue;
    if (!first) result += ",";
    result += "\"" + jsonEscape(peers[index].nodeId) + "\"";
    first = false;
  }
  result += "]";
  return result;
}

bool isConfigured() {
  return String(ROUTER_WIFI_SSID) != "YOUR_WIFI_NAME" &&
         String(SERVER_BASE_URL).indexOf("YOUR_LARAVEL_HOST") < 0 &&
         String(ROUTER_WIFI_PASSWORD) != "YOUR_WIFI_PASSWORD" &&
         String(LORA_GATEWAY_TOKEN) != "SET_THE_SAME_LORA_INGEST_TOKEN";
}

bool beginHttp(HTTPClient& http, const String& url) {
  if (url.startsWith("https://")) {
    if (String(SERVER_ROOT_CA_CERT).length() == 0) {
      Serial.println("[HTTPS] Configure SERVER_ROOT_CA_CERT; certificate verification is required.");
      return false;
    }
    secureClient.setCACert(SERVER_ROOT_CA_CERT);
    secureClient.setHandshakeTimeout(7);
    return http.begin(secureClient, url);
  }

  return http.begin(plainClient, url);
}

String apiUrl(const String& path) {
  String base = SERVER_BASE_URL;
  while (base.endsWith("/")) base.remove(base.length() - 1);
  return base + "/api/v1/lora" + path;
}

void gatewayApiWorker(void*) {
  for (;;) {
    GatewayApiWork* work = nullptr;
    if (xQueueReceive(apiWorkQueue, &work, portMAX_DELAY) != pdTRUE || !work) continue;
    const unsigned long started = millis();
    HTTPClient http;
    http.setConnectTimeout(7000);
    http.setTimeout(7000);
    if (beginHttp(http, apiUrl(work->path))) {
      http.addHeader("Accept", "application/json");
      http.addHeader("Content-Type", "application/json");
      http.addHeader("X-STITCH-LORA-TOKEN", LORA_GATEWAY_TOKEN);
      work->status = work->method == "POST" ? http.POST(work->body) : http.GET();
      work->response = http.getString();
    }
    http.end();
    work->elapsedMs = millis() - started;
    xQueueSend(apiResultQueue, &work, portMAX_DELAY);
  }
}

bool startGatewayApiWorker() {
  apiWorkQueue = xQueueCreate(1, sizeof(GatewayApiWork*));
  apiResultQueue = xQueueCreate(1, sizeof(GatewayApiWork*));
  if (!apiWorkQueue || !apiResultQueue) return false;
  return xTaskCreatePinnedToCore(gatewayApiWorker, "gateway-http", 16384,
    nullptr, 1, nullptr, 0) == pdPASS;
}

bool requestApi(const String& method, const String& path, const String& body, String& responseBody) {
  if (apiRequestBusy || !apiWorkerReady || !apiWorkQueue || !apiResultQueue ||
      !isConfigured() || WiFi.status() != WL_CONNECTED) return false;
  if (String(SERVER_BASE_URL).startsWith("https://") && time(nullptr) < 1700000000) {
    gatewayStatus = "Waiting for internet time before HTTPS";
    return false;
  }
  GatewayApiWork* work = new GatewayApiWork;
  work->method = method; work->path = path; work->body = body;
  if (xQueueSend(apiWorkQueue, &work, 0) != pdTRUE) { delete work; return false; }
  apiRequestBusy = true;
  ++apiRequestSequence;
  GatewayApiWork* completed = nullptr;
  while (xQueueReceive(apiResultQueue, &completed, 0) != pdTRUE) {
    // Only this task touches LoRa, peer state, security counters and NVS.
    // Incoming SOS is durably queued, never recursively sent over HTTPS here.
    serviceGatewayRadio();
    delay(2);
  }
  apiRequestBusy = false;
  lastApiFinishedMs = millis();
  const int statusCode = completed->status;
  lastApiStatusCode = statusCode;
  const unsigned long requestElapsedMs = completed->elapsedMs;
  responseBody = completed->response;
  delete completed;
  if (statusCode < 0) {
    Serial.println("[API connection] " + HTTPClient::errorToString(statusCode));
  } else if (statusCode == 401 || statusCode == 403) {
    Serial.println("[API auth] Match LORA_GATEWAY_TOKEN with LORA_INGEST_TOKEN on the hosted website.");
  }
  if (statusCode == 422) Serial.println("[API validation] " + extractJsonString(responseBody, "message"));
  Serial.println("[API " + method + "] " + path + " -> " + String(statusCode));
  if (statusCode >= 200 && statusCode < 300) {
    if (path == "/health") {
      websiteRoundTripMs = (long)requestElapsedMs;
      websiteRoundTripAtMs = millis();
      websiteRoundTripSample = String(esp_random(), HEX) + "-" + String(websiteRoundTripAtMs);
    }
    lastServerSuccessMs = millis();
    gatewayStatus = "Laravel server connected";
    // Show confirmation and advertise any due beacon before the next blocking
    // HTTPS request. A chain of API calls must not starve field-node routes.
    updateLedIndicators();
    sendGatewayBeaconIfNeeded();
    return true;
  }
  gatewayStatus = "Laravel server unavailable (HTTP " + String(statusCode) + ")";
  return false;
}

bool serverRouteAvailable() {
  return WiFi.status() == WL_CONNECTED && lastServerSuccessMs > 0 &&
    millis() - lastServerSuccessMs <= SERVER_ROUTE_TIMEOUT_MS;
}

bool hasConfirmedServerRoute() {
  // requestApi records only HTTP 2xx, so Wi-Fi association alone cannot light solid.
  return serverRouteAvailable();
}

bool hasPendingLocalEmergency() {
  // Forwarded field-node reports never populate this local persistent queue.
  return pendingLocalPacket.length() > 0;
}

void initializeLedIndicators() {
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(YELLOW_STATUS_LED_PIN, OUTPUT);
  pinMode(RED_EMERGENCY_LED_PIN, OUTPUT);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(YELLOW_STATUS_LED_PIN, LOW);
  digitalWrite(RED_EMERGENCY_LED_PIN, LOW);
}

void updateLedIndicators() {
  const unsigned long now = millis();
  if (ledActivityPending) {
    lastLedActivityMs = now;
    ledActivityActive = true;
    ledActivityPending = false;
  }

  // Green reports radio reachability independently of Wi-Fi or Laravel.
  digitalWrite(GREEN_LED_PIN, hasFreshLoRaPeer() ? HIGH : LOW);

  // Confirmed server access takes priority: routine radio traffic must never
  // make a working server connection look unavailable.
  const bool activityFresh = ledActivityActive && now - lastLedActivityMs < LED_ACTIVITY_MS;
  if (!activityFresh) ledActivityActive = false;
  if (hasConfirmedServerRoute()) {
    ledBlinkState = true;
  } else {
    const unsigned long interval = activityFresh ? LED_FAST_TOGGLE_MS : LED_SLOW_TOGGLE_MS;
    if (now - lastLedToggleMs >= interval) {
      lastLedToggleMs = now;
      ledBlinkState = !ledBlinkState;
    }
  }
  digitalWrite(YELLOW_STATUS_LED_PIN, ledBlinkState ? HIGH : LOW);

  // Independent red timer follows only the established local pending state.
  // Server submission alone does not clear it; the matching responder ACK does.
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

String utcTimestamp() {
  time_t now = time(nullptr);
  if (now < 1700000000) return "UPTIME-" + String(millis());
  struct tm utcTime;
  gmtime_r(&now, &utcTime);
  char timestamp[25];
  strftime(timestamp, sizeof(timestamp), "%Y-%m-%dT%H:%M:%SZ", &utcTime);
  return String(timestamp);
}

void markLoRaActivity() {
  // Defer the animation until loop resumes after any blocking API request.
  ledActivityPending = true;
}

bool sendLoRaPacket(const String& payload) {
  const uint8_t copies=payload.startsWith("ACK|")&&getField(payload,4)!="RECEIVED"?3:1;
  const bool queued=meshSecurityReady&&meshCompact(payload).length()+34<=255&&meshRadio.enqueue(payload,millis(),copies);
  if(!queued)Serial.println("[LORA] Radio queue full or authenticated packet too large; pending server ACK/SOS remains retryable.");
  return queued;
}

void onRadioTransmitted(const String& packet) {
  markLoRaActivity();
  if(packet.startsWith("ACK|")&&getField(packet,4)!="RECEIVED"&&getField(packet,3)==NODE_ID){
    const String id=getField(packet,1);
    const String identity=radioAckQueuedToken.length()?radioAckQueuedToken:packet;
    if(packet==radioAckQueuedPacket&&identity!=lastRadioAckNotified&&(!radioAckToConfirm.length()||radioAckToConfirm==id)){
      radioAckToConfirm=id;radioAckConfirmToken=radioAckQueuedToken;lastRadioAckNotified=identity;
    }
  }
}

void confirmTransmittedAcknowledgmentIfNeeded() {
  if(!radioAckToConfirm.length())return;
  String ignored;
  const String body=radioAckConfirmToken.length()?"{\"ack_token\":\""+radioAckConfirmToken+"\"}":"{}";
  if(requestApi("POST","/acks/"+urlEncode(radioAckToConfirm)+"/delivered",body,ignored)){
    radioAckToConfirm="";radioAckConfirmToken="";lastAckPollMs=millis()-ACK_POLL_INTERVAL_MS;
  }
}

void checkServerIfNeeded() {
  if (millis() - lastServerCheckMs < SERVER_CHECK_INTERVAL_MS) return;
  lastServerCheckMs = millis();
  String response;
  requestApi("GET", "/health", "", response);
}

String gatewayHeartbeatBody() {
  String body = "{";
  body += "\"node_id\":\"NODE_1\",";
  body += "\"gateway_id\":\"NODE_1\",";
  body += "\"route\":\"NODE_1\",";
  body += "\"peers\":" + peerIdsJson() + ",";
  body += "\"battery\":\"" + measuredBatteryVoltage() + "\",";
  if (websiteRoundTripMs >= 0 && millis() - websiteRoundTripAtMs <= 120000) {
    body += "\"round_trip_ms\":" + String(websiteRoundTripMs) + ",";
    body += "\"round_trip_sample\":\"" + websiteRoundTripSample + "\",";
    body += "\"round_trip_age_ms\":" + String(millis() - websiteRoundTripAtMs) + ",";
  }
  body += "\"hop\":0,\"ttl\":0,\"packet_type\":\"HB\"";
  body += ",\"blocked_submissions\":" + String(reportBlocked) + ",\"radio_rejections\":" + String(meshInvalidFrames) + ",\"guard_boot\":\"" + meshField(reportGuardHeartbeat(), 3) + "\",\"radio_auth\":\"hmac-sha256-v2\"";
  body += "}";
  return body;
}

void sendGatewayHeartbeatIfNeeded() {
  if (!serverRouteAvailable() || millis() - lastHeartbeatMs < HEARTBEAT_INTERVAL_MS) return;
  lastHeartbeatMs = millis();
  String response;
  if (!batchHeartbeatsSupported) {
    requestApi("POST", "/heartbeat", gatewayHeartbeatBody(), response);
    return;
  }
  String snapshots[11];
  String body = "{\"nodes\":[" + gatewayHeartbeatBody();
  for (int node = 2; node <= 10; ++node) {
    GatewayRadioItem& item = heartbeatInbox[node];
    if (!item.packet.length()) continue;
    const unsigned long age = millis() - item.receivedAtMs;
    if (age > 25000) { item.packet = ""; continue; }
    snapshots[node] = item.packet;
    String row = heartbeatBodyForPacket(item.packet, item.rssi, item.snr);
    if (!row.length()) continue;
    row.remove(row.length() - 1);
    body += "," + row + ",\"received_age_ms\":" + String(age) + "}";
  }
  body += "]}";
  if (requestApi("POST", "/heartbeats", body, response)) {
    for (int node = 2; node <= 10; ++node) {
      if (snapshots[node].length() && heartbeatInbox[node].packet == snapshots[node]) heartbeatInbox[node].packet = "";
    }
  } else if (lastApiStatusCode == 404 || lastApiStatusCode == 405) {
    // Preserve operation during rollout; deploy the server endpoint first for ten-node throughput.
    batchHeartbeatsSupported = false;
    Serial.println("[API] Batch heartbeat endpoint unavailable; using legacy heartbeat API.");
  }
}

void sendGatewayBeaconIfNeeded() {
  if (!serverRouteAvailable() || millis() - lastBeaconMs < BEACON_INTERVAL_MS) return;
  lastBeaconMs = millis();
  gatewaySequence++;
  sendLoRaPacket("GW|NODE_1|" + gatewayBeaconBoot + ":" + String(gatewaySequence) + "|0|12|NODE_1");
}

void pollNetworkSummaryIfNeeded() {
  if (!serverRouteAvailable() || millis() - lastNetworkPollMs < NETWORK_POLL_INTERVAL_MS) return;
  lastNetworkPollMs = millis();

  String response;
  if (!requestApi("GET", "/network-summary", "", response)) return;
  String packet = extractJsonString(response, "packet");
  activeNodeCount = extractJsonInt(response, "activeCount", -1);
  if (packet.startsWith("NET|")) sendLoRaPacket(packet);
}

void pollPendingAcknowledgmentIfNeeded() {
  if(radioAckToConfirm.length()||meshRadio.finalAckPending())return;
  if (!serverRouteAvailable() || millis() - lastAckPollMs < ACK_POLL_INTERVAL_MS) return;
  lastAckPollMs = millis();

  String response;
  if (!requestApi("GET", "/acks/pending?gatewayId=NODE_1", "", response)) return;
  String packet = extractJsonString(response, "packet");
  String msgId = extractJsonString(response, "msgId");
  const String ackToken=extractJsonString(response,"ackToken");
  if (!packet.startsWith("ACK|") || msgId.length() == 0) return;

  const String origin = getField(packet, 2);
  if (origin == NODE_ID) {
    if (msgId == latestMsgId || msgId == pendingLocalMsgId) {
      latestReportStatus = getField(packet, 4);
      latestAckAt = getField(packet, 5);
      latestAckMessage = getField(packet, 8);
      if (latestAckMessage.length() == 0) latestAckMessage = "Your emergency report has been acknowledged.";
      if (getField(packet, 9).length()) latestAckMessage = getField(packet, 9) + ": " + latestAckMessage;
      pendingLocalPacket = "";
      pendingLocalMsgId = "";
      prefs.putString("latest_status", latestReportStatus);
      prefs.putString("latest_ack_at", latestAckAt);
      prefs.putString("latest_ack", latestAckMessage);
      prefs.remove("pending_packet");
      prefs.remove("pending_msg");
    }
    String ignored;
    const String body=ackToken.length()?"{\"ack_token\":\""+ackToken+"\"}":"{}";
    requestApi("POST", "/acks/" + urlEncode(msgId) + "/delivered", body, ignored);
    return;
  }

  packet = meshSignOrigin(packet, true, meshNodeNumber(origin));
  if (!packet.length()) return;
  if(sendLoRaPacket(packet)){radioAckQueuedPacket=packet;radioAckQueuedToken=ackToken;}
  // Three spaced copies; confirm the API only after actual TX_DONE.
}

String normalizedLocalType(String type) {
  type.trim();
  if (type == "R" || type == "ROAD_ACCIDENT") return "Road Accident";
  if (type == "MEDICAL_EMERGENCY") return "Medical Emergency";
  if (type == "FIRE") return "Fire";
  if (type == "FLOOD") return "Flood";
  if (type == "SECURITY") return "Security";
  return "Other";
}

bool submitLocalReport() {
  if (pendingLocalPacket.length() == 0) return false;

  String body = "{";
  body += "\"sender_id\":\"NODE_1\",";
  body += "\"sequence\":\"" + jsonEscape(pendingLocalMsgId) + "\",";
  body += "\"gateway_id\":\"NODE_1\",";
  body += "\"created_at\":\"" + jsonEscape(latestCreatedAt) + "\",";
  body += "\"incident_type\":\"" + jsonEscape(normalizedLocalType(getField(pendingLocalPacket, 4))) + "\",";
  body += "\"description\":\"" + jsonEscape(getField(pendingLocalPacket, 5)) + "\",";
  body += "\"incident_landmark\":\"" + jsonEscape(getField(pendingLocalPacket, 10)) + "\",";
  body += "\"radio_auth\":\"hmac-sha256-v2\",";
  body += "\"battery\":\"" + jsonEscape(getField(pendingLocalPacket, 6)) + "\",\"hop\":0,\"ttl\":12,";
  body += "\"route\":\"NODE_1\",\"rssi\":0,\"snr\":0";
  body += "}";

  String response;
  const bool accepted = requestApi("POST", "/alerts", body, response);
  if (accepted) {
    latestReportStatus = "RECEIVED BY SERVER - WAITING FOR ACK";
    lastForwardedMessage = pendingLocalMsgId + " from NODE_1";
  } else if (latestReportStatus != "RECEIVED BY SERVER - WAITING FOR ACK") {
    latestReportStatus = "QUEUED - SERVER UNAVAILABLE";
  }
  prefs.putString("latest_status", latestReportStatus);
  return accepted;
}

void retryLocalReportIfNeeded(bool forceNow = false) {
  if (pendingLocalPacket.length() == 0) return;
  const unsigned long retryInterval = latestReportStatus == "RECEIVED BY SERVER - WAITING FOR ACK"
    ? 60000 : LOCAL_REPORT_RETRY_INTERVAL_MS;
  if (!forceNow && millis() - lastLocalReportRetryMs < retryInterval) return;
  lastLocalReportRetryMs = millis();
  submitLocalReport();
}

void handleLocalEmergency() {
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
  if (pendingLocalPacket.length() > 0) {
    reportRecordBlocked(); localServer.send(409, "text/plain", "This node already has a report waiting for admin acknowledgment. Check Latest Report."); return;
  }
  if (!reportConsumeConfirmation(clientAddress, reportConfirmationBody(message, landmark, type, createdAt), localServer.arg("confirmation"))) {
    localServer.send(400, "text/plain", "Confirmation expired, already used, or report details changed. Review the report again."); return;
  }
  if (prefs.getUInt("seq", 0) == UINT32_MAX || !reportGuardAccept(clientAddress, millis())) {
    reportRecordBlocked(); localServer.sendHeader("Retry-After", "60");
    localServer.send(429, "text/plain", "Submission protection active. Wait 60 seconds or contact the emergency team."); return;
  }
  const uint32_t sequence = prefs.getUInt("seq", 0) + 1;
  if (prefs.putUInt("seq", sequence) != 4) {
    localServer.send(503, "text/plain", "Could not save the report ID. Report was not submitted."); return;
  }
  latestMsgId = String(NODE_ID) + "-" + String(sequence);
  pendingLocalMsgId = latestMsgId;
  latestCreatedAt = createdAt;
  latestAckMessage = "";
  latestAckAt = "";
  latestReportStatus = "QUEUED";
  pendingLocalPacket = "SOS|" + latestMsgId + "|NODE_1|" + createdAt + "|R|" + message + "|" + measuredBatteryVoltage() + "|0|12|NODE_1|" + landmark;
  pendingLocalPacket = meshSignOrigin(pendingLocalPacket, false, 1);
  prefs.putString("latest_msg", latestMsgId);
  prefs.putString("latest_created", latestCreatedAt);
  prefs.putString("latest_status", latestReportStatus);
  prefs.remove("latest_ack");
  prefs.remove("latest_ack_at");
  prefs.putString("pending_packet", pendingLocalPacket);
  prefs.putString("pending_msg", pendingLocalMsgId);

  if (submitLocalReport()) {
    localServer.send(200, "text/plain", "Emergency report reached the Laravel server. Waiting for responder acknowledgment.");
  } else {
    localServer.send(200, "text/plain", "Server unavailable. NODE 1 saved the report and will retry automatically.");
  }
}

void handleLocalStatus() {
  const bool online = serverRouteAvailable();
  String json = "{";
  json += AksyResRadio::statusFields();
  json += meshRadio.statusFields();
  json += reportGuardStatus((uint32_t)localServer.client().remoteIP());
  json += "\"node_id\":\"NODE_1\",";
  json += "\"server_base_url\":\"" + jsonEscape(SERVER_BASE_URL) + "\",";
  json += "\"gateway_status\":\"" + jsonEscape(gatewayStatus) + "\",";
  json += "\"wifi_connected\":" + String(WiFi.status() == WL_CONNECTED ? "true" : "false") + ",";
  json += "\"wifi_ip\":\"" + WiFi.localIP().toString() + "\",";
  json += "\"battery\":\"" + measuredBatteryVoltage() + "\",";
  json += "\"round_trip_ms\":" + (websiteRoundTripMs < 0 ? String("null") : String(websiteRoundTripMs)) + ",";
  json += "\"server_online\":" + String(online ? "true" : "false") + ",";
  json += "\"peer_list\":\"" + jsonEscape(peerList()) + "\",";
  json += "\"active_nodes\":" + String(activeNodeCount) + ",";
  json += "\"pending\":" + String(pendingLocalPacket.length() > 0 ? "true" : "false") + ",";
  json += "\"message_id\":\"" + jsonEscape(latestMsgId) + "\",";
  json += "\"created_at\":\"" + jsonEscape(latestCreatedAt) + "\",";
  json += "\"report_status\":\"" + jsonEscape(latestReportStatus) + "\",";
  json += "\"ack_message\":\"" + jsonEscape(latestAckMessage) + "\",";
  json += "\"ack_at\":\"" + jsonEscape(latestAckAt) + "\"}";
  localServer.sendHeader("Cache-Control", "no-store");
  localServer.send(200, "application/json", json);
}

void redirectToDashboard() {
  localServer.sendHeader("Location", "http://" + WiFi.softAPIP().toString() + "/", true);
  localServer.sendHeader("Cache-Control", "no-cache, no-store, must-revalidate");
  localServer.sendHeader("Pragma", "no-cache");
  localServer.sendHeader("Expires", "-1");
  localServer.send(302, "text/plain", "");
}

void startCaptivePortal() {
  const IPAddress apIP = WiFi.softAPIP();
  dnsServer.start(DNS_PORT, "*", apIP);
  localServer.on("/", HTTP_GET, []() { String page = FPSTR(LOCAL_DASHBOARD); page.replace("</body>", String(FPSTR(REPORT_GUARD_SCRIPT)) + "</body>"); localServer.sendHeader("Cache-Control", "no-store"); localServer.send(200, "text/html", page); });
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
  localServer.on("/send", HTTP_POST, handleLocalEmergency);
  localServer.on("/generate_204", HTTP_ANY, redirectToDashboard);
  localServer.on("/gen_204", HTTP_ANY, redirectToDashboard);
  localServer.on("/redirect", HTTP_ANY, redirectToDashboard);
  localServer.on("/hotspot-detect.html", HTTP_ANY, redirectToDashboard);
  localServer.on("/library/test/success.html", HTTP_ANY, redirectToDashboard);
  localServer.on("/connecttest.txt", HTTP_ANY, redirectToDashboard);
  localServer.on("/ncsi.txt", HTTP_ANY, redirectToDashboard);
  localServer.on("/canonical.html", HTTP_ANY, redirectToDashboard);
  localServer.on("/success.txt", HTTP_ANY, redirectToDashboard);
  localServer.onNotFound(redirectToDashboard);
  localServer.begin();
}

String normalizedType(String type) {
  type.trim();
  if (type == "R" || type == "ROAD_ACCIDENT") return "Road Accident";
  if (type == "MEDICAL_EMERGENCY") return "Medical Emergency";
  if (type == "FIRE") return "Fire";
  if (type == "FLOOD") return "Flood";
  if (type == "SECURITY") return "Security";
  if (type.length() == 0) return "LoRa Emergency Alert";
  return type;
}

bool forwardSosToLaravel(const String& packet, int rssi, float snr) {
  String msgId = getField(packet, 1);
  String senderId = getField(packet, 2);
  String createdAt = getField(packet, 3);
  String type = getField(packet, 4);
  String message = getField(packet, 5);
  String battery = getField(packet, 6);
  String hop = getField(packet, 7);
  String ttl = getField(packet, 8);
  String route = appendGatewayToRoute(getField(packet, 9));

  if (!meshAcceptSos(packet, false)) { ++meshInvalidFrames; return false; }
  if (msgId.length() == 0 || senderId.length() == 0 || message.length() == 0) {
    Serial.println("[SOS] Rejected malformed SOS packet.");
    return false;
  }

  String body = "{";
  body += "\"sender_id\":\"" + jsonEscape(senderId) + "\",";
  body += "\"sequence\":\"" + jsonEscape(msgId) + "\",";
  body += "\"gateway_id\":\"NODE_1\",";
  body += "\"created_at\":\"" + jsonEscape(createdAt) + "\",";
  body += "\"incident_type\":\"" + jsonEscape(normalizedType(type)) + "\",";
  body += "\"description\":\"" + jsonEscape(message) + "\",";
  body += "\"incident_landmark\":\"" + jsonEscape(getField(packet, 10)) + "\",";
  body += "\"radio_auth\":\"hmac-sha256-v2\",";
  body += "\"battery\":\"" + jsonEscape(battery.length() ? battery : "NA") + "\",";
  body += "\"hop\":" + String(hop.toInt()) + ",";
  body += "\"ttl\":" + String(ttl.toInt()) + ",";
  body += "\"route\":\"" + jsonEscape(route) + "\",";
  body += "\"rssi\":" + String(rssi);
  body += ",\"snr\":" + String(snr, 2);
  body += "}";

  String response;
  bool accepted = requestApi("POST", "/alerts", body, response);
  if (accepted) {
    if (!meshAcceptSos(packet, true)) return false;
    lastForwardedMessage = msgId + " from " + senderId;
    Serial.println("[SOS] Laravel accepted " + lastForwardedMessage);
    const String receipt = "ACK|" + msgId + "|" + senderId + "|NODE_1|RECEIVED|" +
      utcTimestamp() + "|12|NODE_1|Report received by server.|SERVER";
    sendLoRaPacket(meshSignOrigin(receipt, true, meshNodeNumber(senderId)));
  }
  return accepted;
}

String peersAsJson(const String& peerCsv) {
  String result = "[";
  int start = 0;
  bool first = true;
  while (start < peerCsv.length()) {
    int comma = peerCsv.indexOf(',', start);
    if (comma < 0) comma = peerCsv.length();
    String peer = peerCsv.substring(start, comma);
    peer.trim();
    if (peer.length() > 0) {
      if (!first) result += ",";
      result += "\"" + jsonEscape(peer) + "\"";
      first = false;
    }
    start = comma + 1;
  }
  result += "]";
  return result;
}

int registeredMeshNodeNumber(const String& nodeId) {
  if (!nodeId.startsWith("NODE_")) return -1;
  const int number = nodeId.substring(5).toInt();
  return number >= 1 && number <= 10 && nodeId == "NODE_" + String(number) ? number : -1;
}

bool unsignedDecimal(const String& value) {
  if (value.length() == 0) return false;
  for (unsigned int i = 0; i < value.length(); i++) {
    if (value[i] < '0' || value[i] > '9') return false;
  }
  return true;
}

void forwardTimingProbe(const String& packet) {
  const String nodeId = getField(packet, 1);
  const String probeId = getField(packet, 2);
  const int number = registeredMeshNodeNumber(nodeId);
  if (number < 2 || probeId.length() == 0 || probeId.length() > 24 ||
      getField(packet, 3).toInt() <= 0 || !serverRouteAvailable()) return;
  if (lastProbeIds[number] == probeId) return;
  lastProbeIds[number] = probeId;

  const String body = "{\"node_id\":\"" + jsonEscape(nodeId) +
    "\",\"probe_id\":\"" + jsonEscape(probeId) + "\"}";
  String response;
  if (!requestApi("POST", "/probe", body, response)) return;
  const String pong = extractJsonString(response, "packet");
  if (pong.startsWith("PONG|" + nodeId + "|" + probeId + "|")) sendLoRaPacket(pong);
}

String heartbeatBodyForPacket(const String& packet, int rssi, float snr) {
  String nodeId = getField(packet, 1);
  String battery = getField(packet, 2);
  String hop = getField(packet, 3);
  String ttl = getField(packet, 4);
  String route = appendGatewayToRoute(getField(packet, 5));
  String peers = getField(packet, 6);
  String sequence = getField(packet, 7);
  String roundTrip = getField(packet, 8);
  String sample = getField(packet, 9);
  String sampleAge = getField(packet, 10);

  const int number = registeredMeshNodeNumber(nodeId);
  if (number < 2) return "";
  String body = "{";
  body += "\"node_id\":\"" + jsonEscape(nodeId) + "\",";
  body += "\"gateway_id\":\"NODE_1\",";
  body += "\"route\":\"" + jsonEscape(route) + "\",";
  body += "\"peers\":" + peersAsJson(peers) + ",";
  body += "\"battery\":\"" + jsonEscape(battery.length() ? battery : "NA") + "\",";
  body += "\"hop\":" + String(hop.toInt()) + ",";
  body += "\"ttl\":" + String(ttl.toInt()) + ",";
  body += "\"rssi\":" + String(rssi) + ",";
  body += "\"snr\":" + String(snr, 2) + ",";
  if (unsignedDecimal(roundTrip) && roundTrip.toInt() <= 60000 && sample.length() &&
      unsignedDecimal(sampleAge) && sampleAge.toInt() <= 120000) {
    body += "\"round_trip_ms\":" + roundTrip + ",";
    body += "\"round_trip_sample\":\"" + jsonEscape(sample) + "\",";
    body += "\"round_trip_age_ms\":" + sampleAge + ",";
  }
  body += "\"blocked_submissions\":" + String(getField(packet, 11).toInt()) + ",";
  body += "\"radio_rejections\":" + String(getField(packet, 12).toInt()) + ",";
  body += "\"guard_boot\":\"" + jsonEscape(getField(packet, 13)) + "\",";
  body += "\"radio_auth\":\"hmac-sha256-v2\",";
  body += "\"packet_type\":\"HB\"";
  body += "}";

  return body;
}

bool forwardHeartbeatToLaravel(const String& packet, int rssi, float snr) {
  const int number = registeredMeshNodeNumber(getField(packet, 1));
  if (number < 2) return false;
  const String sequence = getField(packet, 7);
  if (sequence.length() && lastHeartbeatSequences[number] == sequence &&
      millis() - lastHeartbeatForwardedAtMs[number] < 20000) return true;
  String response;
  const bool accepted = requestApi("POST", "/heartbeat", heartbeatBodyForPacket(packet, rssi, snr), response);
  if (accepted && sequence.length()) {
    lastHeartbeatSequences[number] = sequence;
    lastHeartbeatForwardedAtMs[number] = millis();
  }
  return accepted;
}

void queueGatewayPacket(const String& packet, int rssi, float snr) {
  const bool sos = packet.startsWith("SOS|");
  const int node = meshNodeNumber(getField(packet, sos ? 2 : 1));
  if (node < 2) return;
  GatewayRadioItem* item = nullptr;
  if (sos) {
    if (!meshAcceptSos(packet, false)) { ++meshInvalidFrames; return; }
    item = &sosInbox[node];
    if (item->packet.length()) {
      // Each origin keeps one pending report. Do not overwrite an undelivered one.
      return;
    }
    const String key = "inbox" + String(node);
    if (!prefs.putString(key.c_str(), packet)) {
      Serial.println("[QUEUE] Could not persist SOS; origin will retry.");
      return;
    }
    Serial.println("[QUEUE] Saved SOS " + getField(packet, 1));
  } else if (packet.startsWith("HB|")) item = &heartbeatInbox[node];
  else if (packet.startsWith("PING|")) item = &probeInbox[node];
  if (!item) return;
  // Heartbeats/probes coalesce to the newest packet per origin during HTTPS.
  item->packet = packet; item->rssi = rssi; item->snr = snr; item->receivedAtMs = millis();
}

void restoreGatewayInbox() {
  for (int node = 2; node <= 10; ++node) {
    const String key = "inbox" + String(node);
    const String packet = prefs.getString(key.c_str(), "");
    if (packet.length() && meshAcceptSos(packet, false)) sosInbox[node].packet = packet;
  }
}

bool forwardQueuedSosIfNeeded() {
  if (!serverRouteAvailable()) return false;
  for (int scanned = 0; scanned < 9; ++scanned) {
    const int node = nextSosNode;
    nextSosNode = node == 10 ? 2 : node + 1;
    GatewayRadioItem& item = sosInbox[node];
    if (!item.packet.length() || (item.attempted && millis() - item.attemptedAtMs < 5000)) continue;
    item.attempted = true; item.attemptedAtMs = millis();
    const String packet = item.packet;
    if (forwardSosToLaravel(packet, item.rssi, item.snr)) {
      item.packet = ""; item.attempted = false;
      const String key = "inbox" + String(node); prefs.remove(key.c_str());
    }
    return true;
  }
  return false;
}

void forwardQueuedHeartbeatIfNeeded() {
  if (!serverRouteAvailable()) return;
  for (int scanned = 0; scanned < 9; ++scanned) {
    const int node = nextHeartbeatNode;
    nextHeartbeatNode = node == 10 ? 2 : node + 1;
    GatewayRadioItem& item = heartbeatInbox[node];
    if (!item.packet.length() || (item.attempted && millis() - item.attemptedAtMs < 10000)) continue;
    item.attempted = true; item.attemptedAtMs = millis();
    const String packet = item.packet;
    if (forwardHeartbeatToLaravel(packet, item.rssi, item.snr) && item.packet == packet) {
      item.packet = "";
    }
    return;
  }
}

void forwardQueuedProbeIfNeeded() {
  if (!serverRouteAvailable()) return;
  for (int scanned = 0; scanned < 9; ++scanned) {
    const int node = nextProbeNode;
    nextProbeNode = node == 10 ? 2 : node + 1;
    if (!probeInbox[node].packet.length()) continue;
    const String packet = probeInbox[node].packet; probeInbox[node].packet = "";
    forwardTimingProbe(packet);
    return;
  }
}

void serviceGatewayRadio() {
  dnsServer.processNextRequest();
  meshRadio.tick(millis());
  handleIncomingLoRa();
  sendGatewayBeaconIfNeeded();
  meshRadio.tick(millis());
  updateLedIndicators();
}

void handleIncomingLoRa() {
  if(meshRadio.busy())return;
  const int packetSize = LoRa.parsePacket();
  if (!packetSize) return;
  markLoRaActivity();

  String packet = meshReadFrame(packetSize);
  const int rssi = LoRa.packetRssi();
  const float snr = LoRa.packetSnr();
  const long frequencyError = LoRa.packetFrequencyError();
  LoRa.receive();
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
  Serial.println("[LORA RX " + String(rssi) + " dBm, SNR " + String(snr, 2) + " dB] " + packet);

  if (packet.startsWith("HELLO|")) {
    String peerId = getField(packet, 1);
    if (peerId.length() > 0 && peerId != NODE_ID) {
      updatePeer(peerId, rssi);
      sendLoRaPacket("HELLO_ACK|NODE_1|" + peerId);
    }
  } else if (packet.startsWith("HELLO_ACK|")) {
    String peerId = getField(packet, 1);
    if (getField(packet, 2) == NODE_ID) updatePeer(peerId, rssi);
  } else if (packet.startsWith("SOS|")) {
    queueGatewayPacket(packet, rssi, snr);
  } else if (packet.startsWith("HB|")) {
    queueGatewayPacket(packet, rssi, snr);
  } else if (packet.startsWith("PING|")) {
    queueGatewayPacket(packet, rssi, snr);
  }
}

void connectRouter() {
  if (!isConfigured()) {
    gatewayStatus = "Configure gateway Wi-Fi, server URL, and token";
    return;
  }
  const unsigned long now = millis();
  if (WiFi.status() == WL_CONNECTED) {
    if (!routerWasConnected) {
      Serial.println("Router connected: " + WiFi.localIP().toString());
      configTime(0, 0, "pool.ntp.org", "time.nist.gov", "time.cloudflare.com");
      gatewayStatus = "Router connected; checking Laravel";
      lastServerCheckMs = now - SERVER_CHECK_INTERVAL_MS;
    }
    routerWasConnected = true; routerPhase = 0;
    return;
  }
  if (routerWasConnected) { routerWasConnected = false; lastServerSuccessMs = 0; }
  if (routerPhase == 1) {
    if (now - routerPhaseAtMs < 250) return;
    WiFi.begin(ROUTER_WIFI_SSID, ROUTER_WIFI_PASSWORD);
    routerPhase = 2; routerPhaseAtMs = now;
    return;
  }
  if (routerPhase == 2) {
    if (now - routerPhaseAtMs < 20000) return;
    routerPhase = 0; lastWifiReconnectMs = now;
    gatewayStatus = "Router unavailable; field nodes will queue reports";
    Serial.println(gatewayStatus);
    Serial.println("Wi-Fi status code: " + String((int) WiFi.status()));
    return;
  }
  if (routerEverAttempted && now - lastWifiReconnectMs < 10000) return;
  routerEverAttempted = true;
  WiFi.setAutoReconnect(false);
  WiFi.disconnect(false, false);
  routerPhase = 1; routerPhaseAtMs = now;
  gatewayStatus = "Connecting router; LoRa receiver remains active";
}

void setup() {
  initializeLedIndicators();
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());
  Serial.println("Battery sensing: " + String(BATTERY_MONITOR_ENABLED ? "enabled" : "disabled (no voltage reading)"));
  if (BATTERY_MONITOR_ENABLED) {
    Serial.println("Battery ADC: GPIO" + String(BATTERY_ADC_PIN) + " (connect voltage divider for valid readings)");
  }

  prefs.begin("node1", false);
  if (MESH_THIS_NODE != 1 || !meshSecurityBegin()) {
    Serial.println("ERROR: NODE_1 mesh security configuration failed.");
    while (true) delay(1000);
  }
  restoreGatewayInbox();
  latestMsgId = prefs.getString("latest_msg", "");
  latestCreatedAt = prefs.getString("latest_created", "");
  latestReportStatus = prefs.getString("latest_status", "NONE");
  latestAckMessage = prefs.getString("latest_ack", "");
  latestAckAt = prefs.getString("latest_ack_at", "");
  pendingLocalPacket = prefs.getString("pending_packet", "");
  pendingLocalMsgId = prefs.getString("pending_msg", "");
  updateLedIndicators();  // Restore the pending indicator after a reboot.

  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(LOCAL_AP_SSID, LOCAL_AP_PASSWORD);
  Serial.println("NODE_1 local dashboard: http://" + WiFi.softAPIP().toString());
  startCaptivePortal();
  connectRouter();

  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_SS);
  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(LORA_FREQUENCY)) {
    Serial.println("LoRa init failed. Check wiring and radio settings.");
    while (true) {
      updateLedIndicators();
      delay(2);
    }
  }

  AksyResRadio::configure(LoRa);
  meshRadioPort.begin(LORA_SS);
  meshRadio.begin(esp_random());
  gatewayBeaconBoot=String(esp_random(),HEX);
  LoRa.receive();
  Serial.println("[RADIO] " + AksyResRadio::summary());
  apiWorkerReady = startGatewayApiWorker();
  if (!apiWorkerReady) Serial.println("ERROR: Could not start HTTP worker; SOS remains queued.");
  Serial.println("NODE_1 Laravel LoRa gateway ready.");
}

void loop() {
  dnsServer.processNextRequest();
  localServer.handleClient();

  connectRouter();
  serviceGatewayRadio();
  if (millis() - lastApiFinishedMs >= 150) {
    const uint32_t before = apiRequestSequence;
    // One HTTP operation per scheduler pass; radio is serviced during its wait.
    checkServerIfNeeded();
    if (apiRequestSequence == before && millis() - lastHeartbeatMs >= 15000) sendGatewayHeartbeatIfNeeded();
    if (apiRequestSequence == before) confirmTransmittedAcknowledgmentIfNeeded();
    if (apiRequestSequence == before) forwardQueuedSosIfNeeded();
    if (apiRequestSequence == before) retryLocalReportIfNeeded();
    if (apiRequestSequence == before) sendGatewayHeartbeatIfNeeded();
    if (apiRequestSequence == before) pollPendingAcknowledgmentIfNeeded();
    if (apiRequestSequence == before) pollNetworkSummaryIfNeeded();
    if (apiRequestSequence == before && !batchHeartbeatsSupported) forwardQueuedHeartbeatIfNeeded();
    if (apiRequestSequence == before) forwardQueuedProbeIfNeeded();
  }
  delay(2);
}
