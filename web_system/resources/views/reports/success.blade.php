@extends('layouts.app')

@php
    $isCivilian = auth()->user()?->isCivilian() ?? false;
    $tone = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'red',
        'Serious', 'Needs review' => 'amber',
        default => 'green',
    };
    $statusDisplay = static fn (string $status): array => match ($status) {
        'received' => ['label' => 'Pending admin response', 'tone' => 'amber'],
        'acknowledged' => ['label' => 'Under admin review', 'tone' => 'blue'],
        'dispatched' => ['label' => 'Admin response started', 'tone' => 'blue'],
        'responding' => ['label' => 'Responded', 'tone' => 'blue'],
        'resolved' => ['label' => 'Resolved', 'tone' => 'green'],
        'rejected' => ['label' => 'Cancelled', 'tone' => 'red'],
        default => ['label' => ucfirst($status), 'tone' => 'neutral'],
    };
    $currentStatus = $statusDisplay($report->status);
@endphp

@section('title', 'Report Submitted')
@section('page_label', 'Report Confirmation')
@section('page_heading', $isCivilian ? 'Emergency Report Sent' : 'Incident Submitted Successfully')
@section('page_subheading', $isCivilian ? 'Your civilian report was accepted by the system and is now ready for admin review.' : 'The incident was accepted by the system and is now visible for admin monitoring and coordination.')
@section('hide_topbar', $isCivilian ? 'true' : 'false')

@section('hero')
    @if ($isCivilian)
        <section class="hero-card civilian-success-hero">
            <div class="civilian-success-layout">
                <div class="hero-copy">
                    <p class="eyebrow">Emergency Report Sent</p>
                    <h2>Your emergency report was sent successfully.</h2>
                    <p>
                        Reference code: <strong class="incident-code">{{ $report->report_code }}</strong>.
                        Admin receivers can now review your photo, verification selfie, GPS, and short description.
                    </p>
                    <div class="hero-actions">
                        <a href="{{ route('reports.show', $report) }}" class="btn btn-primary">Open My Report</a>
                        <a href="{{ route('reports.index') }}" class="btn btn-secondary">Report History</a>
                    </div>
                </div>

                <div class="civilian-success-cards" aria-label="Submitted report summary">
                    <article class="civilian-success-card">
                        <span>Report code</span>
                        <strong>{{ $report->report_code }}</strong>
                    </article>
                    <article class="civilian-success-card">
                        <span>Status</span>
                        <strong>{{ $currentStatus['label'] }}</strong>
                    </article>
                    <article class="civilian-success-card">
                        <span>Submitted</span>
                        <strong>{{ optional($report->created_at)->format('M d, h:i A') ?? 'Just now' }}</strong>
                    </article>
                    <article class="civilian-success-card">
                        <span>Location</span>
                        <strong>{{ $report->location_text }}</strong>
                    </article>
                </div>
            </div>
        </section>
    @else
        <section class="hero-card">
            <div class="hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow">Report Confirmation</p>
                    <h2>Incident submitted successfully.</h2>
                    <p>
                        Report <strong>{{ $report->report_code }}</strong> was accepted by the system and is now visible in the incident feed for admin receivers and dashboard monitoring.
                    </p>
                    <div class="hero-actions">
                        <a href="{{ route('reports.show', $report) }}" class="btn btn-primary">Open Incident Details</a>
                        <a href="{{ route('dashboard') }}" class="btn btn-secondary">Return To Dashboard</a>
                    </div>
                </div>
                <div class="hero-metrics">
                    <article class="metric-card"><span>Report code</span><strong>{{ $report->report_code }}</strong><p>Reference code for your submitted incident.</p></article>
                    <article class="metric-card"><span>Severity</span><strong>{{ $report->severity_display }}</strong><p>{{ $report->operational_severity === null ? 'Awaiting responder assessment.' : 'Current assessment; photos remain supporting evidence.' }}</p></article>
                    <article class="metric-card"><span>Transmission</span><strong>{{ strtoupper($report->transmission_type ?: 'online') }}</strong><p>{{ $report->channel ?: 'Internet' }}</p></article>
                    <article class="metric-card"><span>Location</span><strong>{{ $report->location_text }}</strong><p>Submitted location text for this report.</p></article>
                </div>
            </div>
        </section>
    @endif
