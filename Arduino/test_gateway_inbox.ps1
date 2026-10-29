# Exercise the actual gateway inbox and cooperative HTTP wait with a fake clock.
$ErrorActionPreference = 'Stop'
$source = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'Arduino\GatewayNode1\GatewayNode1.ino') -Raw
function Read-TestFunction([string]$name) {
    $match = [regex]::Match($source, '(?ms)^(?:void|bool)\s+' + $name + '\([^)]*\)\s*\{.*?^\}')
    if (-not $match.Success) { throw "Missing runtime function: $name" }
    $body = $match.Value.Replace('const ', '').Replace('unsigned long', 'uint').Replace('String(node)', 'node.ToString()')
    $body = $body.Replace('String&', 'string').Replace('nullptr', 'null').Replace('GatewayRadioItem*', 'GatewayRadioItem').Replace('GatewayRadioItem&', 'GatewayRadioItem')
    $body = [regex]::Replace($body, '\bString\b', 'string')
    $body = $body.Replace('item->', 'item.').Replace('= &', '= ').Replace('.c_str()', '').Replace('.startsWith(', '.StartsWith(')
    $body = $body.Replace('!item.packet.length()', 'item.packet.Length == 0').Replace('item.packet.length()', 'item.packet.Length > 0')
    $body = $body.Replace('!packet.length()', 'packet.Length == 0').Replace('packet.length()', 'packet.Length > 0')
    $body = $body.Replace('!prefs.putString(key, packet)', 'prefs.putString(key, packet) == 0')
    $body = $body.Replace('if (!item)', 'if (item == null)')
    return $body
}
$functions = @('queueGatewayPacket','restoreGatewayInbox','forwardQueuedSosIfNeeded','serviceGatewayRadio') | ForEach-Object { Read-TestFunction $_ }
$wait = [regex]::Match($source, '(?ms)^  while \(xQueueReceive\(apiResultQueue, &completed, 0\) != pdTRUE\) \{.*?^  \}')
if (-not $wait.Success) { throw 'Cooperative HTTP wait is missing.' }
$waitBody = $wait.Value.Replace('while (xQueueReceive(apiResultQueue, &completed, 0) != pdTRUE)', 'while (!ApiComplete())')
$ns = 'InboxTest_' + [guid]::NewGuid().ToString('N')
Add-Type -Language CSharp -TypeDefinition @"
using System;
using System.Collections.Generic;
namespace $ns {
public class GatewayRadioItem { public string packet=""; public int rssi; public float snr; public uint attemptedAtMs, receivedAtMs; public bool attempted; }
public class FakePrefs {
    public static Dictionary<string,string> Store = new Dictionary<string,string>();
    public static bool Fail;
    public uint putString(string key,string value) { if(Fail)return 0; Store[key]=value; return (uint)value.Length; }
    public string getString(string key,string fallback) { return Store.ContainsKey(key)?Store[key]:fallback; }
    public void remove(string key) { Store.Remove(key); }
}
public class Dummy { public void println(string s) {} public void processNextRequest() {} }
public class FakeMeshRadio { public void tick(uint now) {} }
public class Harness {
    public uint clock=0, meshInvalidFrames=0;
    public int nextSosNode=2, Calls=0, RadioPasses=0, LedPasses=0;
    public bool Accepted=false;
    public GatewayRadioItem[] sosInbox=new GatewayRadioItem[11], heartbeatInbox=new GatewayRadioItem[11], probeInbox=new GatewayRadioItem[11];
    FakePrefs prefs=new FakePrefs(); Dummy Serial=new Dummy(), dnsServer=new Dummy();
    FakeMeshRadio meshRadio=new FakeMeshRadio();
    public Harness() { for(int i=0;i<=10;i++){ sosInbox[i]=new GatewayRadioItem(); heartbeatInbox[i]=new GatewayRadioItem(); probeInbox[i]=new GatewayRadioItem(); } }
    uint millis(){ return clock; }
    bool serverRouteAvailable(){ return true; }
    string getField(string s,int n){ var f=s.Split('|'); return n<f.Length?f[n]:""; }
    int meshNodeNumber(string s){ for(int n=1;n<=10;n++)if(s=="NODE_"+n)return n;return 0; }
    bool meshAcceptSos(string s,bool remember){ return s.EndsWith("|signed"); }
    bool forwardSosToLaravel(string s,int r,float snr){ Calls++; return Accepted; }
    void sendGatewayBeaconIfNeeded(){}
    void updateLedIndicators(){ LedPasses++; }
    void delay(int ms){ clock+=(uint)ms; }
    bool ApiComplete(){ return clock>=14000; }
    void handleIncomingLoRa(){
        RadioPasses++;
        if(clock==100)queueGatewayPacket("SOS|NODE_4-42|NODE_4|signed",-44,8);
        if(clock==200)queueGatewayPacket("HB|NODE_3|first",-70,8);
        if(clock==500)queueGatewayPacket("SOS|NODE_3-42|NODE_3|signed",-70,8);
        if(clock==800)queueGatewayPacket("HB|NODE_3|latest",-70,8);
    }
    $($functions -join "`n")
    public void Wait(){ $waitBody }
    public bool Flush(){ return forwardQueuedSosIfNeeded(); }
    public void Restore(){ restoreGatewayInbox(); }
    public void Queue(string packet){ queueGatewayPacket(packet,-50,8); }
}
}
"@
function Check([bool]$ok,[string]$description) { if(-not $ok){throw $description} }
$type = [type]("$ns.FakePrefs")
$h = New-Object "$ns.Harness"
$h.Wait()
Check ($h.RadioPasses -ge 6000 -and $h.LedPasses -eq $h.RadioPasses) 'Radio/LED service stopped during a 14-second HTTPS request'
Check ($h.Calls -eq 0) 'Radio reception recursively issued an HTTP request'
Check ($h.sosInbox[3].packet -and $h.sosInbox[4].packet) 'Concurrent NODE_3/NODE_4 reports were lost'
Check ($h.heartbeatInbox[3].packet -eq 'HB|NODE_3|latest') 'Heartbeat coalescing did not retain the latest packet'
Check ($type::Store.Count -eq 2) 'Received SOS was not persisted before HTTPS submission'
$h.Queue('SOS|NODE_3-99|NODE_3|signed')
Check ($h.sosInbox[3].packet -eq 'SOS|NODE_3-42|NODE_3|signed') 'An undelivered SOS was overwritten'
$h.Queue('SOS|NODE_5-42|NODE_5|unsigned')
Check (-not $h.sosInbox[5].packet) 'Origin verification failure was queued'
$null = $h.Flush()
Check ($h.sosInbox[3].packet -and $type::Store.Count -eq 2) 'Failed server request discarded a pending SOS'
$h.Accepted = $true
$null = $h.Flush()
Check (-not $h.sosInbox[4].packet -and $h.sosInbox[3].packet) 'One failing node starved a different node'
$h.clock = 19001
$null = $h.Flush()
Check (-not $h.sosInbox[3].packet -and $type::Store.Count -eq 0) 'Accepted SOS did not clear its durable inbox entry'
$h.Queue('SOS|NODE_2-1|NODE_2|signed')
$rebooted = New-Object "$ns.Harness"
$rebooted.Restore()
Check ($rebooted.sosInbox[2].packet -eq 'SOS|NODE_2-1|NODE_2|signed') 'Reboot lost the durable inbox'
$type::Fail = $true
$rebooted.Queue('SOS|NODE_6-1|NODE_6|signed')
Check (-not $rebooted.sosInbox[6].packet) 'Flash failure was incorrectly treated as a persisted report'
Write-Output 'PASS actual HTTP wait keeps radio/LED service active; per-node inbox, coalescing, retries, fairness, persistence and flash failure'
