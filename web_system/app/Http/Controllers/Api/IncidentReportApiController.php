<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use App\Services\AiTextSeverityClient;
use App\Support\AiSeverityMapper;
use App\Support\EvidencePhotoPreScreen;
use App\Support\IncidentFeedBroadcaster;
use App\Support\IncidentReportIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class IncidentReportApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reports = IncidentReport::query()
            ->with(['assignedResponder'])
            ->when($user->isCivilian(), fn ($query) => $query->where('reported_by', $user->id))
            ->when(! $user->isCivilian() && $request->filled('severity'), fn ($query) => $query->operationallyAssessed()->where('severity', $this->normalizeSeverity((string) $request->string('severity'))))
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->string('status')))
            ->when($request->string('sort')->toString() === 'oldest', fn ($query) => $query->oldest(), fn ($query) => $query->latest())
            ->get();

        return response()->json([
            'data' => $reports->map(fn (IncidentReport $report): array => $this->reportPayload($report, $user))->values(),
        ]);
    }

    public function store(Request $request, IncidentReportIngestor $ingestor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isCivilian = $user->isCivilian();

        if ($isCivilian && ! $user->hasVerifiedAccount()) {
            return response()->json([
                'message' => 'Verify your civilian account with live facial verification before sending an emergency report.',
                'verification_required' => true,
            ], Response::HTTP_FORBIDDEN);
        }

        $usesManualGpsFallback = $isCivilian && $request->boolean('gps_fallback_used');

        if (! $request->filled('submission_id') && $request->hasHeader('Idempotency-Key')) {
            $request->merge(['submission_id' => $request->header('Idempotency-Key')]);
        }

        $validator = Validator::make($request->all(), [
            'submission_id' => ['nullable', 'string', 'max:100'],
            'incident_type' => ['required', 'string', 'max:80'],
            'severity' => ['nullable', 'string', 'max:20'],
            'location_text' => ['required', 'string', 'max:180'],
            'gps_fallback_used' => ['nullable', 'boolean'],
            'latitude' => [Rule::requiredIf($isCivilian && ! $usesManualGpsFallback), 'nullable', 'numeric'],
            'longitude' => [Rule::requiredIf($isCivilian && ! $usesManualGpsFallback), 'nullable', 'numeric'],
            'description' => ['required', 'string', 'max:1000'],
            'transmission_type' => ['nullable', 'in:auto,online,lora'],
            'evidence_type' => ['nullable', 'in:none,photo,video'],
            'evidence' => ['nullable', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi,3gp'],
            'selfie' => [
                $isCivilian ? 'required' : 'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp',
            ],
        ], [
            'latitude.required' => 'Lock GPS or type a manual location before sending the emergency report.',
            'longitude.required' => 'Lock GPS or type a manual location before sending the emergency report.',
            'description.required' => 'Describe the emergency before sending. The AI severity review uses this text as its main basis.',
        ]);

        $validator->after(function ($validator) use ($request, $isCivilian): void {
            if (! $isCivilian) {
                return;
            }

            $evidenceUpload = $request->file('evidence');
            $hasImageEvidence = $evidenceUpload !== null
                && Str::startsWith((string) $evidenceUpload->getMimeType(), 'image/');

            if (! $hasImageEvidence) {
                $validator->errors()->add('evidence', 'Capture or attach a scene photo before sending the emergency report.');
            }
        });

        $validated = $validator->validate();
        $ingestKey = $ingestor->onlineKey($user, $validated['submission_id'] ?? null);

        if ($existing = $ingestor->existing($ingestKey)) {
            return response()->json([
                'message' => 'Incident report already exists.',
                'duplicate' => true,
                'data' => $this->reportPayload($existing->load('assignedResponder'), $user),
            ]);
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
            : $this->normalizeTransmissionType($validated['transmission_type'] ?? 'auto');
        $incidentType = $isCivilian
            ? 'Road Accident'
            : $validated['incident_type'];
        $channel = $transmissionType === 'lora' ? 'LoRa Mesh' : 'Internet';
        $evidenceType = $validated['evidence_type'] ?? 'none';
        $evidenceUpload = null;
        $evidencePath = null;
        $evidenceOriginalName = null;
        $selfiePath = null;
        $selfieOriginalName = null;
        $photoEvidenceUpload = null;

        if ($request->hasFile('selfie')) {
            $selfieOriginalName = $request->file('selfie')->getClientOriginalName();
        }

        if ($transmissionType === 'online' && $request->hasFile('evidence')) {
            $evidenceUpload = $request->file('evidence');
            $mimeGroup = Str::before((string) $evidenceUpload->getMimeType(), '/');
            $evidenceType = $mimeGroup === 'video' ? 'video' : 'photo';
            $photoEvidenceUpload = $evidenceType === 'photo' ? $evidenceUpload : null;
            $evidenceOriginalName = $evidenceUpload->getClientOriginalName();
        } else {
            $evidenceType = 'none';
        }

        if ($isCivilian && $photoEvidenceUpload !== null) {
            $preScreen = EvidencePhotoPreScreen::inspect($photoEvidenceUpload);

            if (! ($preScreen['accepted'] ?? true)) {
                throw ValidationException::withMessages([
                    'evidence' => $preScreen['rejection_message'] ?? EvidencePhotoPreScreen::REJECTION_MESSAGE,
                ]);
            }
        }

        if ($request->hasFile('selfie')) {
            $selfiePath = $request->file('selfie')->store('incident-selfies');
        }

        if ($evidenceUpload !== null) {
            $evidencePath = $evidenceUpload->store('incident-evidence');
        }

        $result = $ingestor->persist($ingestKey, [
            'report_code' => 'INC-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'reported_by' => $user->id,
            'reporter_name' => $user->name,
            'reporter_contact' => $user->responderProfile?->phone,
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

            return response()->json([
                'message' => 'Incident report already exists.',
                'duplicate' => true,
                'data' => $this->reportPayload($report->load('assignedResponder'), $user),
            ]);
        }

        IncidentFeedBroadcaster::dispatch($report, 'created');

        return response()->json([
            'message' => 'Incident report submitted successfully.',
            'duplicate' => false,
            'data' => $this->reportPayload($report->fresh('assignedResponder'), $user),
        ], 201);
    }

    public function show(Request $request, IncidentReport $incidentReport): JsonResponse
    {
        $this->authorizeReportAccess($request->user(), $incidentReport);

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->reportPayload($incidentReport->load('assignedResponder'), $user),
        ]);
    }

    public function evidence(Request $request, IncidentReport $incidentReport): Response
    {
        $this->authorizeReportAccess($request->user(), $incidentReport);

        abort_unless($incidentReport->evidence_path, 404);

        return $this->streamStoredMedia(
            path: $incidentReport->evidence_path,
            filename: $incidentReport->evidence_original_name ?: basename($incidentReport->evidence_path),
            download: $request->boolean('download'),
        );
    }

    public function selfie(Request $request, IncidentReport $incidentReport): Response
    {
        $this->authorizeReportAccess($request->user(), $incidentReport);

        abort_unless($incidentReport->reporter_selfie_path, 404);

        return $this->streamStoredMedia(
            path: $incidentReport->reporter_selfie_path,
            filename: $incidentReport->reporter_selfie_original_name ?: basename($incidentReport->reporter_selfie_path),
            download: $request->boolean('download'),
        );
    }

    private function reportPayload(IncidentReport $report, User $viewer): array
    {
        $clientPayload = [
            'id' => $report->id,
            'report_code' => $report->report_code,
            'incident_type' => $report->incident_type,
            'status' => $report->status,
            'location_text' => $report->location_text,
            'description' => $report->description,
            'evidence_available' => $report->evidence_path !== null,
            'selfie_available' => $report->reporter_selfie_path !== null,
            'status_updated_at' => optional($report->status_updated_at)->toIso8601String(),
            'created_at' => optional($report->created_at)->toIso8601String(),
        ];

        if ($viewer->isCivilian()) {
            return $clientPayload;
        }

        return array_merge($clientPayload, [
            'reported_fatality_flag' => (bool) $report->reported_fatality_flag,
            'reporter_name' => $report->reporter_name,
            'reporter_contact' => $report->reporter_contact,
            'severity' => $report->operational_severity,
            'severity_display' => $report->severity_display,
            'severity_is_provisional' => $report->operational_severity === null || $report->ai_source === 'text_review',
            'ai_score_label' => $report->ai_score_display,
            'channel' => $report->channel,
            'transmission_type' => $report->transmission_type,
            'latitude' => $report->latitude,
            'longitude' => $report->longitude,
            'ai_summary' => $report->operational_ai_summary,
            'ai_confidence' => $report->operational_ai_confidence,
            'ai_source' => $report->operational_ai_source,
            'ai_status' => $report->operational_ai_status,
            'ai_model_name' => $report->operational_ai_model_name,
            'ai_model_version' => $report->operational_ai_model_version,
            'ai_review_required' => $report->operational_ai_review_required,
            'ai_probabilities' => $report->operational_ai_probabilities,
            'ai_processed_at' => $report->isOperationalSeveritySuppressed() ? null : optional($report->ai_processed_at)->toIso8601String(),
            'ai_error_message' => $report->ai_error_message,
            'photo_relevance_label' => $report->photo_relevance_label,
            'photo_relevance_confidence' => $report->photo_relevance_confidence,
            'photo_relevance_probabilities' => $report->photo_relevance_probabilities,
            'photo_relevance_overridden' => (bool) $report->photo_relevance_overridden,
            'evidence_type' => $report->evidence_type,
            'evidence_original_name' => $report->evidence_original_name,
            'evidence_url' => $report->evidence_path ? route('api.reports.evidence', $report) : null,
            'selfie_original_name' => $report->reporter_selfie_original_name,
            'selfie_url' => $report->reporter_selfie_path ? route('api.reports.selfie', $report) : null,
            'selfie_captured_at' => optional($report->reporter_selfie_captured_at)->toIso8601String(),
            'assigned_responder_name' => $report->assignedResponder?->name,
            'assigned_admin_name' => $report->assignedResponder?->name,
            'response_notes' => $report->response_notes,
            'hardware_source' => $report->hardware_source,
            'lora_sender_id' => $report->lora_sender_id,
            'lora_sequence' => $report->lora_sequence,
            'lora_gateway_id' => $report->lora_gateway_id,
            'lora_gateway_rssi' => $report->lora_gateway_rssi,
            'lora_gateway_snr' => $report->lora_gateway_snr,
            'lora_receiver_rssi' => $report->lora_receiver_rssi,
            'lora_receiver_snr' => $report->lora_receiver_snr,
            'lora_satellites' => $report->lora_satellites,
            'transmitted_at' => optional($report->transmitted_at)->toIso8601String(),
        ]);
    }

    private function normalizeTransmissionType(string $transmissionType): string
    {
        return match (Str::lower(trim($transmissionType))) {
            'lora' => 'lora',
            default => 'online',
        };
    }

    private function normalizeSeverity(string $severity): string
    {
        return AiSeverityMapper::normalizeSeverity($severity);
    }

    private function authorizeReportAccess(?User $user, IncidentReport $report): void
    {
        abort_unless($user, 401);

        if ($user->isCivilian() && $report->reported_by !== $user->id) {
            abort(404);
        }
    }

    private function streamStoredMedia(string $path, string $filename, bool $download = false): Response
    {
        abort_unless(Storage::exists($path), 404);

        if ($download) {
            return Storage::download($path, $filename);
        }

        return response()->file(Storage::path($path));
    }
}
