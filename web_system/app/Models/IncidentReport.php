<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_code',
        'ingest_key',
        'reported_by',
        'assigned_responder_id',
        'reporter_name',
        'reporter_contact',
        'incident_type',
        'severity',
        'status',
        'channel',
        'transmission_type',
        'location_text',
        'latitude',
        'longitude',
        'description',
        'reported_fatality_flag',
        'ai_summary',
        'ai_confidence',
        'ai_source',
        'ai_status',
        'ai_model_name',
        'ai_model_version',
        'ai_review_required',
        'ai_probabilities',
        'ai_processed_at',
        'ai_error_message',
        'photo_relevance_label',
        'photo_relevance_confidence',
        'photo_relevance_probabilities',
        'photo_relevance_overridden',
        'evidence_type',
        'evidence_path',
        'evidence_original_name',
        'reporter_selfie_path',
        'reporter_selfie_original_name',
        'reporter_selfie_captured_at',
        'coordination_log',
        'response_notes',
        'transmitted_at',
        'resolved_at',
        'status_updated_at',
        'hardware_source',
        'lora_sender_id',
        'lora_sequence',
        'lora_gateway_id',
        'lora_gateway_rssi',
        'lora_gateway_snr',
        'lora_receiver_rssi',
        'lora_receiver_snr',
        'lora_satellites',
        'lora_ack_queued_at',
        'lora_ack_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'reported_fatality_flag' => 'boolean',
            'longitude' => 'decimal:6',
            'ai_confidence' => 'integer',
            'ai_review_required' => 'boolean',
            'ai_probabilities' => 'array',
            'ai_processed_at' => 'datetime',
            'photo_relevance_confidence' => 'integer',
            'photo_relevance_probabilities' => 'array',
            'photo_relevance_overridden' => 'boolean',
            'transmitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'reporter_selfie_captured_at' => 'datetime',
            'coordination_log' => 'array',
            'status_updated_at' => 'datetime',
            'lora_gateway_rssi' => 'integer',
            'lora_gateway_snr' => 'decimal:2',
            'lora_receiver_rssi' => 'integer',
            'lora_receiver_snr' => 'decimal:2',
            'lora_satellites' => 'integer',
            'lora_ack_queued_at' => 'datetime',
            'lora_ack_delivered_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function getSeverityDisplayAttribute(): string
    {
        if ($this->severity === null && $this->ai_source === 'human_responder') {
            return 'Needs review';
        }

        if ($this->isOperationalSeveritySuppressed() || $this->isAwaitingTextSeverityConfirmation()) {
            return 'Awaiting responder';
        }

        if ($this->severity === null && in_array($this->ai_source, ['manual_only', 'text_review'], true)) {
            return 'Awaiting responder';
        }

        if ($this->ai_source === 'text_review') {
            return 'Needs review';
        }

        if (in_array($this->ai_source, ['text_model', 'text_model_experimental', 'text_rule_advisory'], true) && $this->severity === 'Fatal') {
            return 'Critical';
        }

        return (string) $this->severity;
    }

    public function getAiScoreDisplayAttribute(): string
    {
        if ($this->isOperationalSeveritySuppressed()) {
            if ($this->ai_source === 'text_review') {
                return 'No usable AI suggestion; responder review required';
            }

            if ($this->ai_source === 'manual_only') {
                return 'AI disabled; responder review required';
            }

            return 'Historical AI suggestion hidden in MANUAL_ONLY mode';
        }

        if ($this->ai_source === 'manual_only') {
            return 'AI disabled; responder review required';
        }

        if ($this->ai_source === 'human_responder') {
            if ($this->severity === null) {
                return 'Human review: more information needed';
            }

            return 'No AI score; responder-assigned severity';
        }

        if ($this->ai_source === 'text_rule_advisory') {
            return 'Word/phrase suggestion; no rule confidence score';
        }

        if ($this->ai_source === 'text_review' && $this->ai_status === 'needs_review' && filled($this->ai_model_name)) {
            return 'AI analyzed text; no severity suggested';
        }

        if (str_starts_with((string) $this->ai_source, 'text_')) {
            return $this->ai_confidence === null
                ? 'Model score unavailable'
                : 'Model score '.$this->ai_confidence.'% (uncalibrated)';
        }

        return 'Confidence '.($this->ai_confidence !== null ? $this->ai_confidence.'%' : 'Pending');
    }

    public function assignedResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_responder_id');
    }

    public function getDescriptionSeverityReviewAttribute(): ?array
    {
        foreach (array_reverse($this->coordination_log ?? []) as $entry) {
            if (! array_key_exists('reviewed_severity', $entry)) {
                continue;
            }

            // A later operational review or edited description invalidates the old text label.
            if (($entry['review_scope'] ?? null) !== 'description_only'
                || ($entry['description_sha256'] ?? null) !== hash('sha256', (string) $this->description)) {
                return null;
            }

            $currentLabel = $this->severity === null ? 'needs_review' : strtolower($this->severity);
            if (($entry['reviewed_label'] ?? null) !== $currentLabel
                || ! in_array($this->ai_source, ['human_responder', 'text_human_override', 'manual_override'], true)) {
                return null;
            }

            return $entry;
        }

        return null;
    }

    public static function manualOnlyMode(): bool
    {
        return strtoupper((string) config('services.ai_text.mode', 'MANUAL_ONLY')) === 'MANUAL_ONLY';
    }

    public function isLegacyUnreviewedTextSuggestion(): bool
    {
        return self::manualOnlyMode()
            && in_array($this->ai_source, ['text_model', 'text_model_experimental', 'text_rule_advisory'], true)
            && (bool) $this->ai_review_required;
    }

    public function isOperationalSeveritySuppressed(): bool
    {
        return $this->isLegacyUnreviewedTextSuggestion()
            || ($this->ai_source === 'manual_only' && $this->severity === null)
            || ($this->ai_source === 'text_review' && self::manualOnlyMode());
    }

    public function isAwaitingTextSeverityConfirmation(): bool
    {
        return in_array($this->ai_source, ['text_model', 'text_model_experimental', 'text_rule_advisory'], true);
    }

    public function getAiSuggestedSeverityAttribute(): ?string
    {
        return ! self::manualOnlyMode() && $this->isAwaitingTextSeverityConfirmation()
            && in_array($this->severity, ['Minor', 'Serious', 'Critical'], true)
                ? $this->severity : null;
    }

    public function getOperationalSeverityAttribute(): ?string
    {
        return $this->isOperationalSeveritySuppressed() || $this->isAwaitingTextSeverityConfirmation() ? null : $this->severity;
    }

    public function getOperationalAiConfidenceAttribute(): ?int
    {
        return $this->isOperationalSeveritySuppressed() ? null : $this->ai_confidence;
    }

    public function getOperationalAiSummaryAttribute(): ?string
    {
        if ($this->isOperationalSeveritySuppressed() && $this->ai_source === 'text_review') {
            return 'No usable automated severity was available. A responder must assess the report.';
        }

        return $this->isOperationalSeveritySuppressed()
            ? ($this->ai_source === 'manual_only'
                ? 'Automated severity is disabled in MANUAL_ONLY mode. A responder must assign severity.'
                : 'This saved automated suggestion is non-operational in MANUAL_ONLY mode. A responder must assign severity.')
            : $this->ai_summary;
    }

    public function getOperationalAiSourceAttribute(): ?string
    {
        return $this->isOperationalSeveritySuppressed() ? 'manual_only' : $this->ai_source;
    }

    public function getOperationalAiStatusAttribute(): ?string
    {
        return $this->isOperationalSeveritySuppressed() ? 'needs_review' : $this->ai_status;
    }

    public function getOperationalAiModelNameAttribute(): ?string
    {
        return $this->isOperationalSeveritySuppressed() ? null : $this->ai_model_name;
    }

    public function getOperationalAiModelVersionAttribute(): ?string
    {
        return $this->isOperationalSeveritySuppressed() ? null : $this->ai_model_version;
    }

    public function getOperationalAiProbabilitiesAttribute(): ?array
    {
        return $this->isOperationalSeveritySuppressed() ? null : $this->ai_probabilities;
    }

    public function getOperationalAiReviewRequiredAttribute(): bool
    {
        return $this->isOperationalSeveritySuppressed() || $this->isAwaitingTextSeverityConfirmation() || (bool) $this->ai_review_required;
    }

    public function scopeOperationallyAssessed(Builder $query): Builder
    {
        $query->whereNotNull('severity')
            ->where(fn (Builder $sourceQuery) => $sourceQuery
                ->whereNull('ai_source')
                ->orWhereNotIn('ai_source', ['manual_only', 'text_review', 'text_model', 'text_model_experimental', 'text_rule_advisory']));

        return $query;
    }

    public function scopeNeedsResponderReview(Builder $query): Builder
    {
        return $query->where(function (Builder $reviewQuery): void {
            $reviewQuery->whereIn('ai_source', ['manual_only', 'text_review', 'text_model', 'text_model_experimental', 'text_rule_advisory'])
                ->orWhereNull('severity');
        });
    }
}
