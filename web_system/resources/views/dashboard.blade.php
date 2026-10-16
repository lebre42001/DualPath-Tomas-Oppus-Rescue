@extends('layouts.app')

@php
    $isCivilian = ($dashboardMode ?? null) === 'civilian' || auth()->user()?->isCivilian();
    $isAccountVerified = ! $isCivilian || (auth()->user()?->hasVerifiedAccount() ?? false);
    $tone = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'red',
        'Serious', 'Needs review', 'Awaiting responder' => 'amber',
        default => 'green',
    };
    $aiSourceLabel = static fn (?string $source): string => match ($source) {
        'text_model' => 'Description AI model',
        'text_model_experimental' => 'Experimental description AI',
        'text_rule_advisory' => 'Word/phrase severity suggestion',
        'text_review' => 'Description awaiting review',
        'text_human_override' => 'Responder severity; text AI advisory',
        'manual_only' => 'Manual responder review required',
        'human_responder' => 'Responder-assigned severity',
        'python_model', 'image_model' => 'Historical photo assessment',
        'description_fallback' => 'Historical description fallback',
        'manual_override' => 'Manual override',
        null, '' => 'Source unavailable',
        default => ucwords(str_replace('_', ' ', $source)),
    };
    $aiStatusLabel = static function ($report): string {
        if ($report->operational_ai_status === 'failed') {
            return 'AI unavailable';
        }

        if ($report->operational_ai_review_required) {
            return 'Admin review needed';
        }

        if ($report->operational_ai_status === 'fallback') {
            return 'Fallback mode';
        }

        if ($report->operational_ai_status === 'complete') {
            return 'Ready for triage';
        }

        return 'Pending AI review';
    };
    $aiStatusTone = static function ($report): string {
        if ($report->operational_ai_status === 'failed' || $report->operational_ai_review_required) {
            return 'red';
        }

        if ($report->operational_ai_status === 'fallback') {
            return 'amber';
        }

        if ($report->operational_ai_status === 'complete') {
            return 'green';
        }

        return 'neutral';
    };
    $confidenceTone = static function ($confidence): string {
        if ($confidence === null) {
            return 'neutral';
        }

        if ($confidence >= 85) {
            return 'green';
        }

        if ($confidence >= 60) {
            return 'amber';
        }

        return 'red';
    };
    $priorityTimer = static function ($report): string {
        if ($report->created_at === null) {
            return 'Just received';
        }

        return max(1, (int) ceil($report->created_at->diffInMinutes(now()))).' min active';
    };
    $adminProcessState = static function ($report): array {
        return match ($report->status) {
            'received' => [
                'label' => 'Pending admin review',
                'tone' => 'amber',
                'class' => 'process-pending',
            ],
            'acknowledged' => [
                'label' => 'Under admin review',
                'tone' => 'blue',
                'class' => 'process-active',
            ],
            'dispatched' => [
                'label' => 'Response started',
                'tone' => 'blue',
                'class' => 'process-active',
            ],
            'responding' => [
                'label' => 'Admin responded',
                'tone' => 'blue',
                'class' => 'process-active',
            ],
            'resolved' => [
                'label' => 'Resolved',
                'tone' => 'green',
                'class' => 'process-complete',
            ],
            'rejected' => [
                'label' => 'Cancelled',
                'tone' => 'red',
                'class' => 'process-cancelled',
            ],
            default => [
                'label' => ucfirst((string) $report->status),
                'tone' => 'neutral',
                'class' => 'process-neutral',
            ],
        };
    };
    $statusDisplayLabel = static fn (string $status): string => match ($status) {
        'received' => 'Pending',
        'acknowledged' => 'Under review',
        'dispatched' => 'Response started',
        'responding' => 'Responded',
        'resolved' => 'Resolved',
        'rejected' => 'Cancelled',
        default => ucfirst($status),
    };
    $statusDisplayTone = static fn (string $status): string => match ($status) {
        'received' => 'amber',
        'resolved' => 'green',
        'rejected' => 'red',
        default => 'blue',
    };
    $civilianStatusSummary = static fn (string $status): string => match ($status) {
        'resolved' => 'The admin receiver marked this report as completed.',
        'rejected' => 'This report was reviewed and closed after admin review.',
        'responding' => 'The admin receiver responded to your report.',
        'dispatched' => 'An admin receiver has started the response workflow.',
        'acknowledged' => 'Your report was acknowledged and is under review.',
        default => 'Your report is in the active monitoring queue.',
    };
    $civilianAttachmentSummary = static function ($report): string {
        $parts = [];

        if ($report->evidence_path) {
            $parts[] = ucfirst($report->evidence_type ?: 'media').' evidence attached';
        }

        if ($report->reporter_selfie_path) {
            $parts[] = 'verification selfie stored';
        }

        if ($parts === []) {
            return 'No evidence or verification selfie is attached to this report.';
        }

        return ucfirst(implode(' and ', $parts)).'.';
    };
    $civilianProgressPercent = static fn (string $status): int => match ($status) {
        'resolved' => 100,
        'responding' => 82,
        'dispatched' => 64,
        'acknowledged' => 42,
        'rejected' => 100,
        default => 20,
    };
    $civilianEstimatedResponse = static function ($report): array {
        return match ($report->status) {
            'rejected' => ['label' => 'No dispatch scheduled', 'copy' => 'This report was reviewed and closed without admin deployment.', 'tone' => 'red'],
            'resolved' => ['label' => 'Response completed', 'copy' => 'Admin activity for this report has already been completed.', 'tone' => 'green'],
            'responding' => ['label' => 'Admin responded', 'copy' => 'An admin receiver has already responded to this incident.', 'tone' => 'green'],
            'dispatched' => ['label' => 'Admin response started', 'copy' => 'The response workflow has started for the incident area.', 'tone' => 'blue'],
            'acknowledged' => ['label' => 'Admin review in progress', 'copy' => 'Your report is under active admin review for assignment.', 'tone' => 'blue'],
            default => ['label' => 'Awaiting admin review', 'copy' => 'Your report is waiting for an admin response update.', 'tone' => 'amber'],
        };
    };
    $mapSearchUrl = static function ($report): string {
        if ($report->latitude !== null && $report->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode($report->latitude.','.$report->longitude);
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode((string) ($report->location_text ?: 'Bontoc Command Center'));
    };
    $dispatchSummary = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'Immediate medical and extraction support with the admin receiver.',
        'Serious' => 'Rapid field assessment and traffic or scene control support.',
        'Needs review' => 'Severity is unassessed. Review the description and evidence before confirming the response.',
        default => 'Verification patrol and scene stabilization if conditions worsen.',
    };
