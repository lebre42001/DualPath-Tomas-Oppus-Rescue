#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <ESPmDNS.h>
#include <SPI.h>
#include <LoRa.h>

#if __has_include("arduino_secrets.h")
#include "arduino_secrets.h"
#else
const char* WIFI_SSID = "";
const char* WIFI_PASSWORD = "";
const char* STITCH_LORA_API_URL = "";
const char* STITCH_LORA_TOKEN = "";
#endif

// =====================
// LoRa Pins
// =====================
#define LORA_SS    5
#define LORA_RST   14
#define LORA_DIO0  4

// =====================
// Alarm Pins
// =====================
#define GREEN_LED 25
#define RED_LED   26
#define BUZZER    27

WebServer server(80);

// =====================
// System Variables
// =====================
bool alertActive = false;
bool pendingApiSync = false;
unsigned long lastApiRetryAt = 0;

String currentStatus = "WAITING / SECURE";
String lastSenderId = "-";
String lastFullName = "-";
String lastDescription = "-";
String lastSeq = "-";
String lastGateway = "-";
String lastRelayGateway = "-";
String lastLat = "0.000000";
String lastLng = "0.000000";
String lastSat = "0";
String lastRssi = "0";
String lastSnr = "0";
String lastGatewayRssiValue = "0";
String lastGatewaySnrValue = "0";
int lastReceiverRssiValue = 0;
float lastReceiverSnrValue = 0;
String logs = "";

String lastAlertUnique = "";
bool lastPacketWasRelayed = false;

// =====================
// Get field from LoRa packet
// Example packet:
// GW|G1|-70|9.5|ALERT|S01|1|10.123456|123.123456|7
// =====================
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

// =====================
// Add logs to dashboard
// =====================
void addLog(String text) {
  logs = text + "<br>" + logs;

  if (logs.length() > 7000) {
    logs = logs.substring(0, 7000);
  }
}

// =====================
// Alarm ON
// =====================
void alarmOn() {
  alertActive = true;
  currentStatus = "!!! EMERGENCY DETECTED !!!";

  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, HIGH);
  digitalWrite(BUZZER, HIGH);
}

// =====================
// Alarm OFF
// =====================
void alarmOff() {
  alertActive = false;
  currentStatus = "WAITING / SECURE";

  digitalWrite(GREEN_LED, HIGH);
  digitalWrite(RED_LED, LOW);
  digitalWrite(BUZZER, LOW);
}

// =====================
// Send automatic ACK to gateway
// Receiver tells gateway: alert was received
// =====================
void sendNetAck() {
  if (lastSenderId == "-") return;

  String ack;
  if (lastPacketWasRelayed) {
    ack = "NETACK|" + lastRelayGateway + "|" + lastSenderId + "|" + lastSeq;
  } else {
    ack = "ACK|" + lastSenderId + "|" + lastSeq;
  }

  Serial.println("Sending network ACK:");
  Serial.println(ack);

  delay(200);

  LoRa.beginPacket();
  LoRa.print(ack);
  LoRa.endPacket();
}

// =====================
// Send LoRa alert to Stitch web API
// =====================
String jsonEscape(String value) {
  value.replace("\\", "\\\\");
  value.replace("\"", "\\\"");
  value.replace("\n", "\\n");
  value.replace("\r", "\\r");

  return value;
}

String htmlEscape(String value) {
  value.replace("&", "&amp;");
  value.replace("<", "&lt;");
  value.replace(">", "&gt;");
  value.replace("\"", "&quot;");
  value.replace("'", "&#39;");
  return value;
}

void addJsonStringField(String &payload, String key, String value, bool comma = true) {
  payload += "\"";
  payload += key;
  payload += "\":\"";
  payload += jsonEscape(value);
  payload += "\"";

  if (comma) {
    payload += ",";
  }
}

void addJsonNumberField(String &payload, String key, String value, bool comma = true) {
  payload += "\"";
  payload += key;
  payload += "\":";
  payload += value;

  if (comma) {
    payload += ",";
  }
}

