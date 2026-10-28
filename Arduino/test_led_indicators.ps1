# Exercise the actual firmware LED functions with a fake clock and GPIO outputs.
# No radio, credentials, Arduino IDE, or physical boards are used by this test.
$ErrorActionPreference = 'Stop'

function Get-FirmwareFunction {
    param([string]$Source, [string]$Name)
    $pattern = '(?ms)^(?:void|bool)\s+' + [regex]::Escape($Name) + '\([^)]*\)\s*\{.*?^\}'
    $match = [regex]::Match($Source, $pattern)
    if (-not $match.Success) { throw "Firmware function not found: $Name" }
    # The timing/GPIO code is also valid C# after translating uint32_t's Arduino
    # spelling and removing C++ local const qualifiers (runtime values in C#).
    return $match.Value.Replace('unsigned long', 'uint').Replace('const ', '')
}

function Get-LedFields {
    param([string]$Source, [string[]]$Names)
    $fields = foreach ($name in $Names) {
        $pattern = '(?m)^(?:const\s+)?(?:unsigned long|bool|int)\s+' + [regex]::Escape($name) + '\s*=[^;\r\n]+;'
        $match = [regex]::Match($Source, $pattern)
        if (-not $match.Success) { throw "Firmware LED field not found: $name" }
        $match.Value.Replace('unsigned long', 'uint')
    }
    return $fields -join "`n"
}

function Assert-Led {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw "LED test failed: $Message" }
}

$field = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'Arduino\FieldNode\FieldNode.ino') -Raw
$gateway = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'Arduino\GatewayNode1\GatewayNode1.ino') -Raw
$commonFields = @('GREEN_LED_PIN', 'LED_ACTIVITY_MS', 'LED_FAST_TOGGLE_MS', 'LED_SLOW_TOGGLE_MS',
    'lastLedActivityMs', 'lastLedToggleMs', 'ledActivityPending', 'ledActivityActive', 'ledBlinkState')
$fieldFields = Get-LedFields -Source $field -Names (@('NODE_NUMBER', 'HAS_THREE_STATUS_LEDS',
    'YELLOW_STATUS_LED_PIN', 'RED_EMERGENCY_LED_PIN', 'MESH_STATUS_LED_PIN',
    'lastEmergencyLedToggleMs', 'emergencyLedBlinkState', 'emergencyLedWasPending') + $commonFields)
$fieldFunctions = (@('markLoRaActivity', 'hasPendingLocalEmergency', 'initializeLedIndicators', 'updateLedIndicators') |
    ForEach-Object { Get-FirmwareFunction -Source $field -Name $_ }) -join "`n"
$gatewayFields = Get-LedFields -Source $gateway -Names (@('YELLOW_STATUS_LED_PIN', 'RED_EMERGENCY_LED_PIN',
    'lastEmergencyLedToggleMs', 'emergencyLedBlinkState', 'emergencyLedWasPending') + $commonFields)
$gatewayFunctions = (@('markLoRaActivity', 'hasPendingLocalEmergency', 'initializeLedIndicators', 'updateLedIndicators') |
    ForEach-Object { Get-FirmwareFunction -Source $gateway -Name $_ }) -join "`n"
$fieldRouteFields = Get-LedFields -Source $field -Names @('SERVER_ROUTE_TIMEOUT_MS', 'lastGatewaySeenMs')
$gatewayRouteFields = Get-LedFields -Source $gateway -Names @('SERVER_ROUTE_TIMEOUT_MS', 'lastServerSuccessMs')
$fieldRouteFunction = Get-FirmwareFunction -Source $field -Name 'serverRouteAvailable'
$heartbeatRelayFunction = Get-FirmwareFunction -Source $field -Name 'heartbeatRelayAllowed'
$gatewayRouteFunction = Get-FirmwareFunction -Source $gateway -Name 'serverRouteAvailable'
$ackHandler = Get-FirmwareFunction -Source $field -Name 'handleAck'
$ackGuard = [regex]::Match($ackHandler,
    '(?s)if\s*\((\(latestMsgId == msgId \|\| pendingMsgId == msgId\).*?)\)\s*\{\s*saveAcknowledgedState')
