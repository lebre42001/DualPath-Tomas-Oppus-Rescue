@extends('layouts.app')

@php
    $isCivilian = auth()->user()?->isCivilian() ?? false;
    $delivered = ($transmission['status'] ?? 'Queued') === 'Delivered';
    $transportMode = strtoupper($report->transmission_type ?: 'online');
    $channelLabel = $transmission['channel'] ?? ($report->channel ?: 'Internet');
    $gatewayLabel = $transmission['gateway'] ?? 'Gateway unavailable';
    $nextRetry = $transmission['next_retry'] ?? 'Not required';
    $coordinates = $report->latitude !== null && $report->longitude !== null
        ? number_format((float) $report->latitude, 6).', '.number_format((float) $report->longitude, 6)
        : $report->location_text;
    $payloadLabel = $report->transmission_type === 'lora' ? 'Compact LoRa payload' : 'Full online emergency payload';
    $mediaLabel = $report->transmission_type === 'lora'
        ? 'LoRa fallback sends compact details only, so media is excluded from the payload.'
        : ($report->evidence_path ? 'Online delivery included the attached evidence file.' : 'Online delivery completed without an attached evidence file.');
    $selfieLabel = $report->reporter_selfie_path
        ? 'Verification selfie was stored with the report for admin review.'
        : 'No verification selfie is attached to this web-submitted report.';
    $statusTone = $delivered ? 'green' : 'amber';
@endphp

@section('title', 'Transmission Status')
@section('page_label', $isCivilian ? 'Transmission Review' : 'Connectivity')
@section('page_heading', $isCivilian ? 'Your Transmission Status' : 'Transmission Status')
@section('page_subheading', $isCivilian ? 'Check how your report was delivered through online or LoRa transport, and review what information reached the admin receiver side.' : 'Review gateway delivery, transport mode, and payload details for this incident transmission.')

@section('hero')
    <section class="hero-card" style="{{ $isCivilian ? 'background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(230,244,255,.95));border-color:rgba(32,104,174,.16);' : '' }}">
        <div class="hero-grid" style="grid-template-columns:minmax(0,1.08fr) minmax(280px,.92fr);">
            <div class="hero-copy">
                <p class="eyebrow">{{ $isCivilian ? 'Transport Result' : 'Connectivity Review' }}</p>
                <h2>{{ $delivered ? 'Transmission delivered successfully.' : 'Transmission is still queued for delivery.' }}</h2>
                <p>
                    @if ($isCivilian)
                        Your report <strong>{{ $report->report_code }}</strong> was processed through the <strong>{{ $transportMode }}</strong> path using
                        <strong>{{ $gatewayLabel }}</strong>. This page shows whether your details, location, and evidence were sent successfully.
                    @else
                        Report <strong>{{ $report->report_code }}</strong> is using the <strong>{{ $transportMode }}</strong> transport path through
                        <strong>{{ $gatewayLabel }}</strong>. Use this view to confirm delivery state, payload type, and fallback readiness.
                    @endif
                </p>
                <div class="hero-actions">
                    <a href="{{ route('reports.show', $report) }}" class="btn btn-primary">{{ $isCivilian ? 'Back To My Report' : 'Back To Incident Details' }}</a>
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">{{ $isCivilian ? 'Return to report history' : 'Return To Incident Feed' }}</a>
                </div>
            </div>
            <div class="hero-metrics">
                <article class="metric-card">
                    <span>Delivery status</span>
                    <strong>{{ $transmission['status'] }}</strong>
                    <p>{{ $delivered ? 'The report reached the active gateway and no retry is needed.' : 'The report is waiting for the next retry window.' }}</p>
                </article>
                <article class="metric-card">
                    <span>Transmission mode</span>
                    <strong>{{ $transportMode }}</strong>
                    <p>{{ $channelLabel }}</p>
                </article>
                <article class="metric-card">
                    <span>Gateway</span>
                    <strong>{{ $gatewayLabel }}</strong>
                    <p>{{ $delivered ? 'Gateway acknowledged the report payload.' : 'Gateway will retry based on the current queue state.' }}</p>
                </article>
                <article class="metric-card">
                    <span>Retry window</span>
                    <strong>{{ $nextRetry }}</strong>
                    <p>{{ $delivered ? 'No additional retry is required.' : 'Next reconnect attempt for the current payload.' }}</p>
                </article>
            </div>
        </div>
    </section>