bool postAlertToStitch(
  String senderId,
  String seq,
  String fullName,
  String description,
  String gatewayId,
  String lat,
  String lng,
  String sat,
  String gatewayRssi,
  String gatewaySnr,
  int receiverRssi,
  float receiverSnr
) {
  String apiUrl = String(STITCH_LORA_API_URL);
  String apiToken = String(STITCH_LORA_TOKEN);

  if (apiUrl.length() == 0 || apiToken.length() == 0) {
    Serial.println("Stitch API URL or token is not configured.");
    return false;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi is not connected. Skipping Stitch API post.");
    return false;
  }

  String payload = "{";
  addJsonStringField(payload, "sender_id", senderId);
  addJsonStringField(payload, "sequence", seq);
  addJsonStringField(payload, "reporter_name", fullName);
  addJsonStringField(payload, "gateway_id", gatewayId);
  addJsonNumberField(payload, "latitude", String(lat.toFloat(), 6));
  addJsonNumberField(payload, "longitude", String(lng.toFloat(), 6));
  addJsonNumberField(payload, "satellites", String(sat.toInt()));
  addJsonNumberField(payload, "gateway_rssi", String(gatewayRssi.toInt()));
  addJsonNumberField(payload, "gateway_snr", String(gatewaySnr.toFloat(), 2));
  addJsonNumberField(payload, "receiver_rssi", String(receiverRssi));
  addJsonNumberField(payload, "receiver_snr", String(receiverSnr, 2));
  addJsonStringField(payload, "severity", "Serious");
  addJsonStringField(payload, "incident_type", "LoRa Emergency Alert");
  addJsonStringField(payload, "description", description, false);
  payload += "}";

  HTTPClient http;
  int httpCode = -1;
  String response = "";

  if (apiUrl.startsWith("https://")) {
    WiFiClientSecure secureClient;
    secureClient.setInsecure();

    if (!http.begin(secureClient, apiUrl)) {
      Serial.println("Unable to start HTTPS request.");
      return false;
    }

    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("X-STITCH-LORA-TOKEN", apiToken);

    httpCode = http.POST(payload);
    response = http.getString();
    http.end();
  } else {
    WiFiClient plainClient;

    if (!http.begin(plainClient, apiUrl)) {
      Serial.println("Unable to start HTTP request.");
      return false;
    }

    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("X-STITCH-LORA-TOKEN", apiToken);

    httpCode = http.POST(payload);
    response = http.getString();
    http.end();
  }

  Serial.print("Stitch API status: ");
  Serial.println(httpCode);
  Serial.println(response);

  return httpCode >= 200 && httpCode < 300;
}

// =====================
// Send user ACK to gateway
// Receiver tells gateway: dashboard user clicked acknowledge
// =====================
void sendUserAck() {
  if (lastSenderId == "-") return;

  String ack;
  if (lastPacketWasRelayed) {
    ack = "USERACK|" + lastRelayGateway + "|" + lastSenderId + "|" + lastSeq;
  } else {
    ack = "ALERT_ACKNOWLEDGED|" + lastSenderId + "|" + lastSeq;
  }

  Serial.println("Sending USERACK:");
  Serial.println(ack);

  delay(200);

  LoRa.beginPacket();
  LoRa.print(ack);
  LoRa.endPacket();
}