if (-not $ackGuard.Success) { throw 'Matching final ACK guard not found in field-node firmware.' }
$ackCondition = $ackGuard.Groups[1].Value.Replace('.length()', '.Length')

$namespace = 'DualPathLedTests_' + [guid]::NewGuid().ToString('N')
$code = @"
using System.Collections.Generic;
namespace $namespace {
public sealed class PendingPacket {
    public bool Present;
    public int length() { return Present ? 1 : 0; }
}
public abstract class LedHarness {
    protected const int HIGH = 1, LOW = 0, OUTPUT = 1;
    private uint clock;
    private bool peer, route;
    protected PendingPacket pendingPacket = new PendingPacket();
    protected PendingPacket pendingLocalPacket = new PendingPacket();
    private Dictionary<int, int> outputs = new Dictionary<int, int>();
    protected uint millis() { return clock; }
    protected bool hasFreshLoRaPeer() { return peer; }
    protected bool hasConfirmedServerRoute() { return route; }
    protected void pinMode(int pin, int mode) { outputs[pin] = -1; }
    protected void digitalWrite(int pin, int value) { outputs[pin] = value; }
    protected abstract void Drive();
    protected abstract void Activity();
    public int Read(int pin) { return outputs.ContainsKey(pin) ? outputs[pin] : -1; }
    public void Pending(bool present) { pendingPacket.Present = present; pendingLocalPacket.Present = present; }
    public void Tick(uint now, bool hasPeer, bool hasRoute, bool activity) {
        clock = now; peer = hasPeer; route = hasRoute;
        if (activity) Activity();
        Drive();
    }
}
"@

foreach ($n in 2..10) {
    $fields = $fieldFields.Replace('const int NODE_NUMBER = 2;', "const int NODE_NUMBER = $n;")
    $code += @"
public sealed class Node$n : LedHarness {
$fields
$fieldFunctions
    public Node$n() { initializeLedIndicators(); }
    protected override void Drive() { updateLedIndicators(); }
    protected override void Activity() { markLoRaActivity(); }
}
"@
}
$code += @"
public sealed class Node1 : LedHarness {
$gatewayFields
$gatewayFunctions
    public Node1() { initializeLedIndicators(); }
    protected override void Drive() { updateLedIndicators(); }
    protected override void Activity() { markLoRaActivity(); }
}
public static class AckGuard {
    public static bool Accept(string latestMsgId, string pendingMsgId, string msgId,
                              string gateway, string acknowledgedAt, string status) {
        return $ackCondition;
    }
}
public abstract class RouteClock {
    protected uint clock;
    protected uint millis() { return clock; }
}
public sealed class FieldRouteLease : RouteClock {
    private int gatewayHopCount;
$fieldRouteFields
$fieldRouteFunction
$heartbeatRelayFunction
    public bool Relay(uint now, uint lastBeacon, int ourDistance, int senderDistance) {
        clock = now; lastGatewaySeenMs = lastBeacon; gatewayHopCount = ourDistance;
        return heartbeatRelayAllowed(senderDistance);
    }
    public bool Check(uint now, uint lastBeacon) {
        clock = now; lastGatewaySeenMs = lastBeacon;
        return serverRouteAvailable();
    }
}
public sealed class FakeWiFi {
    public int LinkStatus;
    public int status() { return LinkStatus; }
}
public sealed class GatewayRouteLease : RouteClock {
    private const int WL_CONNECTED = 3;
    private FakeWiFi WiFi = new FakeWiFi();
$gatewayRouteFields
$gatewayRouteFunction
    public bool Check(uint now, uint lastSuccess, bool connected) {
        clock = now; lastServerSuccessMs = lastSuccess;
        WiFi.LinkStatus = connected ? WL_CONNECTED : 6;
        return serverRouteAvailable();
    }
}
}
"@
# A constant node group intentionally leaves the other group's branch unused.
Add-Type -TypeDefinition $code -Language CSharp -IgnoreWarnings -WarningAction SilentlyContinue

