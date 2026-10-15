#include <WiFi.h>
#include <WebServer.h>
#include <Preferences.h>
#include <SPI.h>
#include <LoRa.h>

// LoRa pins
#define LORA_SS 5
#define LORA_RST 14
#define LORA_DIO0 4

// Optional physical SOS button. The local Wi-Fi dashboard is the primary sender UI.
#define BUTTON_PIN 27

const char *ACCESS_POINT_NAME = "STITCH-LORA-GW1";
const char *ACCESS_POINT_PASSWORD = "CHANGE_LEGACY_AP_PASSWORD";

const String SENDER_ID = "S01";
const String SOURCE_GATEWAY_ID = "G1";

const size_t FULL_NAME_LIMIT = 48;
const size_t DESCRIPTION_LIMIT = 80;
const size_t MAX_PACKET_BYTES = 220;
const unsigned long RESEND_INTERVAL = 3000;
const int SEND_RETRY_LIMIT = 5;

WebServer server(80);
Preferences preferences;

bool lastButtonState = HIGH;
bool waitingForAck = false;

unsigned long lastSendTime = 0;
uint32_t sequenceNumber = 0;
int sendAttempts = 0;

String lastFullName = "";
String lastDescription = "";
String lastPacket = "";
String deliveryStatus = "Ready for an emergency report.";