// =====================
// Emergency Dashboard HTML
// =====================
String dashboardHTML() {
  String statusClass = alertActive ? "alert" : "secure";
  String mapLink = "https://www.google.com/maps?q=" + lastLat + "," + lastLng;
  String descriptionDisplay = lastDescription.length() > 0
    ? lastDescription
    : String("No optional description.");

  String html = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
  <title>LoRa Rescue Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="5">

  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      background:
        radial-gradient(circle at top, rgba(239,68,68,0.18), transparent 35%),
        linear-gradient(135deg, #020617, #0f172a 55%, #111827);
      color: white;
      min-height: 100vh;
      padding: 18px;
    }

    .container {
      max-width: 780px;
      margin: auto;
    }

    .top-bar {
      background: rgba(15, 23, 42, 0.88);
      border: 1px solid rgba(148, 163, 184, 0.25);
      border-radius: 22px;
      padding: 18px;
      box-shadow: 0 15px 35px rgba(0,0,0,0.45);
      backdrop-filter: blur(8px);
      margin-bottom: 18px;
      position: relative;
      overflow: hidden;
    }

    .top-bar::before {
      content: "";
      position: absolute;
      top: 0;
      left: -50%;
      width: 50%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
      animation: scan 4s infinite;
    }

    @keyframes scan {
      0% { left: -60%; }
      100% { left: 120%; }
    }

    .title {
      font-size: 27px;
      font-weight: 900;
      letter-spacing: 1px;
      color: #f8fafc;
      margin: 0;
      text-align: center;
    }

    .title span {
      color: #ef4444;
      text-shadow: 0 0 18px rgba(239,68,68,0.85);
    }

    .subtitle {
      text-align: center;
      color: #94a3b8;
      font-size: 14px;
      margin-top: 8px;
    }

    .emergency-banner {
      display: none;
      background: linear-gradient(90deg, #7f1d1d, #ef4444, #7f1d1d);
      border: 1px solid #fecaca;
      border-radius: 18px;
      padding: 15px;
      text-align: center;
      font-weight: 900;
      letter-spacing: 1px;
      color: white;
      margin-bottom: 18px;
      box-shadow:
        0 0 20px rgba(239,68,68,0.8),
        0 0 45px rgba(239,68,68,0.45);
      animation: emergencyPulse 1s infinite alternate;
    }

    body.alert-mode .emergency-banner {
      display: block;
    }

    @keyframes emergencyPulse {
      from {
        transform: scale(1);
        filter: brightness(1);
      }
      to {
        transform: scale(1.02);
        filter: brightness(1.25);
      }
    }

    .status-card {
      background: rgba(30, 41, 59, 0.94);
      border-radius: 22px;
      padding: 22px;
      margin-bottom: 18px;
      text-align: center;
      border: 1px solid rgba(148, 163, 184, 0.22);
      box-shadow: 0 15px 35px rgba(0,0,0,0.45);
    }

    .status-label {
      color: #94a3b8;
      font-size: 12px;
      font-weight: bold;
      letter-spacing: 1.5px;
      margin-bottom: 12px;
    }

    .status {
      font-size: 23px;
      font-weight: 900;
      padding: 18px;
      border-radius: 18px;
      letter-spacing: 1px;
    }

    .alert {
      background: rgba(127, 29, 29, 0.65);
      color: #fecaca;
      border: 2px solid #ef4444;
      box-shadow:
        0 0 18px rgba(239,68,68,0.95),
        inset 0 0 18px rgba(239,68,68,0.25);
      animation: redGlow 0.8s infinite alternate;
    }

    @keyframes redGlow {
      from {
        box-shadow:
          0 0 12px rgba(239,68,68,0.7),
          inset 0 0 12px rgba(239,68,68,0.2);
      }
      to {
        box-shadow:
          0 0 30px rgba(239,68,68,1),
          0 0 60px rgba(239,68,68,0.55),
          inset 0 0 25px rgba(239,68,68,0.35);
      }
    }

    .secure {
      background: rgba(6, 78, 59, 0.55);
      color: #bbf7d0;
      border: 2px solid #22c55e;
      box-shadow:
        0 0 14px rgba(34,197,94,0.55),
        inset 0 0 12px rgba(34,197,94,0.12);
    }

    .card {
      background: rgba(30, 41, 59, 0.94);
      border-radius: 22px;
      padding: 18px;
      margin-bottom: 18px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      box-shadow: 0 15px 35px rgba(0,0,0,0.45);
    }

    .section-title {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #e2e8f0;
      font-size: 15px;
      font-weight: 900;
      margin-bottom: 14px;
      letter-spacing: 0.8px;
    }

    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .item {
      background: linear-gradient(145deg, #020617, #111827);
      padding: 14px;
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.18);
      min-height: 72px;
    }

    .label {
      color: #94a3b8;
      font-size: 11px;
      font-weight: bold;
      letter-spacing: 1px;
      text-transform: uppercase;
    }

    .value {
      font-size: 15px;
      font-weight: 800;
      margin-top: 7px;
      color: #f8fafc;
      word-break: break-word;
    }

    .value.red {
      color: #fca5a5;
    }

    .value.green {
      color: #86efac;
    }

    .button-group {
      margin-top: 16px;
      display: grid;
      gap: 12px;
    }

    .btn {
      display: block;
      width: 100%;
      border: none;
      padding: 16px;
      border-radius: 16px;
      color: white;
      font-size: 15px;
      font-weight: 900;
      text-decoration: none;
      text-align: center;
      letter-spacing: 0.6px;
      transition: 0.2s;
    }

    .btn:active {
      transform: scale(0.97);
    }

    .map {
      background: linear-gradient(135deg, #2563eb, #38bdf8);
      box-shadow: 0 0 18px rgba(59,130,246,0.38);
    }

    .ack {
      background: linear-gradient(135deg, #059669, #22c55e);
      box-shadow: 0 0 18px rgba(34,197,94,0.4);
    }

    .clear {
      background: linear-gradient(135deg, #475569, #64748b);
    }

    body.alert-mode .ack {
      animation: ackGlow 1s infinite alternate;
    }

    @keyframes ackGlow {
      from {
        box-shadow: 0 0 12px rgba(34,197,94,0.35);
      }
      to {
        box-shadow: 0 0 28px rgba(34,197,94,0.95);
      }
    }

    .history {
      background: #020617;
      border: 1px solid rgba(148, 163, 184, 0.18);
      padding: 14px;
      border-radius: 16px;
      max-height: 280px;
      overflow-y: auto;
      font-family: Consolas, monospace;
      font-size: 13px;
      color: #cbd5e1;
      line-height: 1.6;
      text-align: left;
    }

    .footer {
      text-align: center;
      color: #64748b;
      font-size: 12px;
      padding: 12px;
    }

    .live-dot {
      width: 10px;
      height: 10px;
      background: #22c55e;
      border-radius: 50%;
      display: inline-block;
      margin-right: 6px;
      box-shadow: 0 0 12px #22c55e;
    }

    body.alert-mode .live-dot {
      background: #ef4444;
      box-shadow: 0 0 14px #ef4444;
      animation: dotBlink 0.6s infinite alternate;
    }

    @keyframes dotBlink {
      from { opacity: 0.4; }
      to { opacity: 1; }
    }

    @media (max-width: 520px) {
      body {
        padding: 12px;
      }

      .title {
        font-size: 22px;
      }

      .grid {
        grid-template-columns: 1fr;
      }

      .status {
        font-size: 19px;
      }
    }
  </style>
</head>
)rawliteral";

  if (alertActive) {
    html += "<body class='alert-mode'>";
  } else {
    html += "<body>";
  }

  html += R"rawliteral(
  <div class="container">

    <div class="top-bar">
      <h1 class="title"><span>🚨 LoRa</span> Rescue Command Center</h1>
      <div class="subtitle">
        <span class="live-dot"></span>
        Emergency GPS Monitoring and Gateway Relay System
      </div>
    </div>

    <div class="emergency-banner">
      ⚠️ EMERGENCY SIGNAL RECEIVED — IMMEDIATE RESPONSE REQUIRED ⚠️
    </div>

    <div class="status-card">
      <div class="status-label">CURRENT SYSTEM STATUS</div>
      <div class="status )rawliteral";

  html += statusClass;

  html += R"rawliteral(">)rawliteral";
  html += currentStatus;

  html += R"rawliteral(</div>
    </div>

    <div class="card">
      <div class="section-title">📡 LIVE EMERGENCY DATA</div>

      <div class="grid">
        <div class="item">
          <div class="label">Full Name</div>
          <div class="value">)rawliteral";
  html += htmlEscape(lastFullName);
  html += R"rawliteral(</div>
        </div>

        <div class="item">
          <div class="label">Sender ID</div>
          <div class="value">)rawliteral";
  html += lastSenderId;
  html += R"rawliteral(</div>
        </div>

        <div class="item">
          <div class="label">Gateway Used</div>
          <div class="value green">)rawliteral";
  html += lastGateway;
  html += R"rawliteral(</div>
        </div>

        <div class="item">
          <div class="label">Latitude</div>
          <div class="value">)rawliteral";
  html += lastLat;
  html += R"rawliteral(</div>
        </div>

        <div class="item">
          <div class="label">Longitude</div>
          <div class="value">)rawliteral";
  html += lastLng;
  html += R"rawliteral(</div>
        </div>

        <div class="item">
          <div class="label">GPS Satellites</div>
          <div class="value">)rawliteral";
  html += lastSat;
  html += R"rawliteral(</div>
        </div>

        <div class="item">
          <div class="label">Signal RSSI / SNR</div>
          <div class="value red">)rawliteral";
  html += lastRssi + " / " + lastSnr;
  html += R"rawliteral(</div>
        </div>

        <div class="item" style="grid-column:1/-1">
          <div class="label">Short Description</div>
          <div class="value">)rawliteral";
  html += htmlEscape(descriptionDisplay);
  html += R"rawliteral(</div>
        </div>
      </div>

      <div class="button-group">
        <a class="btn map" href=")rawliteral";

  html += mapLink;

  html += R"rawliteral(" target="_blank">📍 OPEN SENDER LOCATION</a>
        <a class="btn ack" href="/receive">✅ ACKNOWLEDGE / RECEIVE ALERT</a>
        <a class="btn clear" href="/clear">🗑 CLEAR HISTORY</a>
      </div>
    </div>

    <div class="card">
      <div class="section-title">📋 EMERGENCY RESPONSE LOGS</div>
      <div class="history">)rawliteral";

  if (logs.length() == 0) {
    html += "No emergency logs yet.";
  } else {
    html += logs;
  }

  html += R"rawliteral(</div>
    </div>

    <div class="footer">
      LoRa Emergency Rescue Monitoring System | ESP32 GPS Gateway Network
    </div>

  </div>
