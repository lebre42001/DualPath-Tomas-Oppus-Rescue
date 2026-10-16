@php
    $textReview = $report->description_severity_review;
    $savedAiSuggestion = $textReview['ai_suggestion'] ?? null;
    $currentAiSuggestion = ! \App\Models\IncidentReport::manualOnlyMode()
        && in_array($report->ai_source, ['text_model', 'text_model_experimental'], true)
        && $report->ai_status === 'complete'
        && in_array($report->severity, ['Minor', 'Serious', 'Critical'], true);
    $reviewAiSuggestion = \App\Models\IncidentReport::manualOnlyMode() ? null : ($currentAiSuggestion ? [
        'label' => strtolower($report->severity),
        'model_name' => $report->ai_model_name,
        'model_score' => $report->ai_confidence,
    ] : $savedAiSuggestion);
    $isSubmittedReview = (string) old('severity_review_report_id') === (string) $report->id;
    $modelAbstained = ! \App\Models\IncidentReport::manualOnlyMode()
        && $report->ai_source === 'text_review' && $report->ai_status === 'needs_review'
        && filled($report->ai_model_name);
    $selectedReview = $isSubmittedReview ? old('severity_review_label', '') : ($textReview['reviewed_label'] ?? $reviewAiSuggestion['label'] ?? '');
@endphp

<div class="stack" data-severity-review>
    <strong>Confirm or correct severity</strong>
    @if ($reviewAiSuggestion)
        <p><strong>{{ $currentAiSuggestion ? 'AI suggestion' : 'Original AI suggestion' }}:</strong> {{ ucfirst($reviewAiSuggestion['label']) }}
            @if ($reviewAiSuggestion['model_name']) — {{ $reviewAiSuggestion['model_name'] }}@endif
            @if ($reviewAiSuggestion['model_score'] !== null) · Model score {{ $reviewAiSuggestion['model_score'] }}% (uncalibrated)@endif
        </p>
    @elseif ($modelAbstained)
        <p>AI analyzed this description but could not suggest a supported severity. Review the report or request more information.</p>
    @else
        <p>AI suggestion unavailable for this report. An admin can record an assessment; reports created before activation can be analyzed with the active text model.</p>
    @endif
    @if (! \App\Models\IncidentReport::manualOnlyMode() && config('services.ai_text.enabled')
        && ! in_array($report->ai_source, ['human_responder', 'text_human_override', 'manual_override'], true))
        <form method="POST" action="{{ route('reports.ai-suggestion', $report) }}">
            @csrf
            <button type="submit" class="btn btn-secondary">{{ $currentAiSuggestion || $modelAbstained ? 'Analyze description again' : 'Analyze description with AI' }}</button>
        </form>
    @endif
    <p>Review the submitted description, then confirm or correct the severity below. Choose Needs review when the information is insufficient or unclear.</p>
    @if ($textReview)
        <p><strong>Last text review:</strong> {{ $textReview['reviewed_severity'] }} by {{ $textReview['actor_name'] }}
            on {{ \Illuminate\Support\Carbon::parse($textReview['occurred_at'])->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
        </p>
        <p><strong>Decision:</strong> {{ match ($textReview['review_method'] ?? '') {
            'confirmed_ai' => 'Admin confirmed the AI suggestion.',
            'corrected_ai' => 'Admin corrected the AI suggestion.',
            default => 'Admin assessment recorded without an AI severity suggestion.',
        } }}</p>
    @endif
    <form method="POST" action="{{ route('reports.severity-review', $report) }}" class="stack">
        @csrf
        <input type="hidden" name="severity_review_report_id" value="{{ $report->id }}">
        <div class="field">
            <label>
                Reviewed severity
                <select name="severity_review_label" required>
                    <option value="">Choose after reviewing the description</option>
                    @foreach (['minor' => 'Minor', 'serious' => 'Serious', 'critical' => 'Critical', 'needs_review' => 'Needs review — insufficient or unclear information'] as $value => $label)
                        <option value="{{ $value }}" @selected($selectedReview === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="field">
            <label>
                Reason for this text review
                <textarea name="severity_review_notes" class="textarea" required maxlength="1000" rows="3" placeholder="Describe the supporting text or the information that is missing.">{{ $isSubmittedReview ? old('severity_review_notes') : ($textReview['review_notes'] ?? '') }}</textarea>
            </label>
            <p>This internal review note is separate from the response message sent to the civilian.</p>
        </div>
        @if ($isSubmittedReview)
            @error('severity_review_label')<p role="alert">{{ $message }}</p>@enderror
            @error('severity_review_notes')<p role="alert">{{ $message }}</p>@enderror
        @endif
        <button type="submit" class="btn btn-primary">{{ $reviewAiSuggestion ? 'Confirm severity' : 'Save admin assessment' }}</button>
    </form>
</div>
<div class="divider"></div>