$fieldLease = New-Object "$namespace.FieldRouteLease"
Assert-Led (-not $fieldLease.Check(1000, 0)) 'field route is unconfirmed before the first beacon'
Assert-Led ($fieldLease.Check(25000, 1000)) 'field route survives a 24-second HTTPS/beacon gap'
Assert-Led ($fieldLease.Check(31000, 1000)) 'field route remains confirmed at the 30-second deadline'
Assert-Led (-not $fieldLease.Check(31001, 1000)) 'field route expires when gateway beacons stop'
Assert-Led ($fieldLease.Check(40000, 39000)) 'a fresh gateway beacon restores route confirmation'
Assert-Led ($fieldLease.Check(100, [uint32]4294967040)) 'field route age crosses millis rollover'
Assert-Led (-not $fieldLease.Check(30000, [uint32]4294967040)) 'field route expiry crosses millis rollover'
Assert-Led ($fieldLease.Relay(2000, 1000, 2, 3)) 'heartbeat can move from hop three toward hop two'
Assert-Led ($fieldLease.Relay(2000, 1000, 1, 2)) 'heartbeat can move from hop two toward hop one'
Assert-Led (-not $fieldLease.Relay(2000, 1000, 1, 1)) 'same-distance peers cannot multiply routine heartbeat traffic'
Assert-Led (-not $fieldLease.Relay(2000, 1000, 3, 2)) 'heartbeat cannot move away from the gateway'
Assert-Led (-not $fieldLease.Relay(40000, 1000, 1, 2)) 'expired routes cannot relay routine heartbeats'
Assert-Led (-not $fieldLease.Relay(2000, 1000, 1, 0)) 'unknown-route heartbeats remain direct discovery only'
$gatewayLease = New-Object "$namespace.GatewayRouteLease"
Assert-Led (-not $gatewayLease.Check(1000, 0, $true)) 'router association alone cannot confirm API access'
Assert-Led ($gatewayLease.Check(31000, 1000, $true)) 'gateway confirms a recent API success'
Assert-Led (-not $gatewayLease.Check(31001, 1000, $true)) 'gateway API confirmation expires'
Assert-Led (-not $gatewayLease.Check(2000, 1000, $false)) 'router disconnection invalidates a recent API success'
Assert-Led ($gatewayLease.Check(100, [uint32]4294967040, $true)) 'gateway API age crosses millis rollover'
Assert-Led (-not $gatewayLease.Check(30000, [uint32]4294967040, $true)) 'gateway API expiry crosses millis rollover'
Write-Output 'PASS actual route freshness, delayed beacons, Wi-Fi loss, recovery, and millis rollover'