</body>
</html>
)rawliteral";

  return html;
}

// =====================
// Web Routes
// =====================
void handleDashboard() {
  server.send(200, "text/html", dashboardHTML());
}

void handleReceive() {
  if (alertActive) {
    alarmOff();

    addLog("✅ USER ACKNOWLEDGED ALERT | Sender: " + lastSenderId +
           " | Gateway: " + lastGateway);

    sendUserAck();
  }

  server.sendHeader("Location", "/");
  server.send(302, "text/plain", "");
}

void handleClear() {
  logs = "";

  server.sendHeader("Location", "/");
  server.send(302, "text/plain", "");
}

// =====================
// Handle received LoRa packet
// =====================
void handleLoRaPacket(String message, int rssi, float snr) {
  String command = getField(message, '|', 0);
  String gatewayId;
  String gatewayRssi;
  String gatewaySnr;
  String senderId;
  String seq;
  String lat;
  String lng;
  String sat;
  String fullName;
  String description;

  if (command == "ALERT") {
    // Current two-radio format:
    // ALERT|sender|sequence|source gateway|lat|lng|satellites|full name|description
    lastPacketWasRelayed = false;
    lastRelayGateway = "-";
    senderId = getField(message, '|', 1);
    seq = getField(message, '|', 2);
    gatewayId = getField(message, '|', 3);
    lat = getField(message, '|', 4);
    lng = getField(message, '|', 5);
    sat = getField(message, '|', 6);
    fullName = getField(message, '|', 7);
    description = getField(message, '|', 8);
    gatewayRssi = String(rssi);
    gatewaySnr = String(snr, 1);
  } else if (command == "GW" && getField(message, '|', 4) == "ALERT") {
    // Retained for the later mesh phase. Gateway1/Gateway2 wrap the ALERT packet.
    lastPacketWasRelayed = true;
    String relayGatewayId = getField(message, '|', 1);
    lastRelayGateway = relayGatewayId;
    gatewayRssi = getField(message, '|', 2);
    gatewaySnr = getField(message, '|', 3);
    senderId = getField(message, '|', 5);
    seq = getField(message, '|', 6);

    if (getField(message, '|', 10).length() > 0) {
      gatewayId = getField(message, '|', 7);
      lat = getField(message, '|', 8);
      lng = getField(message, '|', 9);
      sat = getField(message, '|', 10);
      fullName = getField(message, '|', 11);
      description = getField(message, '|', 12);
    } else {
      // Backward compatibility with the original compact relay packet.
      gatewayId = relayGatewayId;
      lat = getField(message, '|', 7);
      lng = getField(message, '|', 8);
      sat = getField(message, '|', 9);
      fullName = "LoRa Sender " + senderId;
      description = "";
    }
  } else {
    return;
  }

  if (gatewayId.length() == 0) gatewayId = "G1";
  if (fullName.length() == 0) fullName = "LoRa Sender " + senderId;

  String uniqueId = senderId + "-" + seq;

  if (uniqueId == lastAlertUnique) {
    Serial.println("Duplicate alert acknowledged without creating another admin record.");
    sendNetAck();
    return;
  }

  lastAlertUnique = uniqueId;

  lastGateway = gatewayId;
  lastSenderId = senderId;
  lastFullName = fullName;
  lastDescription = description;
  lastSeq = seq;
  lastLat = lat;
  lastLng = lng;
  lastSat = sat;
  lastGatewayRssiValue = gatewayRssi;
  lastGatewaySnrValue = gatewaySnr;
  lastReceiverRssiValue = rssi;
  lastReceiverSnrValue = snr;

  if (lastPacketWasRelayed) {
    lastRssi = "GW:" + gatewayRssi + " RX:" + String(rssi);
    lastSnr = "GW:" + gatewaySnr + " RX:" + String(snr, 1);
  } else {
    lastRssi = "DIRECT RX:" + String(rssi);
    lastSnr = "DIRECT RX:" + String(snr, 1);
  }

  alarmOn();

  addLog("🚨 ALERT RECEIVED | Sender: " + senderId +
         " | Name: " + htmlEscape(fullName) +
         " | Seq: " + seq +
         " | Gateway: " + gatewayId +
         " | Lat: " + lat +
         " | Lng: " + lng +
         " | Sat: " + sat +
         " | Signal: " + lastRssi + " / " + lastSnr);

  sendNetAck();

  if (postAlertToStitch(senderId, seq, fullName, description, gatewayId, lat, lng, sat, gatewayRssi, gatewaySnr, rssi, snr)) {
    pendingApiSync = false;
    addLog("Stitch web dashboard synced | Sender: " + senderId + " | Seq: " + seq);
  } else {
    pendingApiSync = true;
    lastApiRetryAt = millis();
    addLog("Stitch web dashboard sync failed | Sender: " + senderId + " | Seq: " + seq);
  }
}