@endsection

@section('content')
    <section class="dual-grid">
        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Delivery Summary</p>
                    <h2 class="panel-title">Transport details</h2>
                    <p class="section-copy">{{ $isCivilian ? 'These are the main delivery details for the emergency report you submitted.' : 'Review the transmission metadata attached to this incident record.' }}</p>
                </div>
            </div>
            <div class="stack">
                <div class="detail-card">
                    <strong>Current status</strong>
                    <div class="tag-row">
                        <span class="tag {{ $statusTone }}">{{ $transmission['status'] }}</span>
                        <span class="tag blue">{{ $transportMode }}</span>
                    </div>
                    <p class="detail-copy">{{ $delivered ? 'The gateway confirmed receipt of the current payload.' : 'The report is queued and will attempt delivery again using the configured transport path.' }}</p>
                </div>
                <div class="detail-card"><strong>Gateway</strong><p>{{ $gatewayLabel }}</p></div>
                <div class="detail-card"><strong>Payload type</strong><p>{{ $payloadLabel }}</p></div>
                <div class="detail-card"><strong>Media handling</strong><p>{{ $mediaLabel }}</p></div>
                <div class="detail-card"><strong>Verification status</strong><p>{{ $selfieLabel }}</p></div>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Payload Context</p>
                    <h2 class="panel-title">{{ $isCivilian ? 'What was sent' : 'Transmission context' }}</h2>
                    <p class="section-copy">{{ $isCivilian ? 'Review the location and incident details attached to your outgoing report.' : 'Use this information to confirm that the submitted payload matches the operational record.' }}</p>
                </div>
            </div>
            <div class="stack">
                <div class="detail-card"><strong>Report code</strong><p>{{ $report->report_code }}</p></div>
                <div class="detail-card"><strong>Incident type</strong><p>{{ $report->incident_type }}</p></div>
                <div class="detail-card"><strong>Location text</strong><p>{{ $report->location_text }}</p></div>
                <div class="detail-card"><strong>Coordinates</strong><p>{{ $coordinates }}</p></div>
                <div class="detail-card"><strong>Next retry</strong><p>{{ $nextRetry }}</p></div>
                <div class="detail-card"><strong>Description</strong><p>{{ $report->description }}</p></div>
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-heading">
                <p class="panel-kicker">Next Step</p>
                <h2 class="panel-title">{{ $isCivilian ? 'Continue your report flow' : 'Continue the admin workflow' }}</h2>
                <p class="section-copy">{{ $isCivilian ? 'Move between your report details, AI severity, and report history without leaving the civilian workflow.' : 'Return to incident review, AI severity, or the admin feed.' }}</p>
            </div>
        </div>
        <div class="preview-grid">
            <a href="{{ route('reports.show', $report) }}" class="detail-card">
                <strong>{{ $isCivilian ? 'Back To My Report' : 'Open incident details' }}</strong>
                <p>{{ $isCivilian ? 'Review the report, evidence, and current admin status.' : 'Review evidence, assignment, and coordination status.' }}</p>
            </a>
            <a href="{{ route('reports.severity', $report) }}" class="detail-card">
                <strong>{{ $isCivilian ? 'Open AI severity result' : 'Open AI severity analysis' }}</strong>
                <p>{{ $isCivilian ? 'Review how the system rated the seriousness of your report.' : 'Review severity confidence and dispatch advice.' }}</p>
            </a>
            <a href="{{ route('reports.index') }}" class="detail-card">
                <strong>{{ $isCivilian ? 'Return to report history' : 'Return To Incident Feed' }}</strong>
                <p>{{ $isCivilian ? 'Go back to your submitted reports and monitor their progress.' : 'Go back to the main monitoring feed.' }}</p>
            </a>
        </div>
    </section>
@endsection