foreach ($n in 2..10) {
    $threeLeds = $n -ge 2 -and $n -le 10
    $statusPin = if ($threeLeds) { 32 } else { 25 }
    $node = New-Object "$namespace.Node$n"
    $node.Tick(100, $false, $false, $false)
    Assert-Led ($node.Read($statusPin) -eq 0) "NODE_$n isolated status OFF"
    if ($threeLeds) {
        Assert-Led ($node.Read(25) -eq 0 -and $node.Read(33) -eq 0) "NODE_$n isolated green/yellow/red are all OFF without a pending emergency"
    } else {
        Assert-Led ($node.Read(32) -eq -1 -and $node.Read(33) -eq -1) "NODE_$n uses only GPIO25"
    }

    $node.Tick(1000, $false, $false, $true)
    if ($threeLeds) {
        Assert-Led ($node.Read(32) -eq 0 -and $node.Read(25) -eq 0) "NODE_$n unanswered HELLO or unrecognized RX cannot establish a peer"
    }
    $node.Tick(2000, $false, $true, $false)
    Assert-Led ($node.Read($statusPin) -eq 0) "NODE_$n route without a direct peer cannot light solid"
    if ($threeLeds) {
        Assert-Led ($node.Read(25) -eq 0) "NODE_$n route information cannot establish a direct peer"
    }
    $node.Tick(3000, $true, $false, $false)
    if ($threeLeds) {
        Assert-Led ($node.Read(25) -eq 1) "NODE_$n confirmed peer lights green even without a gateway route"
    }
    $before = $node.Read($statusPin)
    $node.Tick(3600, $true, $false, $false)
    Assert-Led ($node.Read($statusPin) -ne $before) "NODE_$n peer without route blinks every 600 ms"
    $node.Tick(4000, $true, $true, $false)
    Assert-Led ($node.Read($statusPin) -eq 1) "NODE_$n peer plus confirmed route is solid"
    $node.Tick(5000, $true, $true, $true)
    Assert-Led ($node.Read($statusPin) -eq 1) "NODE_$n traffic cannot override a confirmed route"
    $node.Tick(5125, $true, $true, $false)
    Assert-Led ($node.Read($statusPin) -eq 1) "NODE_$n confirmed route stays solid throughout radio activity"
    $node.Tick(5700, $true, $true, $false)
    Assert-Led ($node.Read($statusPin) -eq 1) "NODE_$n activity expires and returns to solid"
    $node.Tick(6000, $true, $false, $false)
    $before = $node.Read($statusPin)
    $node.Tick(6600, $true, $false, $false)
    Assert-Led ($node.Read($statusPin) -ne $before) "NODE_$n expired route returns to slow blink"
    $node.Tick(6700, $true, $true, $false)
    Assert-Led ($node.Read($statusPin) -eq 1) "NODE_$n route recovery returns to solid"
    if ($threeLeds) {
        $node.Tick(6800, $false, $true, $false)
        Assert-Led ($node.Read(25) -eq 0 -and $node.Read(32) -eq 0) "NODE_$n peer expiry turns green/yellow OFF despite stale route information"
    }

    if ($threeLeds) {
        $node.Pending($true)
        $node.Tick(7000, $false, $false, $false)
        Assert-Led ($node.Read(33) -eq 1 -and $node.Read(25) -eq 0) "NODE_$n queued local SOS blinks red without falsely lighting green"
        $node.Tick(7600, $true, $true, $true)
        Assert-Led ($node.Read(33) -eq 0) "NODE_$n transmitted local SOS remains pending, timer independent of traffic"
        $node.Tick(8200, $true, $true, $true)
        Assert-Led ($node.Read(33) -eq 1) "NODE_$n pending emergency keeps blinking during radio activity"
        $node.Pending($false) # The existing matching ACK/rejection handler clears the queue.
        $node.Tick(8300, $true, $true, $true)
        Assert-Led ($node.Read(33) -eq 0) "NODE_$n cleared local queue turns emergency LED OFF immediately"
        $node.Tick(9000, $true, $true, $true)
        Assert-Led ($node.Read(33) -eq 0) "NODE_$n relayed traffic alone cannot create a local emergency"
    }

    foreach ($at in 10000..12000 | Where-Object { $_ % 125 -eq 0 }) {
        $node.Tick([uint32]$at, $true, $true, $true)
        Assert-Led ($node.Read($statusPin) -eq 1) "NODE_$n continuous traffic cannot make a working route blink"
    }

    $burst = New-Object "$namespace.Node$n"
    $burst.Tick(1000, $true, $false, $true)
    $before = $burst.Read($statusPin)
    $burst.Tick(1125, $true, $false, $false)
    Assert-Led ($burst.Read($statusPin) -ne $before) "NODE_$n activity still fast-blinks without a confirmed route"
    $burst.Tick(1700, $true, $false, $false)
    Assert-Led ($burst.Read($statusPin) -eq 0) "NODE_$n expired activity returns to the slow-blink timer"
    $burst.Tick(1800, $true, $false, $false)
    Assert-Led ($burst.Read($statusPin) -eq 1) "NODE_$n missing-route slow blink continues after activity"

    $wrapped = New-Object "$namespace.Node$n"
    $wrapped.Pending($true) # Simulate NVS restoration before the first LED update.
    $wrapped.Tick([uint32]4294967040, $true, $false, $true)
    $before = $wrapped.Read($statusPin)
    $wrapped.Tick([uint32]4294967165, $true, $false, $false)
    Assert-Led ($wrapped.Read($statusPin) -ne $before) "NODE_$n fast blink before clock rollover"
    $wrapped.Tick([uint32]344, $true, $false, $false)
    if ($threeLeds) {
        Assert-Led ($wrapped.Read(33) -eq 0) "NODE_$n emergency timer crosses millis rollover"
    }
    $wrapped.Tick([uint32]444, $true, $true, $false)
    Assert-Led ($wrapped.Read($statusPin) -eq 1) "NODE_$n activity expires across millis rollover"
    if ($threeLeds) {
        $wrapped.Tick([uint32]944, $true, $true, $false)
        Assert-Led ($wrapped.Read(33) -eq 1) "NODE_$n emergency blink continues after rollover"
    }
    Write-Output "PASS NODE_$n LED states, recovery, timing, and millis rollover"
}

