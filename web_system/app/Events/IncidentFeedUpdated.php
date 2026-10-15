<?php

namespace App\Events;

use App\Models\IncidentReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentFeedUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public IncidentReport $report,
        public string $action = 'created',
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('responders.incidents');
    }

    public function broadcastAs(): string
    {
        return 'incident.feed.updated';
    }

    public function broadcastWith(): array
    {
        $report = $this->report->loadMissing(['assignedResponder']);
        $priorityMinutes = $this->priorityMinutesFor($report);
        $recommendation = $this->recommendationFor($report);

        return [
            'action' => $this->action,
            'message' => $this->messageFor($report),
            'report' => [
                'id' => $report->id,
                'report_code' => $report->report_code,
                'incident_type' => $report->incident_type,
                'severity' => $report->operational_severity,
                'severity_display' => $report->severity_display,
                'reported_fatality_flag' => (bool) $report->reported_fatality_flag,
                'severity_is_provisional' => $report->operational_severity === null || $report->ai_source === 'text_review',
                'ai_score_label' => $report->ai_score_display,
                'status' => $report->status,
                'channel' => $report->channel,
                'transmission_type' => $report->transmission_type,
                'location_text' => $report->location_text,
                'latitude' => $report->latitude !== null ? (float) $report->latitude : null,
                'longitude' => $report->longitude !== null ? (float) $report->longitude : null,
                'description' => $report->description,
                'reporter_name' => $report->reporter_name,
                'reporter_contact' => $report->reporter_contact,
                'assigned_responder_name' => $report->assignedResponder?->name,
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
                'evidence_type' => $report->evidence_type,
                'evidence_available' => $report->evidence_path !== null,
                'selfie_available' => $report->reporter_selfie_path !== null,
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
                'priority_minutes' => $priorityMinutes,
                'priority_timer_label' => $priorityMinutes.' min active',
                'dispatch_recommendation' => $recommendation,
                'created_at' => optional($report->created_at)->toIso8601String(),
                'updated_at' => optional($report->updated_at)->toIso8601String(),
                'detail_url' => route('reports.index', [
                    'review' => $report->id,
                ]).'#incident-report-'.$report->id,
                'destroy_url' => route('reports.destroy', $report),
                'map_role' => 'sender',
                'readable_location' => $report->location_text,
                'google_maps_url' => $this->googleMapsUrl($report),
            ],
        ];
    }

    private function messageFor(IncidentReport $report): string
    {
        if ($report->reported_fatality_flag && $this->action === 'created') {
            return 'Possible fatality reported at '.$report->location_text.'. Critical-priority responder verification required.';
        }

        if ($this->action === 'ai_updated') {
            return 'AI severity updated for '.$report->report_code.' to '.$report->severity_display.'.';
        }

        if ($this->action === 'updated') {
            return $report->report_code.' status changed to '.ucfirst($report->status).'.';
        }

        return 'New '.$report->severity_display.' incident at '.$report->location_text.'.';
    }

    private function priorityMinutesFor(IncidentReport $report): int
    {
        if ($report->created_at === null) {
            return 0;
        }

        return max(1, (int) ceil($report->created_at->diffInMinutes(now())));
    }

    private function recommendationFor(IncidentReport $report): array
    {
        if ($report->reported_fatality_flag) {
            return [
                'priority' => 'Critical priority — possible fatality reported',
                'response_type' => 'Urgent responder verification; fatality unconfirmed',
                'recommended_responder' => $report->assignedResponder?->name ?? 'Nearest available responder',
            ];
        }

        if ($report->operational_severity === null
            || $report->isOperationalSeveritySuppressed()
            || $report->ai_source === 'text_review') {
            return [
                'priority' => 'Needs responder review',
                'response_type' => 'Unassessed description; verify urgency',
                'recommended_responder' => $report->assignedResponder?->name ?? 'Nearest available responder',
            ];
        }

        $priority = match ($report->operational_severity) {
            'Critical', 'Fatal' => 'Priority 1',
            'Serious' => 'Priority 2',
            default => 'Priority 3',
        };

        $responseType = match ($report->operational_severity) {
            'Critical', 'Fatal' => 'Medical and extraction',
            'Serious' => 'Rapid field assessment',
            default => 'Verification patrol',
        };

        $recommendedResponder = $report->assignedResponder?->name ?? 'Nearest available responder';

        return [
            'priority' => $priority,
            'response_type' => $responseType,
            'recommended_responder' => $recommendedResponder,
        ];
    }

    private function googleMapsUrl(IncidentReport $report): string
    {
        if ($report->latitude !== null && $report->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode($report->latitude.','.$report->longitude);
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .rawurlencode((string) ($report->location_text ?: 'Bontoc Command Center'));
    }
}