void retryPendingApiSync() {
  if (!pendingApiSync || millis() - lastApiRetryAt < 10000) {
    return;
  }

  lastApiRetryAt = millis();

  if (postAlertToStitch(
        lastSenderId,
        lastSeq,
        lastFullName,
        lastDescription,
        lastGateway,
        lastLat,
        lastLng,
        lastSat,
        lastGatewayRssiValue,
        lastGatewaySnrValue,
        lastReceiverRssiValue,
        lastReceiverSnrValue
      )) {
    pendingApiSync = false;
    addLog("Stitch web dashboard retry succeeded | Sender: " + lastSenderId + " | Seq: " + lastSeq);
  }
}

// =====================
// Setup
// =====================
void setup() {
  Serial.begin(115200);

  pinMode(GREEN_LED, OUTPUT);
  pinMode(RED_LED, OUTPUT);
  pinMode(BUZZER, OUTPUT);

  alarmOff();

  // Connect to the admin-server uplink without blocking LoRa forever.
  if (String(WIFI_SSID).length() > 0) {
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    Serial.print("Connecting WiFi");

    unsigned long wifiStartedAt = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - wifiStartedAt < 20000) {
      delay(500);
      Serial.print(".");
    }
    Serial.println();
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("WiFi connected. IP address: ");
    Serial.println(WiFi.localIP());

    if (MDNS.begin("lora-rescue")) {
      Serial.println("mDNS started: http://lora-rescue.local");
    }
  } else {
    Serial.println("WiFi/admin API not configured or unavailable. LoRa receiving remains active.");
  }

  // LoRa Start
  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);

  if (!LoRa.begin(433E6)) {
    Serial.println("LoRa Init Failed!");
    digitalWrite(RED_LED, HIGH);
    while (1);
  }

  LoRa.setSyncWord(0x12);
  LoRa.enableCrc();

  // Web Server Routes
  server.on("/", handleDashboard);
  server.on("/receive", handleReceive);
  server.on("/clear", handleClear);

  server.begin();

  Serial.println("Receiver Dashboard Ready");
}

// =====================
// Main Loop
// =====================
void loop() {
  server.handleClient();
  retryPendingApiSync();

  int packetSize = LoRa.parsePacket();

  if (packetSize) {
    String message = "";

    while (LoRa.available()) {
      message += (char)LoRa.read();
    }

    int rssi = LoRa.packetRssi();
    float snr = LoRa.packetSnr();

    Serial.println("LoRa Received:");
    Serial.println(message);
    Serial.print("Receiver RSSI: ");
    Serial.print(rssi);
    Serial.print(" | Receiver SNR: ");
    Serial.println(snr);

    handleLoRaPacket(message, rssi, snr);
  }
}