@endsection

@section('content')
    <div hidden data-report-draft-clear-on-success></div>
    @if ($isCivilian)
        <section class="civilian-success-flow">
            <article class="panel">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Summary</p>
                        <h2 class="panel-title">What happens next?</h2>
                        <p class="section-copy">Your report is now in the admin receiver queue. Keep this code if admins ask for a reference.</p>
                    </div>
                </div>
                <div class="stack">
                    <div class="detail-card"><strong>Report code</strong><p>{{ $report->report_code }}</p></div>
                    <div class="detail-card"><strong>Current status</strong><span class="tag {{ $currentStatus['tone'] }}">{{ $currentStatus['label'] }}</span></div>
                    <div class="detail-card"><strong>Location</strong><p>{{ $report->location_text }}</p></div>
                    <div class="detail-card"><strong>Submitted</strong><p>{{ optional($report->created_at)->format('M d, Y h:i A') ?? 'Just now' }}</p></div>
                </div>
            </article>

            <article class="panel">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Next Steps</p>
                        <h2 class="panel-title">Track your report</h2>
                        <p class="section-copy">Open your report to check its status, or return to your report history.</p>
                    </div>
                </div>
                <div class="hero-actions">
                    <a href="{{ route('reports.show', $report) }}" class="btn btn-primary">Open My Report</a>
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">Return to report history</a>
                </div>
            </article>
        </section>
    @else
    <section class="dual-grid">
        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Summary</p>
                    <h2 class="panel-title">Submission result</h2>
                    <p class="section-copy">{{ $isCivilian ? 'Review the main details of the report you just sent.' : 'Review the saved report details before continuing through the admin workflow.' }}</p>
                </div>
            </div>
            <div class="stack">
                <div class="detail-card"><strong>Transmission type</strong><p>{{ strtoupper($report->transmission_type ?: 'online') }}</p></div>
                <div class="detail-card"><strong>Severity</strong><span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span></div>
                <div class="detail-card"><strong>Location</strong><p>{{ $report->location_text }}</p></div>
                @if ($report->photo_relevance_label !== null || $report->photo_relevance_confidence !== null)
                    <div class="detail-card"><strong>Historical photo relevance</strong><p>{{ $report->photo_relevance_label ? ucwords(str_replace('_', ' ', $report->photo_relevance_label)) : 'Not checked' }}{{ $report->photo_relevance_confidence !== null ? ' - '.$report->photo_relevance_confidence.'%' : '' }}</p></div>
                @endif
                <div class="detail-card"><strong>{{ in_array($report->ai_source, ['python_model', 'image_model'], true) ? 'Historical photo assessment' : 'Severity assessment' }}</strong><p>{{ $report->operational_ai_summary ?: 'No AI summary available.' }}</p></div>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Next Steps</p>
                    <h2 class="panel-title">Continue workflow</h2>
                    <p class="section-copy">{{ $isCivilian ? 'Continue reviewing your own report flow from the civilian side.' : (\App\Models\IncidentReport::manualOnlyMode() ? 'Continue responder severity review and transmission tracking from the admin workflow.' : 'Continue monitoring, AI review, and transmission tracking from the admin workflow.') }}</p>
                </div>
            </div>
            <div class="stack">
                <a href="{{ route('reports.severity', $report) }}" class="detail-card">
                    <strong>Open AI severity analysis</strong>
                    <p>{{ $isCivilian ? 'See how the system evaluated the seriousness of your report.' : 'Review predicted severity and dispatch advice.' }}</p>
                </a>
                <a href="{{ route('reports.transmissions', $report) }}" class="detail-card">
                    <strong>Open transmission status</strong>
                    <p>{{ $isCivilian ? 'Check how your report was delivered through online or LoRa transport.' : 'Check whether the report was delivered through online or LoRa transport.' }}</p>
                </a>
                <a href="{{ route('reports.index') }}" class="detail-card">
                    <strong>{{ $isCivilian ? 'Return to report history' : 'Return to incident feed' }}</strong>
                    <p>{{ $isCivilian ? 'Go back to your civilian report history and see all submitted incidents.' : 'Continue monitoring and coordination from the report queue.' }}</p>
                </a>
            </div>
        </article>
    </section>
    @endif
@endsection