@endphp

@section('title', $isCivilian ? 'Civilian Sender Dashboard' : 'Admin Receiver Dashboard')
@section('page_label', $isCivilian ? 'Civilian Dashboard' : 'Operations')
@section('page_heading', $isCivilian ? 'Civilian Home' : 'Live Monitoring')
@section('page_subheading', $isCivilian ? 'Direct access to report an emergency and check your latest status.' : 'Latest civilian incident submissions, severity, map pins, and admin review actions.')

@section('hero')
    @if ($isCivilian)
        <section class="hero-card civilian-simple-hero">
            <div class="civilian-simple-hero-grid">
                <div class="hero-copy civilian-simple-copy">
                    <p class="eyebrow">Civilian Home</p>
                    <h2>Report or check status.</h2>
                    <p>
                        Signed in as <strong>{{ $user?->name }}</strong>.
                        {{ $isAccountVerified ? 'Use the main action first during an emergency.' : 'Verify your account with live facial verification before sending an emergency report.' }}
                    </p>
                    <div class="hero-actions civilian-simple-actions">
                        <a href="{{ $isAccountVerified ? route('reports.create') : route('account.verify') }}" class="btn btn-primary">{{ $isAccountVerified ? 'Report Emergency' : 'Verify Account First' }}</a>
                        <a href="{{ route('reports.index') }}" class="btn btn-secondary">My Reports</a>
                    </div>
                </div>
                <div class="civilian-simple-stats" aria-label="Your report summary">
                    <article><span>Total</span><strong>{{ $stats['total'] }}</strong></article>
                    <article><span>Active</span><strong>{{ $stats['active'] }}</strong></article>
                    <article><span>Today</span><strong>{{ $stats['today'] }}</strong></article>
                </div>
            </div>
        </section>
    @endif
