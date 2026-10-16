<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\AiTextSeverityClient;
use App\Services\IncidentReportFilterQuery;
use App\Services\RouteNavigationClient;
use App\Support\AiSeverityMapper;
use App\Support\EvidencePhotoPreScreen;
use App\Support\IncidentFeedBroadcaster;
use App\Support\IncidentReportIngestor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function index(Request $request, IncidentReportFilterQuery $filterQuery): View
    {
        $user = $request->user();
        $isCivilian = $user?->isCivilian() ?? false;
        $reviewReportId = ! $isCivilian && $request->integer('review') > 0
            ? $request->integer('review')
            : null;
        $activeStatuses = ['received', 'acknowledged', 'dispatched', 'responding'];
        $reportsQuery = $filterQuery->build($request, $user)
            ->with(['reporter', 'assignedResponder']);

        $sort = (string) $request->string('sort', 'newest');

        if ($isCivilian && $sort === 'severity') {
            $sort = 'newest';
        }

        if ($reviewReportId) {
            $reportsQuery->orderByRaw('case when incident_reports.id = ? then 0 else 1 end', [$reviewReportId]);
        }

        match ($sort) {
            'oldest' => $reportsQuery->oldest(),
            'severity' => $reportsQuery
                ->orderByRaw($this->operationalSeverityOrderSql())
                ->latest(),
            'status' => $reportsQuery
                ->orderByRaw("case status when 'received' then 1 when 'acknowledged' then 2 when 'dispatched' then 3 when 'responding' then 4 when 'resolved' then 5 when 'rejected' then 6 else 7 end")
                ->latest(),
            default => $reportsQuery->latest(),
        };

        $reports = $reportsQuery
            ->paginate($isCivilian ? 5 : 3)
            ->withQueryString();
        $reportItems = $reports->getCollection();

        $priorityIncident = $isCivilian ? null : IncidentReport::query()
            ->with(['assignedResponder'])
            ->whereIn('status', $activeStatuses)
            ->orderByRaw($this->operationalSeverityOrderSql())
            ->oldest('created_at')
            ->first();

        return view('reports.index', [
            'viewerMode' => $isCivilian ? 'civilian' : 'responder',
            'reports' => $reports,
            'responders' => $isCivilian ? collect() : User::query()
                ->where('is_admin', true)
                ->with('responderProfile')
                ->orderBy('name')
                ->get(),
            'filters' => [
                'search' => (string) $request->string('search'),
                'severity' => $isCivilian ? '' : (string) $request->string('severity'),
                'status' => (string) $request->string('status'),
                'transmission' => $isCivilian ? '' : (string) $request->string('transmission'),
                'sort' => $sort,
            ],
            'reviewReportId' => $reviewReportId,
            'stats' => $isCivilian
                ? ['total' => $this->visibleReportsQuery($user)->count()]
                : [
                    'total' => $this->visibleReportsQuery($user)->count(),
                    'active' => $this->visibleReportsQuery($user)->whereIn('status', $activeStatuses)->count(),
                    'fatal' => $this->visibleReportsQuery($user)->operationallyAssessed()->whereIn('severity', ['Critical', 'Fatal'])->whereIn('status', $activeStatuses)->count(),
                    'online' => $this->visibleReportsQuery($user)->where('transmission_type', 'online')->count(),
                    'lora' => $this->visibleReportsQuery($user)->where('transmission_type', 'lora')->count(),
                ],
            'fatalAlerts' => $isCivilian ? collect() : IncidentReport::query()->operationallyAssessed()
                ->whereIn('severity', ['Critical', 'Fatal'])
                ->whereIn('status', $activeStatuses)
                ->latest()
                ->take(6)
                ->get(),
            'priorityIncident' => $priorityIncident,
            'dispatchRecommendation' => $priorityIncident ? $this->dispatchRecommendationFor($priorityIncident) : null,
            'civilianRecentUpdates' => $isCivilian
                ? $this->visibleReportsQuery($user)
                    ->with(['reporter', 'assignedResponder'])
                    ->orderByRaw('COALESCE(status_updated_at, created_at) desc')
                    ->take(2)
                    ->get()
                : collect(),
            'connectivity' => $isCivilian ? null : $this->connectivityIntelligence(),
            'mapPoints' => $isCivilian
                ? collect()
                : $reportItems
                    ->filter(fn (IncidentReport $report): bool => $report->latitude !== null && $report->longitude !== null)
                    ->values(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        if ($redirect = $this->redirectUnverifiedCivilian(Auth::user())) {
            return $redirect;
        }

        return view('reports.create', [
            'submissionId' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, IncidentReportIngestor $ingestor): RedirectResponse
    {
        $isCivilian = $request->user()?->isCivilian() ?? false;

        if ($redirect = $this->redirectUnverifiedCivilian($request->user())) {
            return $redirect;
        }

        $usesManualGpsFallback = $isCivilian && $request->boolean('gps_fallback_used');

        $validator = Validator::make($request->all(), [
            'description' => ['required', 'string', 'max:1000'],
            'submission_id' => ['nullable', 'uuid'],
            'location_text' => ['required', 'string', 'max:180'],
            'gps_fallback_used' => ['nullable', 'boolean'],
            'latitude' => [Rule::requiredIf($isCivilian && ! $usesManualGpsFallback), 'nullable', 'numeric'],
            'longitude' => [Rule::requiredIf($isCivilian && ! $usesManualGpsFallback), 'nullable', 'numeric'],
            'incident_type' => ['nullable', 'string', 'max:80'],
            'severity' => ['nullable', 'string', 'max:20'],
            'transmission_type' => ['nullable', 'in:auto,online,lora'],
            'evidence' => ['nullable', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi,3gp'],
            'evidence_photo_capture' => ['nullable', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp'],
            'evidence_video_capture' => ['nullable', 'file', 'max:20480', 'mimes:mp4,mov,avi,3gp'],
            'selfie' => [
                $isCivilian ? 'required_without:selfie_capture' : 'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp',
            ],
            'selfie_capture' => [
                $isCivilian ? 'required_without:selfie' : 'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp',
            ],
        ], [
            'latitude.required' => 'Lock GPS or type a manual location before sending the emergency report.',
            'longitude.required' => 'Lock GPS or type a manual location before sending the emergency report.',
            'description.required' => 'Describe the emergency before sending. The AI severity review uses this text as its main basis.',
            'selfie.required_without' => 'Capture or attach a verification selfie before sending the report.',
            'selfie_capture.required_without' => 'Capture or attach a verification selfie before sending the report.',
        ]);

        $validator->after(function ($validator) use ($request, $isCivilian): void {
            if (! $isCivilian) {
                return;
            }

            $photoUpload = $request->file('evidence_photo_capture');
            $fallbackEvidence = $request->file('evidence');
            $videoUpload = $request->file('evidence_video_capture');
            $hasImageEvidence = $photoUpload !== null
                || ($fallbackEvidence !== null && Str::startsWith((string) $fallbackEvidence->getMimeType(), 'image/'));

            if (! $hasImageEvidence) {
                $validator->errors()->add('evidence', 'Capture or attach a scene photo before sending the emergency report.');
            }

            if ($videoUpload !== null && $photoUpload === null && ! $hasImageEvidence) {
                $validator->errors()->add('evidence_video_capture', 'A video alone is not enough. Capture a scene photo before sending the report.');
            }
        });

        $validated = $validator->validate();
        $ingestKey = $ingestor->onlineKey($request->user(), $validated['submission_id'] ?? null);

        if ($existing = $ingestor->existing($ingestKey)) {
            return redirect()
                ->route('reports.success', $existing)
                ->with('status', 'This emergency report was already received. The existing database record was kept.');
        }

        $description = trim((string) ($validated['description'] ?? ''));
        $descriptionForStorage = $description !== '' ? $description : 'No short description provided.';

        $preferredSeverity = $isCivilian ? null : ($validated['severity'] ?? null);
        // Descriptions are the only AI severity input. Disabled or unavailable
        // text inference leaves the report unassessed for responder review.
        $analysis = app(AiTextSeverityClient::class)->analyzeForReport($descriptionForStorage, $preferredSeverity);
        $severity = $analysis['severity'];
        $transmissionType = $isCivilian
            ? 'online'
            : $this->normalizeTransmissionType($validated['transmission_type'] ?? 'online');
        $incidentType = $isCivilian
            ? 'Road Accident'
            : ($validated['incident_type'] ?? 'General Emergency');
        $channel = $transmissionType === 'lora' ? 'LoRa Mesh' : 'Internet';
        $evidencePath = null;
        $evidenceOriginalName = null;
        $evidenceType = 'none';
        $selfiePath = null;
        $selfieOriginalName = null;
        $selfieUpload = $request->file('selfie_capture') ?: $request->file('selfie');
        $evidenceUpload = null;
        $photoEvidenceUpload = null;

        if ($selfieUpload) {
            $selfieOriginalName = $selfieUpload->getClientOriginalName();
        }

        if ($request->hasFile('evidence_photo_capture')) {
            $evidenceUpload = $request->file('evidence_photo_capture');
            $photoEvidenceUpload = $evidenceUpload;
            $evidenceOriginalName = $evidenceUpload->getClientOriginalName();
            $evidenceType = 'photo';
        } elseif ($request->hasFile('evidence_video_capture')) {
            $evidenceUpload = $request->file('evidence_video_capture');
            $evidenceOriginalName = $evidenceUpload->getClientOriginalName();
            $evidenceType = 'video';
        } elseif ($request->hasFile('evidence')) {
            $evidenceUpload = $request->file('evidence');
            $evidenceOriginalName = $evidenceUpload->getClientOriginalName();
            $mimeGroup = Str::before((string) $evidenceUpload->getMimeType(), '/');
            $evidenceType = $mimeGroup === 'video' ? 'video' : 'photo';
            if ($evidenceType === 'photo') {
                $photoEvidenceUpload = $evidenceUpload;
            }
        }

        if ($isCivilian && $photoEvidenceUpload !== null) {
            $preScreen = EvidencePhotoPreScreen::inspect($photoEvidenceUpload);

            if (! ($preScreen['accepted'] ?? true)) {
                throw ValidationException::withMessages([
                    'evidence' => $preScreen['rejection_message'] ?? EvidencePhotoPreScreen::REJECTION_MESSAGE,
                ]);
            }
        }

        if ($selfieUpload) {
            $selfiePath = $selfieUpload->store('incident-selfies');
        }

        if ($evidenceUpload) {
            $evidencePath = $evidenceUpload->store('incident-evidence');
        }

        $result = $ingestor->persist($ingestKey, [
            'report_code' => 'INC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'reported_by' => Auth::id(),
            'reporter_name' => Auth::user()?->name ?? 'Web Operator',
            'reporter_contact' => Auth::user()?->responderProfile?->phone,
            'incident_type' => $incidentType,
            'severity' => $severity,
            'status' => 'received',
            'channel' => $channel,
            'transmission_type' => $transmissionType,
            'location_text' => $validated['location_text'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'description' => $descriptionForStorage,
            'reported_fatality_flag' => (bool) ($analysis['reported_fatality_flag'] ?? false),
            'ai_summary' => $analysis['summary'],
            'ai_confidence' => $analysis['confidence'],
            'ai_source' => $analysis['source'],
            'ai_status' => $analysis['status'],
            'ai_model_name' => $analysis['model_name'],
            'ai_model_version' => $analysis['model_version'],
            'ai_review_required' => $analysis['review_required'],
            'ai_probabilities' => $analysis['probabilities'],
            'ai_processed_at' => $analysis['source'] === 'manual_only' ? null : now(),
            'ai_error_message' => null,
            'photo_relevance_label' => $analysis['photo_relevance_label'] ?? null,
            'photo_relevance_confidence' => $analysis['photo_relevance_confidence'] ?? null,
            'photo_relevance_probabilities' => $analysis['photo_relevance_probabilities'] ?? null,
            'photo_relevance_overridden' => $analysis['photo_relevance_overridden'] ?? false,
            'evidence_type' => $evidenceType,
            'evidence_path' => $evidencePath,
            'evidence_original_name' => $evidenceOriginalName,
            'reporter_selfie_path' => $selfiePath,
            'reporter_selfie_original_name' => $selfieOriginalName,
            'reporter_selfie_captured_at' => $selfiePath ? now() : null,
            'transmitted_at' => now(),
            'status_updated_at' => now(),
        ]);
        $report = $result['report'];

        if (! $result['created']) {
            Storage::delete(array_filter([$evidencePath, $selfiePath]));

            return redirect()
                ->route('reports.success', $report)
                ->with('status', 'This emergency report was already received. The existing database record was kept.');
        }

        IncidentFeedBroadcaster::dispatch($report, 'created');

        return redirect()->route('reports.success', $report);
    }

    public function show(IncidentReport $incidentReport): View
    {
        $this->ensureReportAccess(Auth::user(), $incidentReport);

        $report = $incidentReport->load(['reporter', 'assignedResponder']);
        $isCivilian = Auth::user()?->isCivilian() ?? false;

        if ($isCivilian) {
            return view('reports.show', [
                'viewerMode' => 'civilian',
                'report' => $report,
                'connectivity' => null,
                'fatalAlerts' => collect(),
            ]);
        }

        $commandCenter = $this->bontocCommandCenter();
        $locationMeta = $this->locationInsightsFor($report);
        $routeData = $report->latitude !== null && $report->longitude !== null ? [
            'status' => $locationMeta['route_status'],
            'status_label' => $locationMeta['route_status_label'],
            'provider' => $locationMeta['route_provider'],
            'distance_label' => $locationMeta['distance_label'],
            'travel_time_label' => $locationMeta['travel_time_label'],
            'summary' => collect([
                $locationMeta['distance_label'] ? 'Distance: '.$locationMeta['distance_label'] : null,
                $locationMeta['travel_time_label'] ? 'Travel time: '.$locationMeta['travel_time_label'] : null,
            ])->filter()->implode(' | '),
            'geometry' => $locationMeta['route_geometry'],
        ] : null;
        $mapPoints = collect();

        if ($report->latitude !== null && $report->longitude !== null) {
            $mapPoints->push([
                'id' => 'sender-location-'.$report->id,
                'incident_type' => $report->incident_type,
                'location_text' => $report->location_text,
                'severity' => $report->operational_severity,
                'severity_display' => $report->severity_display,
                'latitude' => (float) $report->latitude,
                'longitude' => (float) $report->longitude,
                'map_role' => 'sender',
                'readable_location' => $locationMeta['readable_location'],
                'barangay_town' => $locationMeta['barangay_town'],
                'distance_from_command_center' => $locationMeta['distance_label'],
                'travel_time_from_command_center' => $locationMeta['travel_time_label'],
                'google_maps_url' => $locationMeta['google_maps_url'],
                'directions_url' => $locationMeta['directions_url'],
                'route_status' => $locationMeta['route_status'],
                'route_provider' => $locationMeta['route_provider'],
            ]);

            $mapPoints->push([
                'id' => 'command-center-'.$report->id,
                'incident_type' => $commandCenter['name'],
                'location_text' => $commandCenter['location_text'],
                'severity' => 'Minor',
                'latitude' => $commandCenter['latitude'],
                'longitude' => $commandCenter['longitude'],
                'map_role' => 'command_center',
                'readable_location' => $commandCenter['location_text'],
                'barangay_town' => 'Bontoc, Southern Leyte',
                'distance_from_command_center' => '0 m',
                'travel_time_from_command_center' => '0 min',
                'google_maps_url' => $commandCenter['google_maps_url'],
                'directions_url' => $commandCenter['google_maps_url'],
            ]);
        }

        return view('reports.show', [
            'viewerMode' => $isCivilian ? 'civilian' : 'responder',
            'report' => $report,
            'commandCenter' => $commandCenter,
            'locationMeta' => $locationMeta,
            'responders' => User::query()
                ->where('is_admin', true)
                ->with('responderProfile')
                ->orderBy('name')
                ->get(),
            'dispatchRecommendation' => $this->dispatchRecommendationFor($report),
            'auditTrail' => $this->auditTrailFor($report),
            'connectivity' => $this->connectivityIntelligence(),
            'fatalAlerts' => IncidentReport::query()->operationallyAssessed()
                ->whereIn('severity', ['Critical', 'Fatal'])
                ->whereIn('status', ['received', 'acknowledged', 'dispatched', 'responding'])
                ->latest()
                ->take(6)
                ->get(),
            'mapPoints' => $mapPoints->values(),
            'routeData' => $routeData,
        ]);
    }

    public function success(IncidentReport $incidentReport): View
    {
        $this->ensureReportAccess(Auth::user(), $incidentReport);

        return view('reports.success', ['report' => $incidentReport]);
    }

    public function severity(IncidentReport $incidentReport): View
    {
        $this->ensureReportAccess(Auth::user(), $incidentReport);
        $isSuppressedLegacySuggestion = $incidentReport->isLegacyUnreviewedTextSuggestion();
        $isTextAssessment = str_starts_with((string) $incidentReport->ai_source, 'text_');
        $isPendingAssessment = $incidentReport->severity === null
            || $incidentReport->isAwaitingTextSeverityConfirmation()
            || in_array($incidentReport->ai_source, ['manual_only', 'text_review'], true)
            || $isSuppressedLegacySuggestion;
        $manualAiAudit = collect($incidentReport->coordination_log ?? [])
            ->filter(fn ($entry): bool => is_array($entry) && filled($entry['historical_ai_data_label'] ?? null))
            ->sortByDesc(fn (array $entry): string => (string) ($entry['occurred_at'] ?? ''))
            ->first();

        return view('reports.severity', [
            'report' => $incidentReport,
            'manualAiAudit' => $manualAiAudit,
            'analysis' => [
                'predicted' => $isSuppressedLegacySuggestion ? 'Historical suggestion (not operational)' : $incidentReport->severity_display,
                'confidence' => $incidentReport->ai_confidence !== null ? $incidentReport->ai_confidence.'%' : null,
                'advice' => $isSuppressedLegacySuggestion
                    ? $incidentReport->ai_summary
                    : ($isPendingAssessment ? $incidentReport->operational_ai_summary : ($isTextAssessment ? $incidentReport->ai_summary : $this->dispatchAdvice($incidentReport->severity))),
                'is_text' => $isTextAssessment,
                'is_pending' => $isPendingAssessment,
                'source' => $isSuppressedLegacySuggestion ? 'legacy_ai_audit' : ($incidentReport->ai_source ?? 'description_fallback'),
                'status' => $incidentReport->ai_status ?? 'complete',
                'review_required' => (bool) $incidentReport->ai_review_required,
                'model_name' => $isSuppressedLegacySuggestion
                    ? ($incidentReport->ai_model_name ?: 'Historical model unavailable')
                    : ($incidentReport->ai_model_name ?: ($isPendingAssessment ? 'No severity model used' : ($isTextAssessment ? 'No usable text assessment' : 'description_rules'))),
                'model_version' => $isSuppressedLegacySuggestion
                    ? $incidentReport->ai_model_version
                    : ($incidentReport->ai_model_version ?: ($isPendingAssessment ? null : ($isTextAssessment ? null : 'legacy'))),
                'processed_at' => optional($incidentReport->ai_processed_at)->format('M d, Y h:i A'),
                'error_message' => AiSeverityMapper::humanizeErrorMessage($incidentReport->ai_error_message),
                'photo_relevance_label' => $incidentReport->photo_relevance_label,
                'photo_relevance_confidence' => $incidentReport->photo_relevance_confidence,
                'photo_relevance_probabilities' => $incidentReport->photo_relevance_probabilities,
                'photo_relevance_overridden' => (bool) $incidentReport->photo_relevance_overridden,
            ],
        ]);
    }

    public function transmissions(IncidentReport $incidentReport): View
    {
        $this->ensureReportAccess(Auth::user(), $incidentReport);

        $delivered = $incidentReport->transmitted_at !== null;

        return view('reports.transmissions', [
            'report' => $incidentReport,
            'transmission' => [
                'channel' => $incidentReport->channel,
                'status' => $delivered ? 'Delivered' : 'Queued',
                'gateway' => $incidentReport->transmission_type === 'lora' ? 'Bontoc LoRa Gateway' : 'Laravel API Gateway',
                'next_retry' => $delivered ? 'Not required' : '10 seconds',
            ],
        ]);
    }

    public function updateCoordination(Request $request, IncidentReport $incidentReport): RedirectResponse
    {
        abort_if($request->user()?->isCivilian(), 403);

        $requestedAssignedResponderId = blank($request->input('assigned_responder_id'))
            ? null
            : (int) $request->input('assigned_responder_id');
        $requestedStatus = (string) $request->input('status', $incidentReport->status);
        $previewStatus = $this->normalizeQueueStatus($requestedStatus, $requestedAssignedResponderId);
        $requiresDecisionMessage = in_array($previewStatus, ['responding', 'rejected'], true)
            && $previewStatus !== $incidentReport->status;

        $validated = $request->validate([
            'assigned_responder_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_admin', true)],
            'status' => ['required', 'in:received,acknowledged,dispatched,responding,resolved,rejected,open,done,reject'],
            'reviewed_severity' => ['nullable', Rule::prohibitedIf(
                ! in_array($incidentReport->ai_source, ['manual_only', 'human_responder'], true)
                    && ! str_starts_with((string) $incidentReport->ai_source, 'text_')
            ), Rule::in(['Minor', 'Serious', 'Critical', 'Fatal'])],
            'response_notes' => [$requiresDecisionMessage ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [
            'response_notes.required' => 'Add a message before responding to or cancelling this incident report.',
        ]);

        $assignedResponderId = array_key_exists('assigned_responder_id', $validated)
            ? $validated['assigned_responder_id']
            : $incidentReport->assigned_responder_id;
        $assignedResponder = null;

        if ($assignedResponderId !== null) {
            $assignedResponder = User::query()
                ->whereKey($assignedResponderId)
                ->where('is_admin', true)
                ->firstOrFail();
        }

        $status = $this->normalizeQueueStatus($validated['status'], $assignedResponderId);
        $actor = $request->user();
        $previousAssignedResponderName = $incidentReport->assignedResponder?->name;
        $effectiveNotes = $request->has('response_notes')
            ? ($validated['response_notes'] ?? null)
            : $incidentReport->response_notes;

        $updates = [
            'assigned_responder_id' => $assignedResponderId,
            'status' => $status,
            'status_updated_at' => now(),
            'resolved_at' => in_array($status, ['resolved', 'rejected'], true) ? now() : null,
            'coordination_log' => array_values([
                ...($incidentReport->coordination_log ?? []),
                $this->buildCoordinationLogEntry(
                    actorName: $actor?->name ?? 'System',
                    actorRole: $actor?->is_admin ? 'Admin' : ($actor?->role === 'civilian' ? 'Civilian' : 'System'),
                    previousStatus: $incidentReport->status,
                    newStatus: $status,
                    previousAssignedResponderName: $previousAssignedResponderName,
                    assignedResponderName: $assignedResponder?->name,
                    responseNotes: $effectiveNotes,
                ),
            ]),
        ];

        if ($incidentReport->transmission_type === 'lora') {
            if ($status === 'received') {
                $updates['lora_ack_queued_at'] = null;
                $updates['lora_ack_delivered_at'] = null;
            } elseif ($status !== $incidentReport->status
                || $effectiveNotes !== $incidentReport->response_notes
                || $incidentReport->lora_ack_queued_at === null) {
                $updates['lora_ack_queued_at'] = now();
                $updates['lora_ack_delivered_at'] = null;
            }
        }

        if ($request->has('response_notes')) {
            $updates['response_notes'] = $validated['response_notes'] ?? null;
        }

        if (filled($validated['reviewed_severity'] ?? null)) {
            $reviewedSeverity = $validated['reviewed_severity'];
            $manualOnlyMode = IncidentReport::manualOnlyMode();
            $updates['severity'] = $reviewedSeverity;
            $updates['ai_source'] = $incidentReport->ai_source === 'manual_only' || $manualOnlyMode
                ? 'human_responder'
                : 'text_human_override';
            $updates['ai_status'] = 'complete';
            $updates['ai_review_required'] = false;
            $updates['ai_summary'] = $incidentReport->ai_source === 'manual_only' || $manualOnlyMode
                ? 'Responder assigned severity: '.$reviewedSeverity.'. No automated severity inference was used.'
                : 'Responder reviewed this report and assigned '.$reviewedSeverity
                    .'. Any retained text model scores describe the earlier AI suggestion, not the responder assessment.';
            if ($manualOnlyMode) {
                $updates['ai_confidence'] = null;
                $updates['ai_probabilities'] = null;
                $updates['ai_model_name'] = null;
                $updates['ai_model_version'] = null;
                $updates['ai_processed_at'] = null;
            }
            $updates['coordination_log'][] = [
                'label' => 'Responder severity review: '.$reviewedSeverity,
                'actor_name' => $actor?->name ?? 'System',
                'actor_role' => 'Admin',
                'actor_id' => $actor?->id,
                'status' => $status,
                'assigned_responder_name' => $assignedResponder?->name,
                'response_notes' => $effectiveNotes,
                'previous_severity' => $incidentReport->severity_display,
                'previous_ai_source' => $incidentReport->ai_source,
                'previous_ai_summary' => $incidentReport->ai_summary,
                'previous_ai_confidence' => $incidentReport->ai_confidence,
                'previous_ai_probabilities' => $incidentReport->ai_probabilities,
                'previous_ai_model_name' => $incidentReport->ai_model_name,
                'previous_ai_model_version' => $incidentReport->ai_model_version,
                'historical_ai_data_label' => $incidentReport->isLegacyUnreviewedTextSuggestion()
                    ? 'Legacy AI suggestion; audit only; not used for responder severity.'
                    : null,
                'previous_stored_severity' => $incidentReport->severity,
                'reviewed_severity' => $reviewedSeverity,
                'occurred_at' => now()->toIso8601String(),
            ];
        }

        $incidentReport->update($updates);

        IncidentFeedBroadcaster::dispatch($incidentReport, 'updated');

        return back()->with('status', 'Incident coordination updated.');
    }

    public function evidence(Request $request, IncidentReport $incidentReport): StreamedResponse
    {
        $this->ensureReportAccess($request->user(), $incidentReport);

        return $this->streamStoredFile(
            $request,
            $incidentReport->evidence_path,
            $incidentReport->evidence_original_name
        );
    }

    public function selfie(Request $request, IncidentReport $incidentReport): StreamedResponse
    {
        $this->ensureReportAccess($request->user(), $incidentReport);

        return $this->streamStoredFile(
            $request,
            $incidentReport->reporter_selfie_path,
            $incidentReport->reporter_selfie_original_name
        );
    }

    public function destroy(Request $request, IncidentReport $incidentReport): RedirectResponse
    {
        $this->ensureReportAccess($request->user(), $incidentReport);

        Storage::delete(array_filter([
            $incidentReport->evidence_path,
            $incidentReport->reporter_selfie_path,
        ]));

        $incidentReport->delete();

        $redirectRoute = $request->input('return_to') === 'monitoring'
            ? 'monitoring'
            : 'reports.index';

        return redirect()
            ->route($redirectRoute)
            ->with('status', 'Report deleted successfully.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        abort_if($request->user()?->isCivilian(), 403);

        $deletedCount = 0;

        $this->visibleReportsQuery($request->user())
            ->select(['id', 'evidence_path', 'reporter_selfie_path'])
            ->orderBy('id')
            ->chunkById(100, function ($reports) use (&$deletedCount): void {
                $paths = $reports
                    ->flatMap(fn (IncidentReport $report): array => [
                        $report->evidence_path,
                        $report->reporter_selfie_path,
                    ])
                    ->filter()
                    ->values()
                    ->all();

                Storage::delete($paths);

                foreach ($reports as $report) {
                    $report->delete();
                    $deletedCount++;
                }
            });

        $message = $deletedCount === 0
            ? 'No reports were available to delete.'
            : $deletedCount.' '.Str::plural('report', $deletedCount).' deleted successfully.';

        return redirect()
            ->route('reports.index')
            ->with('status', $message);
    }

    private function dispatchAdvice(string $severity): string
    {
        return match ($severity) {
            'Critical', 'Fatal' => 'Dispatch the nearest medical and extraction units immediately and escalate command coordination.',
            'Serious' => 'Prioritize this incident for rapid admin receiver assignment and route confirmation.',
            default => 'Monitor closely, confirm scene details, and assign field support if conditions worsen.',
        };
    }

    private function normalizeSeverity(string $severity): string
    {
        return AiSeverityMapper::normalizeSeverity($severity);
    }

    private function normalizeTransmissionType(string $transmissionType): string
    {
        return match (Str::lower(trim($transmissionType))) {
            'lora' => 'lora',
            default => 'online',
        };
    }

    private function normalizeQueueStatus(string $status, ?int $assignedResponderId): string
    {
        return match (Str::lower(trim($status))) {
            'done' => 'resolved',
            'reject' => 'rejected',
            'open' => $assignedResponderId !== null ? 'responding' : 'received',
            default => $status,
        };
    }

    private function visibleReportsQuery(?User $user): Builder
    {
        return IncidentReport::query()
            ->when(
                $user?->isCivilian(),
                fn (Builder $query) => $query->where('reported_by', $user?->id)
            );
    }

    private function redirectUnverifiedCivilian(?User $user): ?RedirectResponse
    {
        if ($user?->isCivilian() && ! $user->hasVerifiedAccount()) {
            return redirect()
                ->route('account.verify')
                ->withErrors(['verification' => 'Verify your civilian account with live facial verification before sending an emergency report.']);
        }

        return null;
    }

    private function ensureReportAccess(?User $user, IncidentReport $report): void
    {
        if ($user?->isCivilian() && (int) $report->reported_by !== (int) $user->id) {
            abort(404);
        }
    }

    private function dispatchRecommendationFor(IncidentReport $report): array
    {
        if ($report->reported_fatality_flag) {
            return [
                'priority' => 'Critical priority — possible fatality reported',
                'response_type' => 'Urgent responder verification required; fatality is unconfirmed',
                'recommended_responder' => $report->assignedResponder?->name ?? 'Admin receiver pending',
                'timer_label' => $this->priorityTimerLabel($report),
                'summary' => 'The description contains “patay”. Treat this as a possible fatality alert; a responder must verify the report.',
            ];
        }

        if ($report->operational_severity === null || $report->isOperationalSeveritySuppressed()) {
            return [
                'priority' => 'Needs responder review',
                'response_type' => 'Unassessed description; verify urgency',
                'recommended_responder' => $report->assignedResponder?->name ?? 'Admin receiver pending',
                'timer_label' => $this->priorityTimerLabel($report),
                'summary' => 'Verify the description and establish severity before relying on an AI dispatch recommendation.',
            ];
        }

        $priority = match ($report->operational_severity) {
            'Critical', 'Fatal' => 'Priority 1',
            'Serious' => 'Priority 2',
            default => 'Priority 3',
        };

        $responseType = match ($report->operational_severity) {
            'Critical', 'Fatal' => 'Immediate medical and extraction support',
            'Serious' => 'Rapid structural or trauma assessment',
            default => 'Verification and scene control',
        };

        return [
            'priority' => $priority,
            'response_type' => $responseType,
            'recommended_responder' => $report->assignedResponder?->name ?? 'Admin receiver pending',
            'timer_label' => $this->priorityTimerLabel($report),
            'summary' => $this->dispatchAdvice($report->operational_severity),
        ];
    }

    private function operationalSeverityOrderSql(): string
    {
        return "case when reported_fatality_flag = 1 then 0 when severity is null or ai_source in ('manual_only', 'text_review', 'text_model', 'text_model_experimental', 'text_rule_advisory') then 4 "
            ."when severity in ('Critical', 'Fatal') then 1 when severity = 'Serious' then 2 "
            ."when severity = 'Minor' then 3 else 4 end";
    }

    private function bontocCommandCenter(): array
    {
        $latitude = 10.354270414923162;
        $longitude = 124.97039989612004;

        return [
            'name' => 'Bontoc Command Center',
            'location_text' => 'GPS 10.354270, 124.970400',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'google_maps_url' => $this->googleMapsUrl($latitude, $longitude, 'Bontoc Command Center'),
        ];
    }

    private function locationInsightsFor(IncidentReport $report): array
    {
        $readableLocation = $this->readableLocationFrom($report->location_text);
        $barangayTown = $this->barangayTownFrom($readableLocation);
        $googleMapsUrl = $this->googleMapsUrl(
            $report->latitude !== null ? (float) $report->latitude : null,
            $report->longitude !== null ? (float) $report->longitude : null,
            $report->location_text
        );

        $distanceKm = null;
        $travelMinutes = null;
        $routeStatus = 'estimated';
        $routeStatusLabel = 'Estimated fallback';
        $routeProvider = 'Direct-distance estimate';
        $routeBasisLabel = 'Estimated emergency drive time from '.$this->bontocCommandCenter()['name'].' to the civilian sender location.';
        $routeNotice = null;
        $routeGeometry = [];
        $routeSteps = [];

        if ($report->latitude !== null && $report->longitude !== null) {
            $commandCenter = $this->bontocCommandCenter();
            $distanceKm = $this->distanceInKilometers(
                (float) $report->latitude,
                (float) $report->longitude,
                $commandCenter['latitude'],
                $commandCenter['longitude'],
            );

            $travelMinutes = $this->estimatedTravelMinutes($distanceKm);
            $routeClient = app(RouteNavigationClient::class);

            if ($routeClient->enabled()) {
                try {
                    $route = $routeClient->route(
                        $commandCenter['latitude'],
                        $commandCenter['longitude'],
                        (float) $report->latitude,
                        (float) $report->longitude,
                    );

                    if (($route['distance_meters'] ?? 0) > 0 && ($route['duration_seconds'] ?? 0) > 0) {
                        $distanceKm = ((float) $route['distance_meters']) / 1000;
                        $travelMinutes = max(1, (int) ceil(((int) $route['duration_seconds']) / 60));
                        $routeGeometry = is_array($route['geometry'] ?? null) ? $route['geometry'] : [];
                        $routeSteps = collect($route['steps'] ?? [])
                            ->filter(fn ($step): bool => is_array($step))
                            ->map(function (array $step): array {
                                $stepTravelMinutes = max(1, (int) ceil(((int) ($step['duration_seconds'] ?? 0)) / 60));

                                return [
                                    'instruction' => $step['instruction'] ?? 'Continue to the incident location.',
                                    'distance_label' => $this->formatDistanceLabel(((float) ($step['distance_meters'] ?? 0)) / 1000) ?? 'Route segment',
                                    'travel_time_label' => $this->formatTravelTimeLabel($stepTravelMinutes) ?? 'Keep moving',
                                    'road_name' => $step['road_name'] ?? null,
                                    'mode' => $step['mode'] ?? 'driving',
                                ];
                            })
                            ->values()
                            ->all();
                        $routeStatus = 'live';
                        $routeStatusLabel = 'Live road route';
                        $routeProvider = (string) ($route['provider'] ?? 'Routing service');
                        $routeBasisLabel = 'Road route guidance from '.$commandCenter['name'].' to the civilian sender location.';
                    }
                } catch (\Throwable $exception) {
                    report($exception);

                    $routeNotice = 'Live road routing is temporarily unavailable, so the system is showing a safe fallback estimate for dispatch planning.';
                }
            }
        }

        return [
            'readable_location' => $readableLocation,
            'barangay_town' => $barangayTown,
            'distance_km' => $distanceKm,
            'distance_label' => $this->formatDistanceLabel($distanceKm),
            'travel_time_minutes' => $travelMinutes,
            'travel_time_label' => $this->formatTravelTimeLabel($travelMinutes),
            'google_maps_url' => $googleMapsUrl,
            'directions_url' => $this->directionsUrl(
                $report->latitude !== null ? (float) $report->latitude : null,
                $report->longitude !== null ? (float) $report->longitude : null,
            ),
            'route_status' => $routeStatus,
            'route_status_label' => $routeStatusLabel,
            'route_provider' => $routeProvider,
            'route_basis_label' => $routeBasisLabel,
            'route_notice' => $routeNotice,
            'route_geometry' => $routeGeometry,
            'route_steps' => $routeSteps,
        ];
    }

    private function readableLocationFrom(?string $locationText): string
    {
        if (blank($locationText)) {
            return 'Location not available';
        }

        $segments = collect(explode('|', (string) $locationText))
            ->map(static fn (string $segment): string => trim($segment))
            ->filter()
            ->values();

        $readableLocation = $segments->first(
            fn (string $segment): bool => ! Str::startsWith(Str::upper($segment), 'GPS ')
        );

        return $readableLocation ?: trim((string) $locationText);
    }

    private function barangayTownFrom(string $readableLocation): ?string
    {
        $parts = collect(explode(',', $readableLocation))
            ->map(static fn (string $part): string => trim($part))
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return null;
        }

        if ($parts->count() >= 3) {
            return $parts->slice($parts->count() - 3, 2)->implode(', ');
        }

        if ($parts->count() >= 2) {
            return $parts->slice($parts->count() - 2, 2)->implode(', ');
        }

        return $parts->first();
    }

    private function googleMapsUrl(?float $latitude, ?float $longitude, ?string $locationText): string
    {
        if ($latitude !== null && $longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode($latitude.','.$longitude);
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode((string) ($locationText ?: 'Bontoc Command Center'));
    }

    private function distanceInKilometers(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadiusKm = 6371;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);

        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    private function formatDistanceLabel(?float $distanceKm): ?string
    {
        if ($distanceKm === null) {
            return null;
        }

        if ($distanceKm < 1) {
            return (string) max(1, (int) round($distanceKm * 1000)).' m';
        }

        return number_format($distanceKm, 2).' km';
    }

    private function estimatedTravelMinutes(?float $distanceKm): ?int
    {
        if ($distanceKm === null) {
            return null;
        }

        $averageEmergencySpeedKmh = 35;

        return max(1, (int) ceil(($distanceKm / $averageEmergencySpeedKmh) * 60));
    }

    private function formatTravelTimeLabel(?int $travelMinutes): ?string
    {
        if ($travelMinutes === null) {
            return null;
        }

        if ($travelMinutes < 60) {
            return $travelMinutes.' min';
        }

        $hours = intdiv($travelMinutes, 60);
        $minutes = $travelMinutes % 60;

        if ($minutes === 0) {
            return $hours.' hr';
        }

        return $hours.' hr '.$minutes.' min';
    }

    private function directionsUrl(?float $destinationLatitude, ?float $destinationLongitude): string
    {
        $commandCenter = $this->bontocCommandCenter();

        if ($destinationLatitude !== null && $destinationLongitude !== null) {
            return 'https://www.google.com/maps/dir/?api=1&origin='
                .rawurlencode($commandCenter['latitude'].','.$commandCenter['longitude'])
                .'&destination='
                .rawurlencode($destinationLatitude.','.$destinationLongitude)
                .'&travelmode=driving';
        }

        return $commandCenter['google_maps_url'];
    }

    private function connectivityIntelligence(): array
    {
        $recentReports = IncidentReport::query()
            ->latest()
            ->take(12)
            ->get();

        $loraRecent = $recentReports->where('transmission_type', 'lora')->count();
        $onlineRecent = $recentReports->where('transmission_type', 'online')->count();

        return [
            'online_status' => $onlineRecent > 0 ? 'Online gateway stable' : 'Online standby',
            'lora_status' => $loraRecent > 0 ? 'LoRa fallback active' : 'LoRa standby',
            'internet_status' => $loraRecent > $onlineRecent ? 'Internet degraded - fallback preferred' : 'Internet uplink healthy',
            'gateway_status' => $loraRecent > 0 || $onlineRecent > 0 ? 'Gateway synchronized' : 'Awaiting field traffic',
            'websocket_status' => 'Reverb live sync online',
            'queue_status' => 'Immediate broadcast channel',
            'api_status' => 'Laravel API healthy',
        ];
    }

    private function priorityTimerLabel(IncidentReport $report): string
    {
        if ($report->created_at === null) {
            return 'Just received';
        }

        $minutes = max(1, (int) ceil($report->created_at->diffInMinutes(now())));

        return $minutes.' min active';
    }

    private function auditTrailFor(IncidentReport $report): array
    {
        $timeline = collect($report->coordination_log ?? [])
            ->filter(fn ($entry): bool => is_array($entry))
            ->sortByDesc(fn (array $entry): string => (string) ($entry['occurred_at'] ?? ''))
            ->values()
            ->all();

        if ($timeline !== []) {
            return $timeline;
        }

        return [[
            'label' => 'Incident received',
            'actor_name' => $report->reporter_name ?: 'System',
            'actor_role' => 'Civilian',
            'status' => $report->status,
            'assigned_responder_name' => $report->assignedResponder?->name,
            'response_notes' => $report->response_notes,
            'occurred_at' => optional($report->created_at)->toIso8601String(),
        ]];
    }

    private function buildCoordinationLogEntry(
        string $actorName,
        string $actorRole,
        string $previousStatus,
        string $newStatus,
        ?string $previousAssignedResponderName,
        ?string $assignedResponderName,
        ?string $responseNotes,
    ): array {
        return [
            'label' => $this->coordinationLabelFor(
                previousStatus: $previousStatus,
                newStatus: $newStatus,
                previousAssignedResponderName: $previousAssignedResponderName,
                assignedResponderName: $assignedResponderName,
            ),
            'actor_name' => $actorName,
            'actor_role' => $actorRole,
            'status' => $newStatus,
            'assigned_responder_name' => $assignedResponderName,
            'response_notes' => $responseNotes,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    private function coordinationLabelFor(
        string $previousStatus,
        string $newStatus,
        ?string $previousAssignedResponderName,
        ?string $assignedResponderName,
    ): string {
        if ($newStatus === 'rejected') {
            return 'Rejected incident';
        }

        if ($newStatus === 'resolved') {
            return 'Marked incident done';
        }

        if (in_array($previousStatus, ['resolved', 'rejected'], true) && in_array($newStatus, ['received', 'responding'], true)) {
            return 'Reopened incident';
        }

        if ($previousAssignedResponderName !== $assignedResponderName && $assignedResponderName !== null) {
            return 'Assigned admin receiver';
        }

        if ($previousAssignedResponderName !== null && $assignedResponderName === null) {
            return 'Removed admin receiver';
        }

        if ($newStatus === 'responding') {
            return 'Opened incident dispatch';
        }

        return 'Updated incident coordination';
    }

    private function streamStoredFile(Request $request, ?string $path, ?string $originalName): StreamedResponse
    {
        abort_if(blank($path), 404);

        $filename = $originalName ?? basename((string) $path);

        if ($request->boolean('download')) {
            return Storage::download($path, $filename);
        }

        $response = Storage::response($path, $filename);
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }
}