$node = New-Object "$namespace.Node1"
Assert-Led ($node.Read(25) -eq 0 -and $node.Read(32) -eq 0 -and $node.Read(33) -eq 0) 'gateway initializes all three LEDs OFF'
$node.Tick(100, $false, $false, $false)
Assert-Led ($node.Read(25) -eq 0 -and $node.Read(33) -eq 0) 'gateway without a peer or local report keeps green/red OFF'
$node.Tick(700, $false, $false, $false)
$before = $node.Read(32)
$node.Tick(1300, $false, $false, $false)
Assert-Led ($node.Read(32) -ne $before) 'gateway yellow slowly blinks without confirmed API access, even without a peer'
$node.Tick(2000, $false, $true, $false)
Assert-Led ($node.Read(25) -eq 0 -and $node.Read(32) -eq 1) 'gateway API availability cannot establish a direct peer'
$node.Tick(2100, $true, $false, $false)
$before = $node.Read(32)
Assert-Led ($node.Read(25) -eq 1) 'gateway green reflects a fresh peer independently of server availability'
$node.Tick(2700, $true, $false, $false)
Assert-Led ($node.Read(32) -ne $before) 'gateway yellow slowly blinks with a peer but no API route'
$node.Tick(3000, $true, $true, $false)
Assert-Led ($node.Read(25) -eq 1 -and $node.Read(32) -eq 1) 'gateway green and yellow are solid with a peer and confirmed API'
$node.Tick(4000, $true, $true, $true)
Assert-Led ($node.Read(32) -eq 1) 'gateway radio activity cannot override confirmed API access'
$node.Tick(4125, $true, $true, $false)
Assert-Led ($node.Read(32) -eq 1 -and $node.Read(25) -eq 1) 'gateway yellow stays solid during activity while API access is confirmed'
$node.Tick(4700, $true, $true, $false)
Assert-Led ($node.Read(32) -eq 1) 'gateway yellow returns to confirmed API state after activity'
$node.Tick(5000, $false, $true, $false)
Assert-Led ($node.Read(25) -eq 0 -and $node.Read(32) -eq 1) 'gateway peer expiry changes green independently of API availability'
$node.Tick(6000, $true, $false, $false)
$before = $node.Read(32)
$node.Tick(6600, $true, $false, $false)
Assert-Led ($node.Read(32) -ne $before) 'gateway API expiry returns yellow to slow blink'
$node.Tick(6700, $true, $true, $false)
Assert-Led ($node.Read(25) -eq 1 -and $node.Read(32) -eq 1) 'gateway recovers automatically after successful API communication'
$node.Pending($true)
$node.Tick(7000, $false, $true, $false)
Assert-Led ($node.Read(33) -eq 1 -and $node.Read(25) -eq 0) 'gateway local emergency can be pending without a radio peer'
$node.Tick(7600, $true, $true, $true)
Assert-Led ($node.Read(33) -eq 0) 'gateway emergency timer is independent of activity and API success'
$node.Tick(8200, $true, $false, $true)
Assert-Led ($node.Read(33) -eq 1) 'gateway emergency stays pending while API access fails'
$node.Pending($false)
$node.Tick(8300, $true, $true, $true)
Assert-Led ($node.Read(33) -eq 0) 'gateway clearing local queue after ACK stops the red indicator'
$node.Tick(9000, $true, $true, $true)
Assert-Led ($node.Read(33) -eq 0) 'gateway forwarding field-node traffic cannot create a local pending indicator'
foreach ($at in 10000..12000 | Where-Object { $_ % 125 -eq 0 }) {
    $node.Tick([uint32]$at, $true, $true, $true)
    Assert-Led ($node.Read(32) -eq 1) 'gateway continuous traffic cannot make confirmed API access blink'
}
$burst = New-Object "$namespace.Node1"
$burst.Tick(1000, $false, $false, $true)
$before = $burst.Read(32)
$burst.Tick(1125, $false, $false, $false)
Assert-Led ($burst.Read(32) -ne $before) 'gateway activity still fast-blinks while API access is unconfirmed'
$burst.Tick(1700, $false, $false, $false)
Assert-Led ($burst.Read(32) -eq 0) 'gateway expired activity returns to the slow-blink timer'
$burst.Tick(1800, $false, $false, $false)
Assert-Led ($burst.Read(32) -eq 1) 'gateway missing-API slow blink continues after activity'
$wrapped = New-Object "$namespace.Node1"
$wrapped.Pending($true)
$wrapped.Tick([uint32]4294967040, $true, $false, $true)
Assert-Led ($wrapped.Read(33) -eq 1) 'gateway restores its pending indication from persistent local state'
$before = $wrapped.Read(32)
$wrapped.Tick([uint32]4294967165, $true, $false, $false)
Assert-Led ($wrapped.Read(32) -ne $before) 'gateway yellow activity timer before rollover'
$wrapped.Tick([uint32]344, $true, $false, $false)
Assert-Led ($wrapped.Read(33) -eq 0) 'gateway independent red timer crosses millis rollover'
$wrapped.Tick([uint32]444, $true, $true, $false)
Assert-Led ($wrapped.Read(25) -eq 1 -and $wrapped.Read(32) -eq 1) 'gateway activity expires across millis rollover'
$wrapped.Tick([uint32]944, $true, $true, $false)
Assert-Led ($wrapped.Read(33) -eq 1) 'gateway red keeps blinking after rollover'
Write-Output 'PASS NODE_1 independent green/yellow/red indicators, recovery, local emergency, and millis rollover'