String cleanPacketField(String value, size_t maxLength) {
  value.trim();
  value.replace("|", "/");
  value.replace("\r", " ");
  value.replace("\n", " ");

  while (value.indexOf("  ") >= 0) {
    value.replace("  ", " ");
  }

  if (value.length() > maxLength) {
    value = value.substring(0, maxLength);
    value.trim();
  }

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

String buildAlertPacket() {
  String packet = "ALERT|";
  packet += SENDER_ID;
  packet += "|";
  packet += String(sequenceNumber);
  packet += "|";
  packet += SOURCE_GATEWAY_ID;
  packet += "|";

  // Keep the legacy packet fields for receiver compatibility. Laravel resolves
  // the sender's fixed position from config/lora_nodes.php and ignores these.
  packet += "0.000000|0.000000|0";

  packet += "|";
  packet += lastFullName;
  packet += "|";
  packet += lastDescription;

  return packet;
}

String createAlertPacket() {
  String packet = buildAlertPacket();

  while (packet.length() > MAX_PACKET_BYTES && lastDescription.length() > 0) {
    lastDescription.remove(lastDescription.length() - 1);
    packet = buildAlertPacket();
  }

  return packet;
}

void transmitCurrentAlert() {
  lastPacket = createAlertPacket();

  Serial.println("Sending LoRa emergency packet:");
  Serial.println(lastPacket);

  LoRa.beginPacket();
  LoRa.print(lastPacket);
  LoRa.endPacket();

  waitingForAck = true;
  lastSendTime = millis();
  deliveryStatus = "Sending message " + String(sequenceNumber) + " to the LoRa receiver...";
}

void startNewAlert(String fullName, String description) {
  lastFullName = cleanPacketField(fullName, FULL_NAME_LIMIT);
  lastDescription = cleanPacketField(description, DESCRIPTION_LIMIT);

  sequenceNumber++;
  preferences.putUInt("sequence", sequenceNumber);
  sendAttempts = 1;
  transmitCurrentAlert();
}

void receiveLoRaResponse() {
  int packetSize = LoRa.parsePacket();

  if (!packetSize) {
    return;
  }

  String message = "";
  while (LoRa.available()) {
    message += (char)LoRa.read();
  }

  Serial.println("LoRa response: " + message);

  String networkAck = "ACK|" + SENDER_ID + "|" + String(sequenceNumber);
  String adminAck = "ALERT_ACKNOWLEDGED|" + SENDER_ID + "|" + String(sequenceNumber);

  if (message == networkAck) {
    waitingForAck = false;
    deliveryStatus = "Delivered to the receiver. The report is now being forwarded to the admin dashboard.";
  } else if (message == adminAck) {
    waitingForAck = false;
    deliveryStatus = "The admin receiver acknowledged this emergency report.";
  }
}

String dashboardHTML() {
  String html = R"rawliteral(
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>STITCH LoRa SOS</title>
  <style>
    :root{color-scheme:dark;--bg:#07111f;--panel:#101f33;--line:#263c57;--text:#f8fafc;--muted:#a9b8ca;--red:#ef4444;--amber:#f59e0b;--green:#22c55e}
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#162b48 0,#07111f 48%);color:var(--text);font-family:Arial,sans-serif;padding:18px}
    main{max-width:620px;margin:0 auto;display:grid;gap:16px}.panel{background:rgba(16,31,51,.96);border:1px solid var(--line);border-radius:22px;padding:20px;box-shadow:0 18px 42px rgba(0,0,0,.28)}
    .eyebrow{margin:0 0 7px;color:#7dd3fc;font-size:.75rem;font-weight:900;letter-spacing:.14em;text-transform:uppercase}h1{margin:0;font-size:clamp(1.75rem,7vw,2.65rem);line-height:1.05}p{color:var(--muted);line-height:1.55}.status{padding:13px 15px;border-radius:14px;background:#0a1728;border:1px solid var(--line);font-weight:800}.status.ok{border-color:rgba(34,197,94,.55);color:#bbf7d0}.status.warn{border-color:rgba(245,158,11,.55);color:#fde68a}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.metric{background:#0a1728;border-radius:14px;padding:13px}.metric span{display:block;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.1em}.metric strong{display:block;margin-top:6px;overflow-wrap:anywhere}
    label{display:block;margin:15px 0 7px;font-weight:800}.required{color:#fca5a5}input,textarea{width:100%;border:1px solid var(--line);border-radius:14px;background:#07111f;color:var(--text);padding:14px;font:inherit}textarea{min-height:108px;resize:vertical}input:focus,textarea:focus{outline:3px solid rgba(125,211,252,.2);border-color:#7dd3fc}.counter{display:block;margin-top:6px;color:var(--muted);font-size:.78rem;text-align:right}
    button{width:100%;margin-top:18px;border:0;border-radius:16px;padding:17px;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;font:900 1.05rem Arial;letter-spacing:.04em;box-shadow:0 12px 28px rgba(239,68,68,.28)}button:active{transform:scale(.985)}.note{font-size:.86rem}.delivery{border-left:4px solid #38bdf8}.footer{text-align:center;color:#71839a;font-size:.78rem}@media(max-width:520px){body{padding:10px}.panel{padding:17px}.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<main>
  <section class="panel">
    <p class="eyebrow">Offline LoRa reporting path</p>
    <h1>Emergency SOS</h1>
    <p>Connected to the local ESP32 gateway. This page works without mobile data or internet.</p>
    <div class="grid">
      <div class="metric"><span>Gateway</span><strong>)rawliteral";
  html += SOURCE_GATEWAY_ID;
  html += R"rawliteral(</strong></div>
      <div class="metric"><span>Node position</span><strong>Fixed in official node registry</strong></div>
    </div>
  </section>

  <section class="panel delivery">
    <p class="eyebrow">Delivery status</p>
    <strong>)rawliteral";
  html += htmlEscape(deliveryStatus);
  html += R"rawliteral(</strong>
  </section>

  <form class="panel" method="post" action="/send">
    <p class="eyebrow">Compact emergency packet</p>
    <label for="full_name">Full name <span class="required">required</span></label>
    <input id="full_name" name="full_name" maxlength="48" autocomplete="name" required placeholder="Example: Juan Dela Cruz" value=")rawliteral";
  html += "\"" + htmlEscape(lastFullName) + "\"";
  html += R"rawliteral(>
    <span class="counter">Maximum 48 characters</span>

    <label for="description">Short description <span class="note">optional</span></label>
    <textarea id="description" name="description" maxlength="80" placeholder="Example: Motorcycle crash, one injured person.">)rawliteral";
  html += htmlEscape(lastDescription);
  html += R"rawliteral(</textarea>
    <span class="counter"><span id="description-count">0</span>/80 characters</span>

    <button type="submit">SEND SOS / EMERGENCY REPORT</button>
    <p class="note">LoRa sends text only: sender ID, message ID, name, and up to 80 description characters. The official system assigns this node's fixed coordinates. No GPS module, photo, video, or online account data is required.</p>
  </form>
  <div class="footer">STITCH Gateway G1 | Open 192.168.4.1 after joining STITCH-LORA-GW1</div>
</main>
<script>
  const description=document.getElementById('description');
  const count=document.getElementById('description-count');
  const sync=()=>count.textContent=String(description.value.length);
  description.addEventListener('input',sync);sync();
</script>
</body>
</html>
)rawliteral";

  return html;
}

void handleDashboard() {
  server.send(200, "text/html", dashboardHTML());
}

void handleSend() {
  if (waitingForAck) {
    server.send(409, "text/plain", "The previous SOS is still waiting for the LoRa receiver. Return to 192.168.4.1 and try again shortly.");
    return;
  }

  String fullName = cleanPacketField(server.arg("full_name"), FULL_NAME_LIMIT);
  String description = cleanPacketField(server.arg("description"), DESCRIPTION_LIMIT);

  if (fullName.length() < 3) {
    server.send(422, "text/plain", "Full name is required and must contain at least 3 characters.");
    return;
  }

  startNewAlert(fullName, description);
  server.sendHeader("Location", "/");
  server.send(303, "text/plain", "");
}

void handleNotFound() {
  server.sendHeader("Location", "http://192.168.4.1/");
  server.send(302, "text/plain", "Open the STITCH LoRa SOS dashboard.");
}

void setup() {
  Serial.begin(115200);
  pinMode(BUTTON_PIN, INPUT_PULLUP);

  preferences.begin("lora-sender", false);
  sequenceNumber = preferences.getUInt("sequence", 0);

  LoRa.setPins(LORA_SS, LORA_RST, LORA_DIO0);
  if (!LoRa.begin(433E6)) {
    Serial.println("LoRa initialization failed.");
    while (true) {
      delay(1000);
    }
  }
  LoRa.setSyncWord(0x12);
  LoRa.enableCrc();

  WiFi.mode(WIFI_AP);
  WiFi.softAP(ACCESS_POINT_NAME, ACCESS_POINT_PASSWORD);

  server.on("/", HTTP_GET, handleDashboard);
  server.on("/send", HTTP_POST, handleSend);
  server.onNotFound(handleNotFound);
  server.begin();

  Serial.println("STITCH LoRa sender ready.");
  Serial.println("Wi-Fi: " + String(ACCESS_POINT_NAME));
  Serial.println("Dashboard: http://192.168.4.1");
}

void loop() {
  server.handleClient();
  receiveLoRaResponse();

  bool currentButtonState = digitalRead(BUTTON_PIN);
  if (!waitingForAck && lastButtonState == HIGH && currentButtonState == LOW) {
    delay(40);
    if (digitalRead(BUTTON_PIN) == LOW && lastFullName.length() >= 3) {
      startNewAlert(lastFullName, "Physical SOS button pressed at gateway " + SOURCE_GATEWAY_ID);
    } else if (lastFullName.length() < 3) {
      deliveryStatus = "Open 192.168.4.1 and enter the sender's full name before using SOS.";
    }
  }
  lastButtonState = currentButtonState;

  if (waitingForAck && millis() - lastSendTime >= RESEND_INTERVAL) {
    if (sendAttempts < SEND_RETRY_LIMIT) {
      sendAttempts++;
      transmitCurrentAlert();
    } else {
      waitingForAck = false;
      deliveryStatus = "No receiver ACK after 5 attempts. Check LoRa power/range, then send the report again.";
    }
  }
}

