<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $reportQuery = IncidentReport::query()->with(['assignedResponder']);

        if ($user->isCivilian()) {
            $reportQuery->where('reported_by', $user->id);
        }

        $reports = $reportQuery->latest()->take(6)->get();
        $activeQuery = IncidentReport::query()->whereIn('status', ['received', 'acknowledged', 'dispatched', 'responding']);
        $scopeQuery = $user->isCivilian()
            ? $activeQuery->where('reported_by', $user->id)
            : IncidentReport::query()->whereIn('status', ['received', 'acknowledged', 'dispatched', 'responding']);

        $summary = [
            'active_alerts' => (clone $scopeQuery)->count(),
            'my_reports' => IncidentReport::query()->where('reported_by', $user->id)->count(),
            'transmitted_today' => IncidentReport::query()
                ->when($user->isCivilian(), fn ($query) => $query->where('reported_by', $user->id))
                ->whereDate('transmitted_at', today())
                ->count(),
        ];

        if ($user->isCivilian()) {
            $summary['resolved_reports'] = IncidentReport::query()
                ->where('reported_by', $user->id)
                ->where('status', 'resolved')
                ->count();
        } else {
            $summary += [
                'assigned_to_me' => IncidentReport::query()
                    ->where('assigned_responder_id', $user->id)
                    ->whereIn('status', ['acknowledged', 'dispatched', 'responding'])
                    ->count(),
                'fatal_reports' => IncidentReport::query()->operationallyAssessed()->whereIn('severity', ['Critical', 'Fatal'])->count(),
                'needs_review' => IncidentReport::query()->needsResponderReview()->count(),
                'online_reports' => IncidentReport::query()->where('transmission_type', 'online')->count(),
                'lora_reports' => IncidentReport::query()->where('transmission_type', 'lora')->count(),
            ];
        }

        $notifications = $reports->take(3)->map(function (IncidentReport $report) use ($user): string {
            if ($user->isCivilian()) {
                return $report->report_code.' - '.ucfirst($report->status);
            }

            return $report->incident_type.' - '.$report->severity_display.' - '.$report->location_text;
        })->values();

        return response()->json([
            'application' => config('app.name'),
            'user' => $this->userPayload($user->fresh('responderProfile')),
            'summary' => $summary,
            'recent_reports' => $reports->map(fn (IncidentReport $report): array => $this->reportPayload($report, $user))->values(),
            'map_points' => $user->isCivilian()
                ? []
                : $reports
                    ->filter(fn (IncidentReport $report): bool => $report->latitude !== null && $report->longitude !== null)
                    ->map(fn (IncidentReport $report): array => [
                        'id' => $report->id,
                        'label' => $report->incident_type,
                        'severity' => $report->operational_severity,
                        'severity_display' => $report->severity_display,
                        'severity_is_provisional' => $report->operational_severity === null || $report->ai_source === 'text_review',
                        'ai_score_label' => $report->ai_score_display,
                        'latitude' => (float) $report->latitude,
                        'longitude' => (float) $report->longitude,
                    ])
                    ->values(),
            'notifications' => $notifications,
        ]);
    }

    private function userPayload(User $user): array
    {
        $profile = $user->responderProfile;

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->is_admin ? 'admin' : $user->role,
            'is_admin' => (bool) $user->is_admin,
            'account_verified' => $user->hasVerifiedAccount(),
            'account_verified_at' => optional($user->account_verified_at)->toIso8601String(),
            'phone' => $profile?->phone,
            'notification_profile' => $profile?->notification_profile ?? 'push_notifications',
        ];

        if (! $user->isCivilian()) {
            $payload['station'] = $profile?->assigned_station ?? 'Bontoc HQ';
            $payload['connectivity_mode'] = $profile?->connectivity_mode ?? 'auto_select';
        }

        return $payload;
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
            'evidence_available' => $report->evidence_path !== null,
            'evidence_url' => $report->evidence_path ? route('api.reports.evidence', $report) : null,
            'selfie_original_name' => $report->reporter_selfie_original_name,
            'selfie_available' => $report->reporter_selfie_path !== null,
            'selfie_url' => $report->reporter_selfie_path ? route('api.reports.selfie', $report) : null,
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
}