$guardType = [type]("$namespace.AckGuard")
Assert-Led ($guardType::Accept('NODE_3-2', 'NODE_3-2', 'NODE_3-2', 'NODE_1', '2026-10-18T12:00:00Z', 'ACKNOWLEDGED')) 'matching responder ACK accepted'
Assert-Led ($guardType::Accept('NODE_3-2', 'NODE_3-2', 'NODE_3-2', 'NODE_1', '2026-10-18T12:00:00Z', 'REJECTED')) 'matching final rejection accepted'
Assert-Led (-not $guardType::Accept('NODE_3-2', 'NODE_3-2', 'NODE_3-1', 'NODE_1', '2026-10-18T12:00:00Z', 'ACKNOWLEDGED')) 'old/different report cannot clear pending emergency'
Assert-Led (-not $guardType::Accept('NODE_3-2', 'NODE_3-2', 'NODE_3-2', 'NODE_4', '2026-10-18T12:00:00Z', 'ACKNOWLEDGED')) 'wrong gateway cannot clear pending emergency'
Assert-Led (-not $guardType::Accept('NODE_3-2', 'NODE_3-2', 'NODE_3-2', 'NODE_1', '', 'ACKNOWLEDGED')) 'incomplete response cannot clear pending emergency'
Assert-Led (-not $guardType::Accept('NODE_3-2', 'NODE_3-2', 'NODE_3-2', 'NODE_1', '2026-10-18T12:00:00Z', 'SUBMITTED')) 'non-final status cannot clear pending emergency'
Write-Output 'PASS actual matching ACK/rejection condition and invalid-response cases'
