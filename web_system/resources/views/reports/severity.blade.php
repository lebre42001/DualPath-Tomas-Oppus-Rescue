@extends('layouts.app')

@php
    $isCivilian = auth()->user()?->isCivilian() ?? false;
    $isHistoricalPhotoAssessment = in_array($report->ai_source, ['python_model', 'image_model'], true);
    $isSuppressedLegacySuggestion = $report->isLegacyUnreviewedTextSuggestion();
    $isPendingAssessment = $report->severity === null
        || in_array($report->ai_source, ['text_review', 'manual_only'], true)
        || $isSuppressedLegacySuggestion;
    $assessmentLabel = $isSuppressedLegacySuggestion
        ? 'Historical AI suggestion (not operational)'
        : ($isHistoricalPhotoAssessment
        ? 'Historical assessment'
        : (in_array($report->ai_source, ['text_human_override', 'manual_override'], true)
            ? 'Responder assessment'
            : ($isPendingAssessment ? 'Assessment status' : 'Proposed severity')));
    $tone = static fn (string $severity): string => match ($severity) {
        'Fatal', 'Critical' => 'red',
        'Serious', 'Needs review', 'Awaiting responder' => 'amber',
        default => 'green',
    };
    $sourceLabel = static fn (?string $source): string => match ($source) {
        'text_model' => 'Description AI model',
        'text_model_experimental' => 'Experimental description AI',
        'text_review' => 'Description awaiting review',
        'text_human_override' => 'Responder severity; text AI advisory',
        'manual_only' => 'Manual responder review required',
        'human_responder' => 'Responder-assigned severity',
        'legacy_ai_audit' => 'Legacy AI suggestion (audit only)',
        'python_model', 'image_model' => 'Historical photo assessment',
        'description_fallback' => 'Historical description fallback',
        'manual_override' => 'Manual override',
        null, '' => 'Source unavailable',
        default => ucwords(str_replace('_', ' ', $source)),
    };
    $statusLabel = static function (array $analysis): string {
        if (($analysis['source'] ?? null) === 'manual_only') {
            return 'Awaiting responder severity';
        }

        if (($analysis['source'] ?? null) === 'legacy_ai_audit') {
            return 'Suppressed in MANUAL_ONLY; audit only';
        }

        if (($analysis['status'] ?? null) === 'fallback') {
            return 'Fallback mode';
        }

        if (($analysis['status'] ?? null) === 'failed') {
            return 'AI unavailable';
        }

        if ($analysis['review_required']) {
            return 'Admin review needed';
        }

        if (($analysis['status'] ?? null) === 'complete') {
            return 'Ready for triage';
        }

        return 'Pending AI review';
    };
    $statusTone = static function (array $analysis): string {
        if (($analysis['status'] ?? null) === 'failed') {
            return 'red';
        }

        if ($analysis['review_required']) {
            return 'red';
        }

        if (($analysis['status'] ?? null) === 'fallback') {
            return 'amber';
        }

        if (($analysis['status'] ?? null) === 'complete') {
            return 'green';
        }

        return 'neutral';
    };
    $confidenceTone = static function (?string $confidence): string {
        if ($confidence === null || $confidence === '') {
            return 'neutral';
        }

        $value = (float) rtrim($confidence, '%');

        if ($value >= 85) {
            return 'green';
        }

        if ($value >= 60) {
            return 'amber';
        }

        return 'red';
    };
@endphp

@section('title', $isSuppressedLegacySuggestion ? 'Restricted AI Audit History' : ($isPendingAssessment ? 'Responder Severity Review' : 'AI Severity Analysis'))
@section('page_label', $isSuppressedLegacySuggestion ? 'Restricted Audit History' : ($isPendingAssessment ? 'Responder Review' : ($isCivilian ? 'AI Review' : 'AI Severity Analysis')))
@section('page_heading', $isSuppressedLegacySuggestion ? 'Historical AI suggestion — not operational' : ($isPendingAssessment ? 'Severity is awaiting responder assessment' : ($isCivilian ? 'Your AI Severity Result' : 'AI Severity Analysis')))
@section('page_subheading', $isSuppressedLegacySuggestion ? 'This saved prediction is excluded from current report severity, queues, alerts, API projections, and dispatch. It is shown only for responder audit history.' : ($isPendingAssessment ? 'No automatic severity was assigned. A responder must assess this report.' : ($isHistoricalPhotoAssessment ? 'Review the saved assessment from the earlier photo-based workflow.' : 'Review the description assessment and responder review status. Photos remain supporting evidence.')))

