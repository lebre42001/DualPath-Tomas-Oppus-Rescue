/*
  NODE_1 LoRa mesh gateway for the official Laravel API.

  Field-node traffic stays on LoRa until this gateway has a working route to
  the configured Laravel server. SOS packets remain pending at their origin
  until a responder acknowledgment returns through this gateway.

  Copy arduino_secrets.example.h to arduino_secrets.h, then configure Wi-Fi,
  server URL, gateway token, and (for HTTPS) the server root CA certificate.
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
#include <time.h>
#include <esp_system.h>
#include "server_root_ca.h"

#if __has_include("arduino_secrets.h")
#include "arduino_secrets.h"
#else
#error "Copy arduino_secrets.example.h to arduino_secrets.h and configure NODE_1 before compiling."
#endif

#define NODE_ID "NODE_1"

const char* LOCAL_AP_SSID = "NODE_1_Gateway";

const long LORA_FREQUENCY = 433000000;
const byte LORA_SYNC_WORD = 0x34;
const int LORA_SS = 5;
const int LORA_SCK = 18;
const int LORA_MISO = 19;
const int LORA_MOSI = 23;
const int LORA_RST = 27;
const int LORA_DIO0 = 26;

const unsigned long SERVER_CHECK_INTERVAL_MS = 10000;
const unsigned long HEARTBEAT_INTERVAL_MS = 10000;
const unsigned long BEACON_INTERVAL_MS = 5000;
const unsigned long NETWORK_POLL_INTERVAL_MS = 10000;
const unsigned long ACK_POLL_INTERVAL_MS = 2000;
const unsigned long SERVER_ROUTE_TIMEOUT_MS = 30000;
const unsigned long LOCAL_REPORT_RETRY_INTERVAL_MS = 15000;

WiFiClient plainClient;
WiFiClientSecure secureClient;
WebServer localServer(80);
DNSServer dnsServer;
Preferences prefs;
const byte DNS_PORT = 53;

unsigned long lastServerCheckMs = 0;
unsigned long lastServerSuccessMs = 0;
unsigned long lastHeartbeatMs = 0;
unsigned long lastBeaconMs = 0;
unsigned long lastNetworkPollMs = 0;
unsigned long lastAckPollMs = 0;
unsigned long lastWifiReconnectMs = 0;
unsigned long lastLocalReportRetryMs = 0;
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

struct PeerInfo {
  String nodeId;
  int rssi;
  unsigned long lastSeenMs;
};

const int MAX_PEERS = 10;
const unsigned long PEER_TIMEOUT_MS = 18000;
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
async function sendReport(){const message=$('message').value.trim();if(!message){alert('Please enter a short emergency message.');return}const p=new URLSearchParams();p.set('type',$('type').value);p.set('message',message);p.set('created_at',localTime());$('send').disabled=true;try{const r=await fetch('/send',{method:'POST',body:p}),t=await r.text();alert(t);$('message').value='';$('remaining').textContent='70';await refresh()}catch(e){alert('Could not reach NODE 1. Please reconnect to its Wi-Fi and try again.')}finally{$('send').disabled=false}}
setInterval(refresh,1500);refresh();
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
    // TLS validates certificate dates against the ESP32 clock. Keep serving
    // the local portal and retry later while NTP synchronizes after boot.
    if (time(nullptr) < 1700000000) {
      gatewayStatus = "Waiting for internet time before HTTPS";
      Serial.println("[HTTPS] Waiting for NTP time; check the hotspot's internet connection.");
      return false;
    }
    secureClient.setCACert(SERVER_ROOT_CA_CERT);
    return http.begin(secureClient, url);
  }

  return http.begin(plainClient, url);
}

String apiUrl(const String& path) {
  String base = SERVER_BASE_URL;
  while (base.endsWith("/")) base.remove(base.length() - 1);
  return base + "/api/v1/lora" + path;
}

bool requestApi(const String& method, const String& path, const String& body, String& responseBody) {
  if (!isConfigured() || WiFi.status() != WL_CONNECTED) return false;

  HTTPClient http;
  http.setConnectTimeout(7000);
  http.setTimeout(7000);
  if (!beginHttp(http, apiUrl(path))) return false;
  http.addHeader("Accept", "application/json");
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-STITCH-LORA-TOKEN", LORA_GATEWAY_TOKEN);

  int statusCode = method == "POST" ? http.POST(body) : http.GET();
  responseBody = http.getString();
  if (statusCode < 0) {
    Serial.println("[API connection] " + HTTPClient::errorToString(statusCode));
  } else if (statusCode == 401 || statusCode == 403) {
    Serial.println("[API auth] Match LORA_GATEWAY_TOKEN with LORA_INGEST_TOKEN on the hosted website.");
  }
  http.end();

  Serial.println("[API " + method + "] " + path + " -> " + String(statusCode));
  if (statusCode >= 200 && statusCode < 300) {
    lastServerSuccessMs = millis();
    gatewayStatus = "Laravel server connected";
    return true;
  }
  gatewayStatus = "Laravel server unavailable (HTTP " + String(statusCode) + ")";
  return false;
}

bool serverRouteAvailable() {
  return lastServerSuccessMs > 0 && millis() - lastServerSuccessMs <= SERVER_ROUTE_TIMEOUT_MS;
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

bool sendLoRaPacket(const String& payload) {
  if (payload.length() > 245) {
    Serial.println("[WARN] LoRa packet too long; preserving the complete packet by skipping transmission.");
    return false;
  }

  LoRa.idle();
  LoRa.beginPacket();
  LoRa.print(payload);
  const int result = LoRa.endPacket();
  LoRa.receive();
  Serial.println("[LORA TX] " + payload);
  return result == 1;
}

void checkServerIfNeeded() {
  if (millis() - lastServerCheckMs < SERVER_CHECK_INTERVAL_MS) return;
  lastServerCheckMs = millis();
  String response;
  requestApi("GET", "/health", "", response);
}

void sendGatewayHeartbeatIfNeeded() {
  if (!serverRouteAvailable() || millis() - lastHeartbeatMs < HEARTBEAT_INTERVAL_MS) return;
  lastHeartbeatMs = millis();

  String body = "{";
  body += "\"node_id\":\"NODE_1\",";
  body += "\"gateway_id\":\"NODE_1\",";
  body += "\"route\":\"NODE_1\",";
  body += "\"peers\":" + peerIdsJson() + ",";
  body += "\"battery\":\"NA\",";
  body += "\"hop\":0,\"ttl\":0,\"packet_type\":\"HB\"";
  body += "}";
  String response;
  requestApi("POST", "/heartbeat", body, response);
}

void sendGatewayBeaconIfNeeded() {
  if (!serverRouteAvailable() || millis() - lastBeaconMs < BEACON_INTERVAL_MS) return;
  lastBeaconMs = millis();
  gatewaySequence++;
  sendLoRaPacket("GW|NODE_1|" + String(gatewaySequence) + "|0|12|NODE_1");
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
  if (!serverRouteAvailable() || millis() - lastAckPollMs < ACK_POLL_INTERVAL_MS) return;
  lastAckPollMs = millis();

  String response;
  if (!requestApi("GET", "/acks/pending?gatewayId=NODE_1", "", response)) return;
  String packet = extractJsonString(response, "packet");
  String msgId = extractJsonString(response, "msgId");
  if (!packet.startsWith("ACK|") || msgId.length() == 0) return;

  const String origin = getField(packet, 2);
  if (origin == NODE_ID) {
    if (msgId == latestMsgId || msgId == pendingLocalMsgId) {
      latestReportStatus = getField(packet, 4);
      latestAckAt = getField(packet, 5);
      latestAckMessage = getField(packet, 8);
      if (latestAckMessage.length() == 0) latestAckMessage = "Your emergency report has been acknowledged.";
      pendingLocalPacket = "";
      pendingLocalMsgId = "";
      prefs.putString("latest_status", latestReportStatus);
      prefs.putString("latest_ack_at", latestAckAt);
      prefs.putString("latest_ack", latestAckMessage);
      prefs.remove("pending_packet");
      prefs.remove("pending_msg");
    }
    String ignored;
    requestApi("POST", "/acks/" + urlEncode(msgId) + "/delivered", "{}", ignored);
    return;
  }

  bool deliveredOverRadio = true;
  for (int attempt = 0; attempt < 3; attempt++) {
    if (!sendLoRaPacket(packet)) deliveredOverRadio = false;
    if (attempt < 2) delay(220);
  }
  if (deliveredOverRadio) {
    String ignored;
    requestApi("POST", "/acks/" + urlEncode(msgId) + "/delivered", "{}", ignored);
  }
}

String normalizedLocalType(String type) {
  type.trim();
  if (type == "ROAD_ACCIDENT") return "Road Accident";
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
  body += "\"incident_type\":\"" + jsonEscape(getField(pendingLocalPacket, 4)) + "\",";
  body += "\"description\":\"" + jsonEscape(getField(pendingLocalPacket, 5)) + "\",";
  body += "\"battery\":\"NA\",\"hop\":0,\"ttl\":12,";
  body += "\"route\":\"NODE_1\",\"rssi\":0,\"snr\":0";
  body += "}";

  String response;
  const bool accepted = requestApi("POST", "/alerts", body, response);
  if (accepted) {
    latestReportStatus = "RECEIVED BY SERVER - WAITING FOR ACK";
    lastForwardedMessage = pendingLocalMsgId + " from NODE_1";
  } else {
    latestReportStatus = "QUEUED - SERVER UNAVAILABLE";
  }
  prefs.putString("latest_status", latestReportStatus);
  return accepted;
}

void retryLocalReportIfNeeded(bool forceNow = false) {
  if (pendingLocalPacket.length() == 0) return;
  if (!forceNow && millis() - lastLocalReportRetryMs < LOCAL_REPORT_RETRY_INTERVAL_MS) return;
  lastLocalReportRetryMs = millis();
  submitLocalReport();
}

void handleLocalEmergency() {
  String message = localServer.arg("message");
  String type = localServer.arg("type");
  String createdAt = localServer.arg("created_at");
  message.trim();
  type.trim();
  createdAt.trim();
  message.replace("|", " ");

  if (message.length() == 0 || message.length() > 70 || createdAt.length() == 0) {
    localServer.send(400, "text/plain", "Message and submitted time are required; message limit is 70 characters.");
    return;
  }
  if (pendingLocalPacket.length() > 0) {
    localServer.send(409, "text/plain", "The previous report is still waiting for a responder acknowledgment.");
    return;
  }

  const uint32_t sequence = prefs.getUInt("seq", 0) + 1;
  prefs.putUInt("seq", sequence);
  latestMsgId = String(NODE_ID) + "-" + String(sequence);
  pendingLocalMsgId = latestMsgId;
  latestCreatedAt = createdAt;
  latestAckMessage = "";
  latestAckAt = "";
  latestReportStatus = "QUEUED";
  pendingLocalPacket = "SOS|" + latestMsgId + "|NODE_1|" + createdAt + "|" + normalizedLocalType(type) + "|" + message + "|NA|0|12|NODE_1";
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
  json += "\"node_id\":\"NODE_1\",";
  json += "\"server_base_url\":\"" + jsonEscape(SERVER_BASE_URL) + "\",";
  json += "\"gateway_status\":\"" + jsonEscape(gatewayStatus) + "\",";
  json += "\"wifi_connected\":" + String(WiFi.status() == WL_CONNECTED ? "true" : "false") + ",";
  json += "\"wifi_ip\":\"" + WiFi.localIP().toString() + "\",";
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
  localServer.on("/", HTTP_GET, []() { localServer.send_P(200, "text/html", LOCAL_DASHBOARD); });
  localServer.on("/status", HTTP_GET, handleLocalStatus);
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
  if (type == "ROAD_ACCIDENT") return "Road Accident";
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
    lastForwardedMessage = msgId + " from " + senderId;
    Serial.println("[SOS] Laravel accepted " + lastForwardedMessage);
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

bool forwardHeartbeatToLaravel(const String& packet, int rssi) {
  String nodeId = getField(packet, 1);
  String battery = getField(packet, 2);
  String hop = getField(packet, 3);
  String ttl = getField(packet, 4);
  String route = appendGatewayToRoute(getField(packet, 5));
  String peers = getField(packet, 6);

  if (nodeId.length() == 0) return false;
  String body = "{";
  body += "\"node_id\":\"" + jsonEscape(nodeId) + "\",";
  body += "\"gateway_id\":\"NODE_1\",";
  body += "\"route\":\"" + jsonEscape(route) + "\",";
  body += "\"peers\":" + peersAsJson(peers) + ",";
  body += "\"battery\":\"" + jsonEscape(battery.length() ? battery : "NA") + "\",";
  body += "\"hop\":" + String(hop.toInt()) + ",";
  body += "\"ttl\":" + String(ttl.toInt()) + ",";
  body += "\"rssi\":" + String(rssi) + ",";
  body += "\"packet_type\":\"HB\"";
  body += "}";

  String response;
  return requestApi("POST", "/heartbeat", body, response);
}

void handleIncomingLoRa() {
  const int packetSize = LoRa.parsePacket();
  if (!packetSize) return;

  String packet;
  while (LoRa.available()) packet += (char) LoRa.read();
  packet.trim();
  const int rssi = LoRa.packetRssi();
  const float snr = LoRa.packetSnr();
  Serial.println("[LORA RX " + String(rssi) + " dBm] " + packet);

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
    forwardSosToLaravel(packet, rssi, snr);
  } else if (packet.startsWith("HB|")) {
    forwardHeartbeatToLaravel(packet, rssi);
  }
}

void connectRouter() {
  if (!isConfigured()) {
    gatewayStatus = "Configure gateway Wi-Fi, server URL, and token";
    return;
  }
  if (WiFi.status() == WL_CONNECTED) return;

  // End any previous in-progress STA attempt before starting a clean retry.
  // This avoids ESP-IDF's "sta is connecting, cannot set config" error.
  WiFi.disconnect(false, false);
  delay(250);
  WiFi.setAutoReconnect(true);
  WiFi.begin(ROUTER_WIFI_SSID, ROUTER_WIFI_PASSWORD);
  unsigned long started = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - started < 20000) delay(250);

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("Router connected: " + WiFi.localIP().toString());
    configTime(0, 0, "pool.ntp.org", "time.nist.gov", "time.cloudflare.com");
    Serial.println("Laravel server: " + String(SERVER_BASE_URL));
    gatewayStatus = "Router connected; checking Laravel";
    lastServerCheckMs = millis() - SERVER_CHECK_INTERVAL_MS;
  } else {
    gatewayStatus = "Router unavailable; field nodes will queue reports";
    Serial.println(gatewayStatus);
    Serial.println("Wi-Fi status code: " + String((int) WiFi.status()));
  }
}

void setup() {
  Serial.begin(115200);
  delay(500);
  randomSeed(esp_random());

  prefs.begin("node1", false);
  latestMsgId = prefs.getString("latest_msg", "");
  latestCreatedAt = prefs.getString("latest_created", "");
  latestReportStatus = prefs.getString("latest_status", "NONE");
  latestAckMessage = prefs.getString("latest_ack", "");
  latestAckAt = prefs.getString("latest_ack_at", "");
  pendingLocalPacket = prefs.getString("pending_packet", "");
  pendingLocalMsgId = prefs.getString("pending_msg", "");

  WiFi.mode(WIFI_AP_STA);
  WiFi.softAP(LOCAL_AP_SSID, LOCAL_AP_PASSWORD);
  Serial.println("NODE_1 local dashboard: http://" + WiFi.softAPIP().toString());
  startCaptivePortal();
  connectRouter();

  SPI.begin(LORA_SCK, LORA_MISO, LORA_MOSI, LORA_SS);
  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(LORA_FREQUENCY)) {
    Serial.println("LoRa init failed. Check wiring and radio settings.");
    while (true) delay(1000);
  }

  LoRa.setSyncWord(LORA_SYNC_WORD);
  LoRa.setTxPower(17);
  LoRa.setSpreadingFactor(7);
  LoRa.setSignalBandwidth(125E3);
  LoRa.setCodingRate4(5);
  LoRa.receive();
  Serial.println("NODE_1 Laravel LoRa gateway ready.");
}

void loop() {
  dnsServer.processNextRequest();
  localServer.handleClient();

  if (WiFi.status() != WL_CONNECTED && millis() - lastWifiReconnectMs >= SERVER_CHECK_INTERVAL_MS) {
    lastWifiReconnectMs = millis();
    connectRouter();
  }

  handleIncomingLoRa();
  checkServerIfNeeded();
  sendGatewayHeartbeatIfNeeded();
  sendGatewayBeaconIfNeeded();
  pollNetworkSummaryIfNeeded();
  pollPendingAcknowledgmentIfNeeded();
  retryLocalReportIfNeeded();
  delay(2);
}
