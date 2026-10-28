@php
    $telemetryGuide = \App\Support\LoraTelemetryGuide::class;
@endphp
<section class="lora-telemetry-legend" aria-label="RTT and radio signal legend" data-lora-telemetry-legend>
    <div class="lora-telemetry-legend-grid">
        <article>
            <h3>Delay (RTT) <span>Lower ms = faster</span></h3>
            <ul>
                <li><span class="tag green">Fast</span> &le; {{ number_format($telemetryGuide::RTT_FAST_MAX) }} ms</li>
                <li><span class="tag amber">Moderate</span> {{ number_format($telemetryGuide::RTT_FAST_MAX + 1) }}&ndash;{{ number_format($telemetryGuide::RTT_MODERATE_MAX) }} ms</li>
                <li><span class="tag red">Slow</span> &gt; {{ number_format($telemetryGuide::RTT_MODERATE_MAX) }} ms</li>
            </ul>
            <p>Round trip to the website, including relays and the server link.</p>
        </article>
        <article>
            <h3>Radio signal (RSSI) <span>Less negative dBm = stronger</span></h3>
            <ul>
                <li><span class="tag green">Strong</span> &ge; {{ $telemetryGuide::RSSI_STRONG_MIN }} dBm</li>
                <li><span class="tag amber">Fair</span> {{ $telemetryGuide::RSSI_FAIR_MIN }} to &lt; {{ $telemetryGuide::RSSI_STRONG_MIN }} dBm</li>
                <li><span class="tag red">Weak</span> &lt; {{ $telemetryGuide::RSSI_FAIR_MIN }} dBm</li>
            </ul>
            <p>-47 dBm is stronger than -110 dBm. Last hop at the gateway; check SNR in node details.</p>
        </article>
    </div>
    <p class="lora-telemetry-legend-note">Approximate bands; check SNR and route conditions too. <strong>Last reading</strong> = historical. <strong>Expired</strong> = RTT older than 120 sec. <strong>&mdash;</strong> = no reading.</p>
</section>
