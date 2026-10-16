@extends('layouts.app')

@php
    $isCivilian = ($viewerMode ?? null) === 'civilian' || auth()->user()?->isCivilian();
    $tone = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'red',
        'Serious', 'Needs review', 'Awaiting responder' => 'amber',
        default => 'green',
    };
    $priorityTimer = static function ($report): string {
        if ($report->created_at === null) {
            return 'Just received';
        }

        return max(1, (int) ceil($report->created_at->diffInMinutes(now()))).' min active';
    };
    $queueState = static fn (string $status): string => match ($status) {
        'resolved' => 'done',
        'rejected' => 'rejected',
        default => 'open',
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
    $aiQueueStatusLabel = static function ($report): string {
        if ($report->operational_ai_source === 'manual_only') {
            return 'Awaiting responder severity';
        }

        if ($report->operational_ai_status === 'failed') {
            return 'AI unavailable';
        }

        if ($report->operational_ai_status === 'fallback') {
            return 'Fallback mode';
        }

        if ($report->operational_ai_review_required) {
            return 'Admin review needed';
        }

        if ($report->operational_ai_status === 'complete') {
            return 'Ready for triage';
        }

        return 'Pending AI review';
    };
    $aiQueueStatusTone = static function ($report): string {
        if ($report->operational_ai_status === 'failed') {
            return 'red';
        }

        if ($report->operational_ai_review_required) {
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
    $civilianStatusSummary = static fn (string $status): string => match ($status) {
        'resolved' => 'The admin receiver marked this report as completed.',
        'rejected' => 'This report was reviewed and not accepted for admin action.',
        'responding' => 'The admin receiver responded to your report.',
        'dispatched' => 'An admin receiver has started the response workflow.',
        'acknowledged' => 'Your report was acknowledged and is under review.',
        default => 'Your report is in the active monitoring queue.',
    };
    $civilianAttachmentSummary = static function ($report): string {
        $parts = [];

        if ($report->evidence_path) {
            $parts[] = ucfirst($report->evidence_type).' evidence attached';
        }

        if ($report->reporter_selfie_path) {
            $parts[] = 'verification selfie stored';
        }

        if ($parts === []) {
            return 'No evidence or verification selfie attached to this report.';
        }

        return ucfirst(implode(' and ', $parts)).'.';
    };
    $civilianTimeline = static function (string $status): array {
        if ($status === 'rejected') {
            return [
                ['label' => 'Submitted', 'copy' => 'Your report reached the system queue.', 'state' => 'complete', 'icon' => 'SB'],
                ['label' => 'Reviewed', 'copy' => 'The report was checked before the final decision.', 'state' => 'complete', 'icon' => 'RV'],
                ['label' => 'Rejected', 'copy' => 'The admin receiver marked this report as rejected.', 'state' => 'current', 'icon' => 'RJ'],
            ];
        }

        $sequence = [
            'received' => ['label' => 'Submitted', 'copy' => 'Your report reached the system queue.', 'icon' => 'SB'],
            'acknowledged' => ['label' => 'Reviewed', 'copy' => 'The report was acknowledged for admin review.', 'icon' => 'RV'],
            'dispatched' => ['label' => 'Dispatched', 'copy' => 'An admin receiver started the response workflow.', 'icon' => 'DP'],
            'responding' => ['label' => 'Responded', 'copy' => 'The admin receiver has responded to your report.', 'icon' => 'RS'],
            'resolved' => ['label' => 'Completed', 'copy' => 'The incident was marked as completed.', 'icon' => 'DN'],
        ];

        $keys = array_keys($sequence);
        $currentIndex = array_search($status, $keys, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;

        return array_map(
            static function (string $key, int $index) use ($sequence, $currentIndex): array {
                return [
                    'label' => $sequence[$key]['label'],
                    'copy' => $sequence[$key]['copy'],
                    'icon' => $sequence[$key]['icon'],
                    'state' => $index < $currentIndex ? 'complete' : ($index === $currentIndex ? 'current' : 'upcoming'),
                ];
            },
            $keys,
            array_keys($keys)
        );
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
            'rejected' => ['label' => 'No dispatch scheduled', 'time' => 'Review only', 'copy' => 'This report was reviewed and not queued for field deployment.', 'tone' => 'red'],
            'resolved' => ['label' => 'Response completed', 'time' => 'Closed', 'copy' => 'Admin activity for this report has already been completed.', 'tone' => 'green'],
            'responding' => ['label' => 'Responded', 'time' => 'Admin responded', 'copy' => 'An admin receiver has already responded to this incident.', 'tone' => 'green'],
            'dispatched' => ['label' => 'Admin response started', 'time' => 'Deployment started', 'copy' => 'The response workflow has started for the incident area.', 'tone' => 'blue'],
            'acknowledged' => ['label' => 'Admin review in progress', 'time' => 'In review', 'copy' => 'Your report is under active admin review for assignment.', 'tone' => 'blue'],
            default => ['label' => 'Awaiting admin review', 'time' => 'Pending', 'copy' => 'Your report is waiting for an admin response update.', 'tone' => 'amber'],
        };
    };
    $civilianStatusNotification = static function ($report): array {
        $timestamp = $report->status_updated_at ?? $report->created_at;

        return [
            'title' => match ($report->status) {
                'resolved' => 'Report completed',
                'rejected' => 'Report rejected',
                'responding' => 'Admin responded',
                'dispatched' => 'Admin response started',
                'acknowledged' => 'Report acknowledged',
                default => 'Pending admin response',
            },
            'copy' => match ($report->status) {
                'resolved' => 'The admin receiver marked this incident as completed.',
                'rejected' => 'The report was reviewed and closed without admin deployment.',
                'responding' => 'An admin receiver has responded to your report.',
                'dispatched' => 'The admin receiver has started the response workflow for your incident.',
                'acknowledged' => 'Your report is now under admin review.',
                default => 'Your report entered the active queue and is waiting for admin response.',
            },
            'time' => $timestamp?->diffForHumans() ?? 'just now',
            'tone' => match ($report->status) {
                'resolved' => 'green',
                'rejected' => 'red',
                'responding', 'dispatched', 'acknowledged' => 'blue',
                default => 'amber',
            },
        ];
    };
    $civilianRecentUpdates = collect($civilianRecentUpdates ?? []);
@endphp

@section('title', $isCivilian ? 'Report History' : 'Incident Reports')
@section('page_label', $isCivilian ? 'Report History' : 'Incident Reports')
@section('page_heading', $isCivilian ? 'My Submitted Reports' : 'Incident Reports')
@section('page_subheading', $isCivilian ? 'Review only the emergencies you submitted and their current response status.' : 'Review incidents, check locations, and coordinate a response.')

@section('hero')
    @if ($isCivilian)
        <section class="hero-card responder-lean-hero">
            <div class="hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow">Personal History</p>
                    <h2>Track your emergency submissions without extra clutter.</h2>
                    <p>Review your submitted incidents, attached evidence, verification selfies, and current status updates from one simple history view.</p>
                    <div class="hero-actions">
                        <a href="{{ route('reports.create') }}" class="btn btn-primary">Send New Report</a>
                        <a href="{{ route('dashboard') }}" class="btn btn-secondary">Back To Dashboard</a>
                    </div>
                </div>
            </div>
        </section>
    @endif
@endsection

@section('content')
    @unless ($isCivilian)
        <div class="report-detail-modal report-filter-modal" data-report-filter-modal hidden>
            <div class="report-detail-modal-shell report-filter-modal-shell" role="dialog" aria-modal="true" aria-labelledby="report-filter-modal-title">
                <div class="report-detail-modal-head">
                    <div class="report-detail-modal-title">
                        <p class="eyebrow">Controls</p>
                        <strong id="report-filter-modal-title">Search and filter reports</strong>
                    </div>
                    <button type="button" class="report-detail-modal-close" data-report-filter-close aria-label="Close report filters">&times;</button>
                </div>
                <div class="report-detail-modal-body report-filter-modal-body">
                    <form method="GET" action="{{ route('reports.index') }}" class="form-grid report-filter-modal-grid">
                        <div class="field"><label for="modal-search">Search</label><input id="modal-search" class="input" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Code, incident, location, reporter"></div>
                        <div class="field"><label for="modal-severity">Severity</label><select id="modal-severity" name="severity"><option value="">All severities</option>@foreach (['Minor', 'Serious', 'Fatal'] as $severity)<option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $severity }}</option>@endforeach</select></div>
                        <div class="field"><label for="modal-status">Status</label><select id="modal-status" name="status"><option value="">All statuses</option>@foreach (['received', 'acknowledged', 'dispatched', 'responding', 'resolved', 'rejected'] as $status)<option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusDisplayLabel($status) }}</option>@endforeach</select></div>
                        <div class="field"><label for="modal-transmission">Transmission</label><select id="modal-transmission" name="transmission"><option value="">All transports</option><option value="online" @selected($filters['transmission'] === 'online')>Online</option><option value="lora" @selected($filters['transmission'] === 'lora')>LoRa</option></select></div>
                        <div class="field"><label for="modal-sort">Sort</label><select id="modal-sort" name="sort"><option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option><option value="severity" @selected($filters['sort'] === 'severity')>Severity</option><option value="status" @selected($filters['sort'] === 'status')>Status</option></select></div>
                        <div class="field report-filter-modal-actions">
                            <div class="button-row">
                                <button class="btn btn-primary" type="submit">Apply Filters</button>
                                <a href="{{ route('reports.index') }}" class="btn btn-secondary">Reset Filters</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endunless

    @if ($isCivilian)
        <section class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Status Notifications</p>
                    <h2 class="panel-title">Recent status updates</h2>
                    <p class="section-copy">Review the latest changes to your submitted reports, including admin acknowledgements, dispatch updates, completions, or rejections.</p>
                </div>
            </div>
            <div class="preview-grid">
                @forelse ($civilianRecentUpdates as $report)
                    @php
                        $statusNotification = $civilianStatusNotification($report);
                    @endphp
                    <button type="button" class="detail-card civilian-status-update-card" data-civilian-report-modal-trigger data-civilian-report-target="{{ $report->id }}">
                        <div class="tag-row" style="justify-content:space-between;align-items:center;">
                            <span class="tag {{ $statusNotification['tone'] }}">{{ $statusDisplayLabel($report->status) }}</span>
                            <span class="incident-code">{{ $statusNotification['time'] }}</span>
                        </div>
                        <strong>{{ $statusNotification['title'] }}</strong>
                        <p>{{ $statusNotification['copy'] }}</p>
                        <p><strong>{{ $report->report_code }}</strong> | {{ $report->incident_type }}</p>
                    </button>
                @empty
                    <div class="data-empty">Status notifications will appear here after you submit your first report.</div>
                @endforelse
            </div>
        </section>

        <section class="panel">
            <div class="panel-toolbar">
                <div class="panel-heading">
                    <p class="panel-kicker">Controls</p>
                    <h2 class="panel-title">Search and filter</h2>
                    <p class="section-copy">Review your report history by reference code and current status.</p>
                </div>
            </div>
            <form method="GET" action="{{ route('reports.index') }}" class="form-grid">
                <div class="field"><label for="search">Search</label><input id="search" class="input" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Code, incident, or location"></div>
                <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>@foreach (['received', 'acknowledged', 'dispatched', 'responding', 'resolved', 'rejected'] as $status)<option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusDisplayLabel($status) }}</option>@endforeach</select></div>
                <div class="field"><label for="sort">Sort</label><select id="sort" name="sort"><option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option><option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option><option value="status" @selected($filters['sort'] === 'status')>Status</option></select></div>
                <div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Apply Filters</button></div>
            </form>
        </section>
    @endif

    <section class="panel {{ $isCivilian ? '' : 'report-list-panel' }}">
        <div class="panel-head">
            <div class="panel-heading">
                <p class="panel-kicker">{{ $isCivilian ? 'Report History' : 'Incident Feed' }}</p>
                <h2 class="panel-title">{{ $isCivilian ? 'My current and past reports' : 'Current reports' }}</h2>
                @if ($isCivilian)
                    <p class="section-copy">Review your submitted emergency details and current response status.</p>
                @endif
            </div>
            @unless ($isCivilian)
                <div class="panel-actions report-feed-toolbar">
                    <button type="button" class="btn btn-secondary" data-report-filter-open aria-haspopup="dialog">Search &amp; Filters</button>
                    <details class="report-export-menu" data-report-export data-preview-url="{{ route('reports.export.preview') }}" data-download-url="{{ route('reports.export') }}">
                        <summary class="btn btn-primary">Export Reports <span aria-hidden="true">&#9662;</span></summary>
                        <div class="report-export-panel">
                            <form data-export-form>
                                @foreach (['search', 'severity', 'status', 'transmission'] as $filterName)
                                    @if (filled($filters[$filterName] ?? ''))
                                        <input type="hidden" name="{{ $filterName }}" value="{{ $filters[$filterName] }}">
                                    @endif
                                @endforeach
                                <p class="report-export-help">Exports every report matching your current search and filters, across all pages. Reporter contact details and media are excluded.</p>
                                <label class="report-export-sensitive-option" for="report-export-personal-data">
                                    <input type="checkbox" id="report-export-personal-data" name="include_personal_data" value="1">
                                    <span><strong>Include reporter name and contact</strong><small>Sensitive data: include only when needed. This export is recorded in the audit log.</small></span>
                                </label>
                                <div class="field">
                                    <label for="report-export-period">Report period</label>
                                    <select id="report-export-period" name="period" data-export-period>
                                        <option value="daily">Today</option>
                                        <option value="weekly">This week (Monday–Sunday)</option>
                                        <option value="monthly">This month</option>
                                        <option value="custom">Custom date range</option>
                                    </select>
                                </div>
                                <div class="report-export-date-fields" data-export-custom-fields hidden>
                                    <div class="field">
                                        <label for="report-export-from">From</label>
                                        <input class="input" type="date" id="report-export-from" name="date_from" data-export-date-from disabled>
                                    </div>
                                    <div class="field">
                                        <label for="report-export-to">To</label>
                                        <input class="input" type="date" id="report-export-to" name="date_to" data-export-date-to disabled>
                                    </div>
                                </div>
                                <p class="report-export-timezone">Dates use Philippines time (Asia/Manila).</p>
                                <button class="btn btn-secondary" type="button" data-export-preview>Preview matching reports</button>
                                <p class="report-export-feedback" data-export-feedback role="status" aria-live="polite" hidden></p>
                                <div class="report-export-downloads" data-export-downloads hidden>
                                    <a class="btn btn-secondary" href="#" data-export-csv>Download CSV</a>
                                    <a class="btn btn-primary" href="#" data-export-xlsx>Download Excel (.xlsx)</a>
                                </div>
                            </form>
                        </div>
                    </details>
                    @if (($stats['total'] ?? 0) > 0)
                        <details class="report-manage-menu">
                            <summary class="btn btn-secondary">Manage <span aria-hidden="true">&#9662;</span></summary>
                            <div class="report-manage-actions">
                                <form method="POST" action="{{ route('reports.destroy-all') }}" onsubmit="return confirm('Delete all incident reports and attached evidence/selfie files? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger">Delete All Reports</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @endunless
        </div>

        <div class="monitor-shell" data-view-toggle data-view-mode="list">
            <div class="{{ $isCivilian ? 'civilian-history-table-shell' : 'incident-stack' }}" data-view-panel="list">
                @if ($isCivilian)
                    <div class="civilian-history-table-wrap">
                        <table class="civilian-history-table">
                            <thead>
                                <tr>
                                    <th scope="col">INC / ID</th>
                                    <th scope="col">Type of Accident</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">GPS</th>
                                </tr>
                            </thead>
                            <tbody>
                @endif
                @forelse ($reports as $report)
                    @php
                        $state = $queueState($report->status);
                    @endphp
                    @if ($isCivilian)
                        @php
                            $estimatedResponse = $civilianEstimatedResponse($report);
                            $progressPercent = $civilianProgressPercent($report->status);
                            $evidenceUrl = $report->evidence_path ? route('reports.evidence', $report) : null;
                            $selfieUrl = $report->reporter_selfie_path ? route('reports.selfie', $report) : null;
                            $coordinates = $report->latitude !== null && $report->longitude !== null
                                ? number_format((float) $report->latitude, 6).', '.number_format((float) $report->longitude, 6)
                                : 'Coordinates not available';
                        @endphp
                        <tr class="civilian-history-table-row" data-civilian-report-row data-civilian-report-modal-trigger data-civilian-report-id="{{ $report->id }}" data-civilian-report-title="{{ $report->report_code }} - {{ $report->incident_type }}" role="button" tabindex="0" aria-label="Open full details for {{ $report->report_code }}">
                            <td data-label="INC / ID"><span class="incident-code">{{ $report->report_code }}</span></td>
                            <td data-label="Type of Accident"><strong>{{ $report->incident_type }}</strong></td>
                            <td data-label="Status"><span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span></td>
                            <td data-label="Date"><span>{{ optional($report->created_at)->format('M d, Y h:i A') ?? 'Pending' }}</span></td>
                            <td data-label="GPS">
                                <span>{{ $coordinates }}</span>
                                <div class="civilian-report-modal-source" data-civilian-report-modal-source hidden>
                                <article class="civilian-report-modal-card">
                                    <div class="civilian-report-modal-hero">
                                        <div>
                                            <span class="incident-code">{{ $report->report_code }}</span>
                                            <h3>{{ $report->incident_type }}</h3>
                                            <p>{{ $civilianStatusSummary($report->status) }}</p>
                                        </div>
                                        <div class="tag-row">
                                            <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
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
                                            <p><strong>GPS:</strong> {{ $coordinates }}</p>
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
                                                <span class="badge {{ $aiQueueStatusTone($report) }}">{{ $aiQueueStatusLabel($report) }}</span>
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
                                                    <img src="{{ $evidenceUrl }}" alt="Evidence photo for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async" fetchpriority="low">
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
                                                    <img src="{{ $selfieUrl }}" alt="Verification selfie for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async" fetchpriority="low">
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
                            </td>
                        </tr>
                    @else
                        @php
                            $hasCoordinates = $report->latitude !== null && $report->longitude !== null;
                            $isLoRa = $report->transmission_type === 'lora';
                            $openForReview = (int) ($reviewReportId ?? 0) === (int) $report->id;
                            $inlineMapPayload = $hasCoordinates ? [[
                                'id' => 'feed-sender-'.$report->id,
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
                        <details class="incident-feed-row {{ $report->status === 'received' ? 'process-pending' : 'process-active' }}" id="incident-report-{{ $report->id }}" data-report-modal-row data-report-title="{{ e($report->report_code.' - '.$report->incident_type) }}"{{ $openForReview ? ' data-report-open-on-load' : '' }}>
                            <summary class="incident-feed-row-summary" role="button" tabindex="0" aria-label="Open full details for {{ $report->report_code }}">
                                <div class="incident-feed-row-type">
                                    <strong>{{ $report->incident_type }}</strong>
                                    <span class="incident-feed-card-badges">
                                        <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                        <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
                                    </span>
                                    <small>{{ strtoupper($report->transmission_type ?: 'online') }} | {{ $report->status === 'received' ? 'Needs admin review' : 'Admin processed' }}</small>
                                </div>
                                <div class="incident-feed-row-location">
                                    <strong>{{ $report->location_text }}</strong>
                                    <small>
                                        @if ($hasCoordinates)
                                            Pinpoint: {{ number_format((float) $report->latitude, 5) }}, {{ number_format((float) $report->longitude, 5) }}
                                        @else
                                            Manual location only
                                        @endif
                                    </small>
                                </div>
                                <div class="incident-feed-row-ai">
                                    @if ($isLoRa)
                                        <span>LoRa source</span>
                                        <strong>{{ $report->lora_gateway_id ?: 'Gateway unknown' }}</strong>
                                        <small>Registered node location | No photo attached</small>
                                    @else
                                        <span>{{ $aiSourceLabel($report->operational_ai_source) }}</span>
                                        <strong>{{ $report->severity_display }}{{ ' · '.$report->ai_score_display }}</strong>
                                        <small>{{ \Illuminate\Support\Str::limit($report->operational_ai_summary ?: 'AI analysis is still pending for this civilian report.', 86) }}</small>
                                    @endif
                                </div>
                                <div class="incident-feed-row-main">
                                    <span class="civilian-account-status-dot {{ $statusDisplayTone($report->status) }}"></span>
                                    <span>
                                        <strong>{{ $report->report_code }}</strong>
                                        <small>{{ $report->reporter_name ?: 'Unknown reporter' }} | {{ $priorityTimer($report) }}</small>
                                    </span>
                                </div>
                                <div class="incident-feed-row-map">
                                    @if ($hasCoordinates)
                                        <div class="latest-inline-map incident-feed-mini-map" data-incident-map data-map-scope="single" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}"></div>
                                    @else
                                        <span class="incident-feed-map-empty">No GPS pin</span>
                                    @endif
                                </div>
                                <button type="button" class="btn btn-secondary incident-feed-review-button" data-report-review-label>Review</button>
                            </summary>

                            <div class="incident-card compact-live-card responder-feed-card incident-feed-expanded">
                            <div class="incident-rail severity-{{ $report->operational_severity }}"></div>
                            <div class="incident-meta">
                                <span class="incident-code">{{ $report->report_code }}</span>
                                <strong class="incident-time">{{ $priorityTimer($report) }}</strong>
                                <div class="tag-row">
                                    <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                    <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
                                    <span class="tag neutral">{{ strtoupper($report->transmission_type ?: 'online') }}</span>
                                </div>
                            </div>
                            <div class="incident-content">
                                <div class="incident-headline">
                                    <div>
                                        <h3 class="incident-title">{{ $report->incident_type }}</h3>
                                        <p class="detail-copy"><strong>{{ $report->location_text }}</strong> | {{ $report->reporter_name ?: 'Unknown reporter' }}</p>
                                    </div>
                                    <div class="tag-row">
                                        @if ($report->evidence_path)
                                            <span class="tag green">{{ ucfirst($report->evidence_type) }} Attached</span>
                                        @endif
                                        @if ($report->reporter_selfie_path)
                                            <span class="tag neutral">Selfie Verified</span>
                                        @endif
                                        <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
                                    </div>
                                </div>
                                <p class="incident-summary">{{ \Illuminate\Support\Str::limit($report->description, 170) }}</p>

                                <div class="preview-grid">
                                    @if ($isLoRa)
                                        <div class="preview-card">
                                            <strong>LoRa compact payload</strong>
                                            <div class="tag-row">
                                                <span class="tag amber">LoRa</span>
                                                <span class="tag neutral">No photo attached</span>
                                            </div>
                                            <div class="meta-list">
                                                <div class="meta-row"><span>Full name</span><strong>{{ $report->reporter_name ?: 'Unknown sender' }}</strong></div>
                                                <div class="meta-row"><span>Source gateway</span><strong>{{ $report->lora_gateway_id ?: 'Unknown gateway' }}</strong></div>
                                                <div class="meta-row"><span>Sender node</span><strong>{{ $report->lora_sender_id ?: 'Unknown node' }}{{ $report->lora_sequence ? ' | Message '.$report->lora_sequence : '' }}</strong></div>
                                                <div class="meta-row"><span>Signal</span><strong>RX {{ $report->lora_receiver_rssi ?? 'n/a' }} dBm / {{ $report->lora_receiver_snr ?? 'n/a' }} dB</strong></div>
                                            </div>
                                            <p>LoRa carries the sender name and short description without media. The fixed map location comes from the official node registry. Responders review the description to assess severity.</p>
                                        </div>
                                    @else
                                        <div class="preview-card">
                                            <strong>{{ in_array($report->operational_ai_source, ['manual_only', 'human_responder'], true) ? 'Severity assessment' : 'AI quick read' }}</strong>
                                            <div class="tag-row">
                                                <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }} output</span>
                                                <span class="badge {{ $confidenceTone($report->operational_ai_confidence) }}">{{ $report->ai_score_display }}</span>
                                                <span class="badge blue">{{ $aiSourceLabel($report->operational_ai_source) }}</span>
                                                <span class="badge {{ $aiQueueStatusTone($report) }}">{{ $aiQueueStatusLabel($report) }}</span>
                                                @include('reports.partials.photo-relevance-badges', ['report' => $report])
                                            </div>
                                            <p>{{ $report->operational_ai_summary ?: 'AI summary is still pending for this incident.' }}</p>
                                            <p><strong>Model:</strong> {{ $report->operational_ai_model_name ? $report->operational_ai_model_name.($report->operational_ai_model_version ? ' v'.$report->operational_ai_model_version : '') : 'Model pending' }}</p>
                                        </div>
                                    @endif

                                    <div class="preview-card">
                                        <strong>Evidence</strong>
                                        @if ($report->evidence_path)
                                            @if ($report->evidence_type === 'photo')
                                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ route('reports.evidence', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Photo evidence' }}">
                                                    <img src="{{ route('reports.evidence', $report) }}" alt="Photo evidence for {{ $report->report_code }}" class="preview-thumb" loading="lazy" decoding="async" fetchpriority="low">
                                                    <span>Click to enlarge photo</span>
                                                </button>
                                            @elseif ($report->evidence_type === 'video')
                                                <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="video" data-media-src="{{ route('reports.evidence', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->evidence_original_name ?: 'Video evidence' }}">
                                                    <video preload="none" playsinline muted class="preview-thumb"><source src="{{ route('reports.evidence', $report) }}">Your browser does not support embedded video playback.</video>
                                                    <span>Click to enlarge video</span>
                                                </button>
                                            @endif
                                            <p>{{ $report->evidence_original_name ?: 'Attached file' }}</p>
                                        @elseif ($report->transmission_type === 'lora')
                                            <p>Compact only. LoRa fallback reports do not include picture or video payloads.</p>
                                        @else
                                            <p>None</p>
                                        @endif
                                    </div>

                                    <div class="preview-card">
                                        <strong>Verification Selfie</strong>
                                        @if ($report->reporter_selfie_path)
                                            <button type="button" class="media-trigger" data-media-viewer-trigger data-media-type="photo" data-media-src="{{ route('reports.selfie', $report) }}" data-media-title="{{ $report->report_code }} - {{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}">
                                                <img src="{{ route('reports.selfie', $report) }}" alt="Verification selfie for {{ $report->report_code }}" class="preview-thumb" style="max-height:220px;" loading="lazy" decoding="async" fetchpriority="low">
                                                <span>Click to enlarge selfie</span>
                                            </button>
                                            <p>{{ $report->reporter_selfie_original_name ?: 'Verification selfie' }}</p>
                                        @else
                                            <p>None</p>
                                        @endif
                                    </div>

                                    <div class="preview-card">
                                        <strong>Map location</strong>
                                        @if ($hasCoordinates)
                                            <div class="latest-inline-map incident-feed-modal-map" data-incident-map data-map-scope="single" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}"></div>
                                            <p>GPS {{ number_format((float) $report->latitude, 6) }}, {{ number_format((float) $report->longitude, 6) }}</p>
                                            <button type="button" class="btn btn-secondary" data-report-map-trigger data-map-title="{{ e($report->report_code.' - '.$report->location_text) }}" data-report-id="{{ $report->id }}" data-latitude="{{ (float) $report->latitude }}" data-longitude="{{ (float) $report->longitude }}" data-location-text="{{ e($report->location_text) }}" data-points="{{ e(json_encode($inlineMapPayload)) }}">Open Map Pin</button>
                                        @else
                                            <p>No GPS pin was attached. Use the written location from the report.</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="dual-grid responder-feed-actions-grid">
                                    <div class="detail-card">
                                        <strong>Admin process</strong>
                                        <div class="tag-row">
                                            <span class="tag {{ $statusDisplayTone($report->status) }}">{{ $statusDisplayLabel($report->status) }}</span>
                                            <span class="tag {{ $tone($report->severity_display) }}">{{ $report->severity_display }}</span>
                                        </div>
                                        <p>{{ $report->status === 'received' ? 'No admin has responded or cancelled this report yet.' : 'Current saved coordination status: '.$statusDisplayLabel($report->status).'.' }}</p>
                                        <div class="meta-list">
                                            <div class="meta-row">
                                                <span>Submitted</span>
                                                <strong>{{ optional($report->created_at)->format('M d, Y h:i A') ?? 'Pending' }}</strong>
                                            </div>
                                            <div class="meta-row">
                                                <span>Status updated</span>
                                                <strong>{{ optional($report->status_updated_at)->format('M d, Y h:i A') ?? 'No admin update yet' }}</strong>
                                            </div>
                                            <div class="meta-row">
                                                <span>Last admin message</span>
                                                <strong>{{ $report->response_notes ?: 'No response message yet.' }}</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="detail-card incident-actions">
                                        <strong>Admin decision</strong>
                                        @include('reports.partials.severity-review-form', ['report' => $report])
                                        <p>{{ $dispatchSummary($report->severity_display) }} Respond or cancel actions require a message so the civilian sender receives a clear update.</p>
                                        <form method="POST" action="{{ route('reports.coordination', $report) }}" class="stack">
                                            @csrf
                                            <textarea name="response_notes" class="textarea" placeholder="Required message for Respond or Cancel">{{ old('response_notes', $report->response_notes) }}</textarea>
                                            <div class="action-row">
                                                @if ($report->transmission_type === 'lora' && $report->status === 'received')
                                                    <button type="submit" name="status" value="acknowledged" class="action-btn primary">Acknowledge</button>
                                                @endif
                                                <button type="submit" name="status" value="responding" class="action-btn secondary">Respond</button>
                                                <button type="submit" name="status" value="resolved" class="action-btn primary">Set Done</button>
                                                <button type="submit" name="status" value="rejected" class="action-btn danger">Cancel</button>
                                                <button type="button" class="action-btn secondary" data-report-row-close>Close</button>
                                            </div>
                                        </form>
                                        <div class="action-row">
                                            <form method="POST" action="{{ route('reports.destroy', $report) }}" onsubmit="return confirm('Delete this report from the queue?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="action-btn danger">Delete Report</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            </div>
                        </details>
                    @endif
                @empty
                    @if ($isCivilian)
                        <tr>
                            <td colspan="5">
                                <div class="civilian-history-empty">
                                    <p class="eyebrow">No Report History Yet</p>
                                    <h2>Your submitted reports will appear here once you send an emergency report.</h2>
                                    <p>If you have not submitted a report yet, start with the civilian emergency form. If you already sent one, try clearing your filters or refreshing the page.</p>
                                    <div class="hero-actions">
                                        <a href="{{ route('reports.create') }}" class="btn btn-primary">Send New Report</a>
                                        <a href="{{ route('dashboard') }}" class="btn btn-secondary">Back To Dashboard</a>
                                    </div>
                                    <div class="civilian-history-empty-notes">
                                        <article class="detail-card">
                                            <strong>What you will see here</strong>
                                            <p>Your report code, accident type, status, date, and GPS summary.</p>
                                        </article>
                                        <article class="detail-card">
                                            <strong>Quick reminder</strong>
                                            <p>Click a report row to open the full details modal.</p>
                                        </article>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @else
                        <div class="data-empty">No reports matched the current filters.</div>
                    @endif
                @endforelse
                @if ($isCivilian)
                            </tbody>
                        </table>
                    </div>
                @endif

            </div>

            @if (method_exists($reports, 'hasPages') && $reports->hasPages())
                @php
                    $currentPage = $reports->currentPage();
                    $lastPage = $reports->lastPage();
                @endphp
                <nav class="report-pagination report-page-toggle" aria-label="Report feed pagination">
                    <span class="report-pagination-meta">
                        {{ $isCivilian ? 'Viewing' : 'Latest view' }} {{ $reports->firstItem() }}-{{ $reports->lastItem() }} of {{ $reports->total() }} reports
                    </span>
                    <div class="report-page-controls">
                        @if ($reports->onFirstPage())
                            <span class="report-page-arrow is-disabled" aria-disabled="true" aria-label="Previous reports">&larr;</span>
                        @else
                            <a href="{{ $reports->previousPageUrl() }}" class="report-page-arrow" aria-label="Previous reports">&larr;</a>
                        @endif

                        <span class="report-page-index">Page {{ $currentPage }} / {{ $lastPage }}</span>

                        @if ($reports->hasMorePages())
                            <a href="{{ $reports->nextPageUrl() }}" class="report-page-arrow is-next" aria-label="Next reports">&rarr;</a>
                        @else
                            <span class="report-page-arrow is-next is-disabled" aria-disabled="true" aria-label="Next reports">&rarr;</span>
                        @endif
                    </div>
                </nav>
            @endif
        </div>
    </section>

    @if ($isCivilian)
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
    @endif
@endsection