@section('hero')
    <section class="hero-card" style="{{ $isCivilian ? 'background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(232,245,255,.95));border-color:rgba(32,104,174,.16);' : '' }}">
        <div class="hero-grid" style="grid-template-columns:minmax(0,1.08fr) minmax(280px,.92fr);">
            <div class="hero-copy">
                    <p class="eyebrow">{{ $isSuppressedLegacySuggestion ? 'Restricted audit history' : ($isCivilian ? 'AI Result' : 'AI Severity Analysis') }}</p>
                    <h2>{{ $isSuppressedLegacySuggestion ? 'Saved legacy value: '.$report->severity.' (non-operational)' : ($isPendingAssessment ? 'Severity needs responder review.' : $analysis['predicted'].' severity assessment.') }}</h2>
                <p>
                    @if ($isHistoricalPhotoAssessment)
                        This report retains a historical photo assessment. New reports use the description to propose severity, with photos kept as evidence for responders.
                    @elseif ($isSuppressedLegacySuggestion)
                        The stored automated suggestion is preserved for audit only. Current response decisions must use a responder-assigned severity.
                    @elseif ($isPendingAssessment)
                        {{ $report->ai_summary }}
                    @elseif ($analysis['is_text'])
                        {{ $report->ai_summary }}
                    @else
                        This is the saved severity assessment for report <strong>{{ $report->report_code }}</strong>. Check the assessment source and responder review status below.
                    @endif
                </p>
                <div class="hero-actions">
                    <a href="{{ route('reports.transmissions', $report) }}" class="btn btn-primary">Continue To Transmission</a>
                    <a href="{{ route('reports.show', $report) }}" class="btn btn-secondary">{{ $isCivilian ? 'Back To My Report' : 'Back To Incident Details' }}</a>
                </div>
            </div>
            <div class="hero-metrics">
                <article class="metric-card"><span>{{ $assessmentLabel }}</span><strong>{{ $analysis['predicted'] }}</strong><p>{{ $isSuppressedLegacySuggestion ? 'This saved AI suggestion is excluded from current operations; responder assessment is required.' : ($isPendingAssessment ? 'No severity has been assigned; responder assessment is required.' : 'Review this assessment together with the submitted evidence.') }}</p></article>
                <article class="metric-card"><span>{{ $analysis['is_text'] ? 'Model score' : 'Saved confidence' }}</span><strong>{{ $report->ai_confidence !== null ? $report->ai_confidence.'%' : 'Unavailable' }}</strong><p>{{ $analysis['is_text'] ? 'Uncalibrated model score; not a probability that the scene is safe.' : 'Score stored with the historical assessment.' }}</p></article>
                @if ($analysis['photo_relevance_label'] !== null || $analysis['photo_relevance_confidence'] !== null)
                    <article class="metric-card"><span>Historical photo relevance</span><strong>{{ $analysis['photo_relevance_label'] ? ucwords(str_replace('_', ' ', $analysis['photo_relevance_label'])) : 'Not checked' }}</strong><p>{{ $analysis['photo_relevance_confidence'] !== null ? 'Saved relevance confidence '.$analysis['photo_relevance_confidence'].'%.' : 'No relevance confidence was stored.' }}</p></article>
                @endif
                <article class="metric-card"><span>{{ $analysis['source'] === 'human_responder' ? 'Severity source' : 'AI source' }}</span><strong>{{ $sourceLabel($analysis['source']) }}</strong><p>{{ $analysis['model_name'] }}@if ($analysis['model_version']) v{{ $analysis['model_version'] }}@endif</p></article>
                <article class="metric-card"><span>Review state</span><strong>{{ $statusLabel($analysis) }}</strong><p>{{ $analysis['review_required'] ? 'Responder review is required before confirming severity.' : 'Review the saved assessment alongside the report evidence.' }}</p></article>
                <article class="metric-card"><span>Transport</span><strong>{{ strtoupper($report->transmission_type ?: 'online') }}</strong><p>{{ $report->channel ?: 'Internet' }}</p></article>
                <article class="metric-card"><span>Location</span><strong>{{ $report->location_text }}</strong><p>Source location used for the report context.</p></article>
            </div>
        </div>
    </section>
@endsection