@endsection

@section('content')
    @if ($isCivilian)
        <section class="civilian-shell civilian-home-compact">
            <section class="civilian-home-card">
                <div class="civilian-home-card-copy">
                    <p class="panel-kicker">Main actions</p>
                    <h2>What do you need?</h2>
                    <p>{{ $isAccountVerified ? 'Use one clear path. Report a new emergency, or check the status of a report you already sent.' : 'Your Send Report button is locked until your civilian account passes live facial verification.' }}</p>
                </div>
                <div class="civilian-home-primary-actions">
                    <a href="{{ $isAccountVerified ? route('reports.create') : route('account.verify') }}" class="btn btn-primary">{{ $isAccountVerified ? 'Report Emergency' : 'Verify Account' }}</a>
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">Check Status</a>
                </div>
                <div class="civilian-home-mini-actions">
                    <a href="{{ route('profile.show') }}">Profile</a>
                    <a href="{{ route('settings.readiness') }}">Device Check</a>
                </div>
            </section>

            <section class="civilian-home-status-row" aria-label="Report summary">
                <article><span>Total Reports</span><strong>{{ $stats['total'] }}</strong></article>
                <article><span>Active</span><strong>{{ $stats['active'] }}</strong></article>
                <article><span>Resolved</span><strong>{{ $stats['resolved'] }}</strong></article>
            </section>

            @unless ($isAccountVerified)
                <section class="panel">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">Account Verification Required</p>
                            <h2 class="panel-title">Unlock Send Report with live facial verification.</h2>
                            <p class="section-copy">This helps prevent prank reports from new accounts. Capture a real-time front-camera selfie once, then emergency reporting unlocks for this civilian account.</p>
                        </div>
                        <a href="{{ route('account.verify') }}" class="btn btn-primary">Verify Account</a>
                    </div>
                </section>
            @endunless

            <section class="panel civilian-latest-panel">
                <div class="panel-head">
                    <div class="panel-heading">
                        <p class="panel-kicker">Latest Status</p>
                        <h2 class="panel-title">Your latest reports</h2>
                    </div>
                    <a href="{{ route('reports.index') }}" class="btn btn-secondary">Open History</a>
                </div>

                <div class="civilian-latest-list">
                    @forelse ($reports->take(3) as $report)
                        @php
                            $statusSummary = match ($report->status) {
                                'resolved' => 'Completed',
                                'rejected' => 'Reviewed',
                                'responding' => 'Responded',
                                'dispatched' => 'Dispatched',
                                'acknowledged' => 'Under review',
                                default => 'Pending',
                            };
                            $statusTone = $statusDisplayTone($report->status);
                            $progressPercent = $civilianProgressPercent($report->status);
                            $estimatedResponse = $civilianEstimatedResponse($report);
                            $evidenceUrl = $report->evidence_path ? route('reports.evidence', $report) : null;
                            $selfieUrl = $report->reporter_selfie_path ? route('reports.selfie', $report) : null;
                            $coordinates = $report->latitude !== null && $report->longitude !== null
                                ? number_format((float) $report->latitude, 6).', '.number_format((float) $report->longitude, 6)
                                : 'Coordinates not available';
                        @endphp
                        <article class="civilian-latest-card" data-civilian-report-row data-civilian-report-title="{{ $report->report_code }} - {{ $report->incident_type }}">
                            <div class="civilian-latest-card-copy">
                                <span class="incident-code">{{ $report->report_code }}</span>
                                <h3>{{ $report->incident_type }}</h3>
                                <p>{{ $report->location_text }}</p>
                                <small>{{ optional($report->created_at)->diffForHumans() ?? 'Just submitted' }}</small>
                            </div>
                            <div class="tag-row">
                                <span class="tag {{ $statusTone }}">{{ $statusSummary }}</span>
                            </div>
                            <button type="button" class="btn btn-primary" data-civilian-report-modal-trigger>View Details</button>

                            <div class="civilian-report-modal-source" data-civilian-report-modal-source hidden>
                                <article class="civilian-report-modal-card">
                                    <div class="civilian-report-modal-hero">
                                        <div>
                                            <span class="incident-code">{{ $report->report_code }}</span>
                                            <h3>{{ $report->incident_type }}</h3>
                                            <p>{{ $civilianStatusSummary($report->status) }}</p>
                                        </div>
                                        <div class="tag-row">
                                            <span class="tag {{ $statusTone }}">{{ $statusDisplayLabel($report->status) }}</span>
                                            <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                            <span class="tag neutral">{{ strtoupper($report->transmission_type ?: 'online') }}</span>
                                        </div>
                                    </div>

                                    <div class="civilian-progress-block">
                                        <div class="civilian-progress-track">
                                            <div class="civilian-progress-fill {{ $report->status === 'resolved' ? 'is-complete' : ($report->status === 'rejected' ? 'is-rejected' : '') }}" style="width:{{ $progressPercent }}%;"></div>
                                        </div>
                                        <span class="tag blue">{{ $progressPercent }}% complete</span>
                                    </div>

                                    <div class="civilian-report-modal-grid">
                                        <div class="detail-card">
                                            <strong>Submitted location</strong>
                                            <p>{{ $report->location_text }}</p>
                                            <p><strong>{{ $report->transmission_type === 'lora' ? 'Fixed node coordinates' : 'GPS' }}:</strong> {{ $coordinates }}</p>
                                        </div>
                                        <div class="detail-card">
                                            <strong>Report timeline</strong>
                                            <p><strong>Submitted:</strong> {{ optional($report->created_at)->format('M d, Y h:i A') ?? 'Pending' }}</p>
                                            <p><strong>Updated:</strong> {{ optional($report->status_updated_at)->format('M d, Y h:i A') ?? 'No admin update yet' }}</p>
                                        </div>
                                        <div class="detail-card">
                                            <strong>Admin response</strong>
                                            <span class="tag {{ $estimatedResponse['tone'] }}">{{ $estimatedResponse['label'] }}</span>
                                            <p>{{ $estimatedResponse['copy'] }}</p>
                                            <p><strong>Assigned admin:</strong> {{ $report->assignedResponder?->name ?? 'Not yet assigned' }}</p>
                                            <p><strong>Message:</strong> {{ $report->response_notes ?: 'No admin message yet.' }}</p>
                                        </div>
                                        <div class="detail-card">
                                            <strong>{{ $aiSourceLabel($report->operational_ai_source) }}</strong>
                                            <div class="tag-row">
                                                <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                                <span class="badge {{ $confidenceTone($report->operational_ai_confidence) }}">{{ $report->ai_score_display }}</span>
                                                <span class="badge {{ $aiStatusTone($report) }}">{{ $aiStatusLabel($report) }}</span>
                                            </div>
                                            <p>{{ $report->operational_ai_summary ?: 'AI review is still pending or no summary was generated.' }}</p>
                                        </div>
                                        <div class="detail-card civilian-report-modal-wide">
                                            <strong>Description</strong>
                                            <p>{{ $report->description ?: 'No description was submitted with this report.' }}</p>
                                        </div>
                                    </div>

                                    <div class="preview-grid civilian-report-modal-media-grid">
                                        <div class="preview-card">
                                            <strong>Submitted evidence</strong>
                                            @if ($evidenceUrl && $report->evidence_type === 'photo')
                                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ $evidenceUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Photo evidence' }}">
                                                    <img src="{{ $evidenceUrl }}" alt="Evidence photo for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async">
                                                    <span>Click to enlarge evidence photo</span>
                                                </button>
                                                <p>{{ $report->evidence_original_name ?: 'Photo evidence attached' }}</p>
                                            @elseif ($evidenceUrl && $report->evidence_type === 'video')
                                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="video" data-media-src="{{ $evidenceUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Video evidence' }}">
                                                    <video preload="metadata" playsinline muted class="preview-thumb"><source src="{{ $evidenceUrl }}">Your browser does not support embedded video playback.</video>
                                                    <span>Click to enlarge evidence video</span>
                                                </button>
                                                <p>{{ $report->evidence_original_name ?: 'Video evidence attached' }}</p>
                                            @else
                                                <p>No evidence photo or video is attached to this report.</p>
                                            @endif
                                        </div>
                                        <div class="preview-card">
                                            <strong>Verification selfie</strong>
                                            @if ($selfieUrl)
                                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ $selfieUrl }}" data-media-title="{{ $report->report_code }} - {{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}">
                                                    <img src="{{ $selfieUrl }}" alt="Verification selfie for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async">
                                                    <span>Click to enlarge selfie</span>
                                                </button>
                                                <p>{{ $report->reporter_selfie_original_name ?: 'Verification selfie stored' }}</p>
                                            @else
                                                <p>No verification selfie is attached to this report.</p>
                                            @endif
                                        </div>
                                        <div class="preview-card">
                                            <strong>Attachment summary</strong>
                                            <p>{{ $civilianAttachmentSummary($report) }}</p>
                                            <p><strong>Channel:</strong> {{ $report->channel ?: 'Internet' }}</p>
                                            <p><strong>Transmission:</strong> {{ strtoupper($report->transmission_type ?: 'online') }}</p>
                                        </div>
                                    </div>

                                    <div class="action-row civilian-report-modal-actions">
                                        <button type="button" class="btn btn-secondary" data-civilian-report-modal-content-close>Close</button>
                                    </div>
                                </article>
                            </div>
                        </article>
                    @empty
                        <div class="civilian-empty-state">
                            <strong>No reports yet</strong>
                            <p>{{ $isAccountVerified ? 'Tap Send Emergency Report when you need to submit one.' : 'Verify your account first to unlock emergency reporting.' }}</p>
                            <a href="{{ $isAccountVerified ? route('reports.create') : route('account.verify') }}" class="btn btn-primary">{{ $isAccountVerified ? 'Send My First Report' : 'Verify Account First' }}</a>
                        </div>
                    @endforelse
                </div>
            </section>
        </section>

        <div class="report-detail-modal civilian-report-modal" data-civilian-report-modal hidden>
            <div class="report-detail-modal-shell civilian-report-modal-shell" role="dialog" aria-modal="true" aria-labelledby="civilian-report-modal-title">
                <div class="report-detail-modal-head">
                    <div class="report-detail-modal-title">
                        <p class="eyebrow">Report Details</p>
                        <strong id="civilian-report-modal-title" data-civilian-report-modal-title>Report details</strong>
                    </div>
                    <button type="button" class="report-detail-modal-close" data-civilian-report-modal-close aria-label="Close report details">&times;</button>
                </div>
                <div class="report-detail-modal-body civilian-report-modal-body" data-civilian-report-modal-body></div>
            </div>
        </div>

        <script>
            (() => {
                const modal = document.querySelector('[data-civilian-report-modal]');
                if (!modal) {
                    return;
                }

                const modalTitle = modal.querySelector('[data-civilian-report-modal-title]');
                const modalBody = modal.querySelector('[data-civilian-report-modal-body]');
                let lastTrigger = null;

                const closeModal = () => {
                    modal.hidden = true;
                    modalBody.innerHTML = '';
                    document.body.style.overflow = '';

                    if (lastTrigger) {
                        lastTrigger.focus();
                    }
                };

                const openModal = (row, trigger) => {
                    const source = row?.querySelector('[data-civilian-report-modal-source]');
                    if (!source) {
                        return;
                    }

                    const clone = source.cloneNode(true);
                    clone.hidden = false;
                    modalTitle.textContent = row.getAttribute('data-civilian-report-title') || 'Report details';
                    modalBody.innerHTML = '';
                    modalBody.appendChild(clone);
                    modal.hidden = false;
                    document.body.style.overflow = 'hidden';
                    lastTrigger = trigger || null;
                };

                document.addEventListener('click', (event) => {
                    if (event.target.matches('[data-civilian-report-modal-close]') || event.target === modal || event.target.closest('[data-civilian-report-modal-content-close]')) {
                        event.preventDefault();
                        closeModal();
                        return;
                    }

                    const trigger = event.target.closest('[data-civilian-report-modal-trigger]');
                    if (!trigger) {
                        return;
                    }

                    const row = trigger.closest('[data-civilian-report-row]');
                    if (!row) {
                        return;
                    }

                    event.preventDefault();
                    openModal(row, trigger);
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !modal.hidden) {
                        closeModal();
                    }
                });
            })();
        </script>
    @else
        <section class="workspace-grid monitoring-dashboard-grid">
            <div class="monitoring-main-stack">
            <article class="panel monitoring-card-panel">
                <div class="panel-toolbar">
                    <div class="panel-heading">
                        <p class="panel-kicker">Latest Incident</p>
                        <h2 class="panel-title">Live incident monitoring</h2>
                    </div>
                    <div class="action-row">
                        <a href="{{ route('lora-reports.index') }}" class="btn btn-secondary">All LoRa reports</a>
                        <a href="{{ route('reports.index') }}" class="btn btn-secondary">All incident reports</a>
                    </div>
                </div>

                <div class="monitoring-incident-grid" data-live-incident-list data-max-items="3">
                        @forelse ($reports as $report)
                            @php
                                $processState = $adminProcessState($report);
                                $isLoRa = $report->transmission_type === 'lora';
                                $hasCoordinates = $report->latitude !== null && $report->longitude !== null;
                                $inlineMapPayload = $hasCoordinates ? [[
                                    'id' => 'dashboard-sender-'.$report->id,
                                    'incident_type' => $report->incident_type,
                                    'location_text' => $report->location_text,
                                    'severity' => $report->operational_severity,
                                    'severity_display' => $report->severity_display,
                                    'latitude' => (float) $report->latitude,
                                    'longitude' => (float) $report->longitude,
                                    'map_role' => 'sender',
                                    'readable_location' => $report->location_text,
                                    'google_maps_url' => $mapSearchUrl($report),
                                ]] : [];
                            @endphp
                            <article class="monitoring-incident-card latest-incident-row severity-{{ $report->operational_severity }} {{ $processState['class'] }}" data-report-modal-row data-report-id="{{ $report->id }}" data-report-title="{{ e($report->report_code.' - '.$report->incident_type) }}" data-admin-process-state="{{ $processState['label'] }}">
                                <span class="visually-hidden-control">ID Name Severity assessment Location Type Open Map Pin {{ $processState['label'] }}</span>

                                <div class="monitoring-card-top" role="button" tabindex="0" data-report-modal-trigger aria-label="Open full details for {{ $report->report_code }}">
                                    <div class="monitoring-card-code">
                                        <span class="civilian-account-status-dot {{ $statusDisplayTone($report->status) }}"></span>
                                        <span>
                                            <strong>{{ $report->report_code }}</strong>
                                            <small>{{ $report->reporter_name ?: $report->reporter?->name ?: 'Unknown civilian' }} | {{ $priorityTimer($report) }}</small>
                                        </span>
                                    </div>
                                    <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
                                </div>

                                <div class="monitoring-card-body" role="button" tabindex="0" data-report-modal-trigger>
                                    <h3>{{ $report->incident_type }}</h3>
                                    @include('reports.partials.ai-analysis', ['report' => $report, 'compact' => true])
                                    <div class="tag-row">
                                        <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                        @unless ($isLoRa)<span class="badge {{ $confidenceTone($report->operational_ai_confidence) }}">{{ $report->ai_score_display }}</span>@endunless
                                        <span class="tag neutral">{{ strtoupper($report->transmission_type ?: 'online') }}</span>
                                        @if (in_array($report->operational_ai_source, ['python_model', 'image_model'], true))
                                            <span class="badge neutral">Historical photo assessment</span>
                                        @endif
                                    </div>
                                    <p>{{ \Illuminate\Support\Str::limit($report->operational_ai_summary ?: $report->description ?: 'No short description provided.', 98) }}</p>
                                </div>

                                <div class="monitoring-card-map">
                                    @if ($hasCoordinates)
                                        <div class="latest-inline-map dashboard-mini-map" data-incident-map data-map-scope="single" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}"></div>
                                    @else
                                        <span class="incident-feed-map-empty">No GPS pin</span>
                                    @endif
                                    <small>GPS {{ $hasCoordinates ? number_format((float) $report->latitude, 6).', '.number_format((float) $report->longitude, 6) : $report->location_text }}</small>
                                </div>

                                <div class="monitoring-card-actions">
                                    <button type="button" class="action-btn primary" data-report-review-label>Review</button>
                                    <form method="POST" action="{{ route('reports.destroy', $report) }}" onsubmit="return confirm('Delete this specific incident report and attached files?');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="return_to" value="monitoring">
                                        <button type="submit" class="action-btn danger">Delete</button>
                                    </form>
                                </div>

                                @include('reports.partials.admin-report-modal-card', [
                                    'report' => $report,
                                    'tone' => $tone,
                                    'statusDisplayTone' => $statusDisplayTone,
                                    'statusDisplayLabel' => $statusDisplayLabel,
                                    'priorityTimer' => $priorityTimer,
                                    'confidenceTone' => $confidenceTone,
                                    'aiSourceLabel' => $aiSourceLabel,
                                    'aiStatusTone' => $aiStatusTone,
                                    'aiStatusLabel' => $aiStatusLabel,
                                    'dispatchSummary' => $dispatchSummary,
                                    'mapSearchUrl' => $mapSearchUrl,
                                ])
                            </article>
                        @empty
                            <div class="data-empty">No incident submissions yet. New online or LoRa reports will appear here as soon as they arrive.</div>
                        @endforelse
                </div>

                <p class="section-copy">Showing the three latest reports. Older reports remain available in report history.</p>
            </article>

            <article class="panel monitoring-summary-panel">
                <div class="monitoring-summary-grid">
                    <section class="monitoring-summary-section monitoring-summary-section-severity">
                        <div class="panel-heading">
                            <p class="panel-kicker">Severity Visualization</p>
                            <h2 class="panel-title">Current load by severity</h2>
                        </div>
                        <div class="summary-strip">
                            @foreach ($severityBreakdown as $label => $count)
                                <article class="summary-card">
                                    <span>{{ $label === 'needs_review' ? 'Needs review' : ($label === 'Fatal' ? 'Critical / Fatal' : $label) }}</span>
                                    <strong>{{ $count }}</strong>
                                    <p>{{ $label === 'needs_review' ? 'Awaiting responder assessment.' : 'Incidents currently tracked in this severity class.' }}</p>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section class="monitoring-summary-section monitoring-summary-section-transmission">
                        <div class="panel-heading">
                            <p class="panel-kicker">Transmission Mix</p>
                            <h2 class="panel-title">Online and LoRa coverage</h2>
                        </div>
                        <div class="summary-strip">
                            <article class="summary-card">
                                <span>Online reports</span>
                                <strong>{{ $transmissionBreakdown['online'] }}</strong>
                                <p>Full payload reports with picture or video evidence.</p>
                            </article>
                            <article class="summary-card">
                                <span>LoRa reports</span>
                                <strong>{{ $transmissionBreakdown['lora'] }}</strong>
                                <p>Compact fallback reports with severity, registered node location, and description.</p>
                            </article>
                        </div>
                    </section>
                </div>
            </article>
            </div>

            <div class="workspace-side monitoring-side-stack">
                <article class="panel">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">Priority Incident</p>
                            <h2 class="panel-title">Dispatch recommendation</h2>
                        </div>
                    </div>
                    @if ($priorityIncident && $dispatchRecommendation)
                        <div class="detail-card">
                            <strong>{{ $priorityIncident->incident_type }}</strong>
                            <p>{{ $priorityIncident->location_text }}</p>
                            <div class="tag-row">
                                <span class="tag red">{{ $dispatchRecommendation['priority'] }}</span>
                                <span class="tag blue">{{ $dispatchRecommendation['timer_label'] }}</span>
                            </div>
                            <div class="meta-list">
                                <div class="meta-row"><strong>Recommended admin</strong><span>{{ $dispatchRecommendation['recommended_responder'] }}</span></div>
                                <div class="meta-row"><strong>Response type</strong><span>{{ $dispatchRecommendation['response_type'] }}</span></div>
                            </div>
                            <a href="{{ route('reports.show', $priorityIncident) }}" class="btn btn-primary">View Priority Incident</a>
                        </div>
                    @else
                        <div class="data-empty">No priority incident is waiting right now.</div>
                    @endif
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">Alert Queue</p>
                            <h2 class="panel-title">Latest fatal incidents</h2>
                        </div>
                    </div>
                    <div class="stack">
                        @forelse ($fatalAlerts as $alert)
                            <a href="{{ route('reports.show', $alert) }}" class="detail-card">
                                <strong>{{ $alert->incident_type }}</strong>
                                <p>{{ $alert->location_text }}</p>
                                <div class="tag-row">
                                    <span class="tag red">{{ $alert->severity_display }}</span>
                                    <span class="tag {{ $statusDisplayTone($alert->status) }}">{{ $statusDisplayLabel($alert->status) }}</span>
                                    <span class="tag neutral">{{ $priorityTimer($alert) }}</span>
                                </div>
                            </a>
                        @empty
                            <div class="data-empty">No fatal alerts are active at the moment.</div>
                        @endforelse
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">Connectivity Intelligence</p>
                            <h2 class="panel-title">Field communication snapshot</h2>
                        </div>
                    </div>
                    <div class="meta-list">
                        <div class="meta-row"><strong>Online</strong><span>{{ $connectivity['online_status'] }}</span></div>
                        <div class="meta-row"><strong>LoRa fallback</strong><span>{{ $connectivity['lora_status'] }}</span></div>
                        <div class="meta-row"><strong>Internet condition</strong><span>{{ $connectivity['internet_status'] }}</span></div>
                        <div class="meta-row"><strong>Gateway</strong><span>{{ $connectivity['gateway_status'] }}</span></div>
                    </div>
                </article>

                <article class="panel lora-monitoring-shortcut">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">LoRa Mesh</p>
                            <h2 class="panel-title">Node heartbeat monitor</h2>
                            <p class="section-copy">{{ $loraNodes->where('recently_heard', true)->count() }} of {{ $loraNodes->count() }} registered nodes reported within the last 30 seconds.</p>
                        </div>
                        <a href="{{ route('monitoring.lora-mesh') }}" class="btn btn-primary">Open LoRa Mesh</a>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-head">
                        <div class="panel-heading">
                            <p class="panel-kicker">Admin Roster</p>
                            <h2 class="panel-title">Available admin receivers</h2>
                        </div>
                    </div>
                    <div class="stack">
                        @forelse ($availableResponders as $responder)
                            <div class="detail-card">
                                <strong>{{ $responder->name }}</strong>
                                <p>{{ $responder->responderProfile?->assigned_station ?? 'Station not set' }}</p>
                                <span class="tag red">Admin Receiver</span>
                            </div>
                        @empty
                            <div class="data-empty">No admin receiver roster entries are available yet.</div>
                        @endforelse
                    </div>
                </article>
            </div>
        </section>
    @endif
@endsection