@section('content')
    <section class="dual-grid">
        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Assessment</p>
                    <h2 class="panel-title">Severity output</h2>
                    <p class="section-copy">{{ $isPendingAssessment ? 'The system has no usable severity result. A responder must assess this report.' : 'Use this assessment to support responder review and coordination.' }}</p>
                </div>
            </div>
            <div class="stack">
                <div class="detail-card"><strong>{{ $assessmentLabel }}</strong><span class="tag {{ $tone($analysis['predicted']) }}">{{ $analysis['predicted'] }}</span></div>
                <div class="detail-card">
                    <strong>{{ $isPendingAssessment || $analysis['source'] === 'human_responder' ? 'Severity assessment' : 'AI quick read' }}</strong>
                    <div class="tag-row">
                        @if ($isSuppressedLegacySuggestion)
                            <span class="badge {{ $confidenceTone($report->ai_confidence !== null ? (string) $report->ai_confidence : null) }}">Historical AI score: {{ $report->ai_confidence !== null ? $report->ai_confidence.'%' : 'unavailable' }} (audit only)</span>
                        @else
                            <span class="badge {{ $confidenceTone($report->ai_confidence !== null ? (string) $report->ai_confidence : null) }}">{{ $report->ai_score_display }}</span>
                        @endif
                        <span class="badge blue">{{ $sourceLabel($analysis['source']) }}</span>
                        <span class="badge {{ $statusTone($analysis) }}">{{ $statusLabel($analysis) }}</span>
                        @include('reports.partials.photo-relevance-badges', [
                            'label' => $analysis['photo_relevance_label'],
                            'confidence' => $analysis['photo_relevance_confidence'],
                            'overridden' => $analysis['photo_relevance_overridden'],
                        ])
                    </div>
                    <p>{{ $analysis['advice'] }}</p>
                    <div class="meta-list">
                        <div class="meta-row">
                            <span>Model</span>
                            <strong>{{ $analysis['model_name'] }}@if ($analysis['model_version']) v{{ $analysis['model_version'] }}@endif</strong>
                        </div>
                        <div class="meta-row">
                            <span>AI status</span>
                            <strong>{{ $statusLabel($analysis) }}</strong>
                        </div>
                        @if ($analysis['processed_at'])
                            <div class="meta-row">
                                <span>Processed at</span>
                                <strong>{{ $analysis['processed_at'] }}</strong>
                            </div>
                        @endif
                        @if ($analysis['photo_relevance_label'] !== null || $analysis['photo_relevance_confidence'] !== null)
                            <div class="meta-row">
                                <span>Historical photo relevance</span>
                                <strong>{{ $analysis['photo_relevance_label'] ? ucwords(str_replace('_', ' ', $analysis['photo_relevance_label'])) : 'Not checked' }}{{ $analysis['photo_relevance_confidence'] !== null ? ' - '.$analysis['photo_relevance_confidence'].'%' : '' }}</strong>
                            </div>
                        @endif
                    </div>
                </div>
                @if ($analysis['processed_at'])
                    <div class="detail-card"><strong>Prediction timing</strong><p>The AI result was processed at {{ $analysis['processed_at'] }}.</p></div>
                @endif
                @if ($analysis['error_message'])
                    <div class="detail-card"><strong>Fallback note</strong><p>{{ $analysis['error_message'] }}</p></div>
                @endif
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Source</p>
                    <h2 class="panel-title">Submitted context</h2>
                    <p class="section-copy">{{ $isHistoricalPhotoAssessment ? 'Submitted details retained alongside the historical assessment.' : 'AI severity uses the submitted description. Location and photos help responders review the incident.' }}</p>
                </div>
            </div>
            <div class="stack">
                <div class="detail-card"><strong>Incident type</strong><p>{{ $report->incident_type }}</p></div>
                <div class="detail-card"><strong>Location</strong><p>{{ $report->location_text }}</p></div>
                <div class="detail-card"><strong>Description</strong><p>{{ $report->description }}</p></div>
            </div>
        </article>
    </section>
    @if (is_array($manualAiAudit))
        <section class="panel" aria-labelledby="historical-ai-audit-title">
            <div class="panel-head">
                <div class="panel-heading">
                    <p class="panel-kicker">Restricted responder audit</p>
                    <h2 id="historical-ai-audit-title" class="panel-title">Historical AI suggestion — non-operational</h2>
                    <p class="section-copy">This record is retained for audit only. The responder-assigned severity above controls current operations; the saved AI suggestion must not guide queue priority, alerts, or dispatch.</p>
                </div>
            </div>
            <div class="stack">
                <div class="detail-card"><strong>Previously stored severity</strong><p>{{ $manualAiAudit['previous_stored_severity'] ?? 'Unavailable' }}</p></div>
                <div class="detail-card"><strong>Historical AI summary</strong><p>{{ $manualAiAudit['previous_ai_summary'] ?? 'No summary was stored.' }}</p></div>
                <div class="detail-card"><strong>Historical confidence</strong><p>{{ isset($manualAiAudit['previous_ai_confidence']) ? $manualAiAudit['previous_ai_confidence'].'%' : 'Unavailable' }}</p></div>
                <div class="detail-card"><strong>Historical model</strong><p>{{ $manualAiAudit['previous_ai_model_name'] ?? 'Unavailable' }}{{ ! empty($manualAiAudit['previous_ai_model_version']) ? ' v'.$manualAiAudit['previous_ai_model_version'] : '' }}</p></div>
                <div class="detail-card"><strong>Audit note</strong><p>{{ $manualAiAudit['historical_ai_data_label'] }}</p></div>
            </div>
        </section>
    @endif
@endsection
