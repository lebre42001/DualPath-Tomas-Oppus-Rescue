@php
    $topCategory = $report->ai_top_category;
    $reviewedText = $report->description_severity_review;
    $compact = $compact ?? false;
    $currentCategory = $report->current_severity_category;
    $categoryTone = match ($currentCategory) { 'Critical' => 'red', 'Serious' => 'amber', 'Minor' => 'green', default => 'blue' };
    $finalCategory = $currentCategory !== 'Unassessed' && ! in_array($report->ai_source, ['manual_only', 'text_review', 'text_model', 'text_model_experimental', 'text_rule_advisory'], true);
    $compactNote = in_array($report->ai_source, ['human_responder', 'manual_override', 'text_human_override'], true)
        ? 'Admin reviewed.'
        : ($report->transmission_type === 'lora'
            ? 'LoRa intake · admin review required.'
            : ($topCategory ? 'AI score · admin review required.' : 'Admin review required.'));
@endphp
<div class="stack" data-ai-analysis-for="{{ $report->id }}" data-ai-analysis-layout="{{ $compact ? 'compact' : 'full' }}" tabindex="-1">
    @if ($currentCategory !== 'Unassessed')
        <div class="tag-row">
            <strong>Current severity category:</strong>
            <span class="tag {{ $categoryTone }}">{{ $currentCategory }}@if (! $finalCategory && $topCategory) — {{ number_format($topCategory['score'], 1) }}%@endif</span>
        </div>
        <p>{{ $compact ? $compactNote : ((($topCategory['tied'] ?? false) && ! $finalCategory ? 'Highest scores are tied. ' : '').($finalCategory ? 'Admin assessment is the current category.' : 'AI top category; provisional model score; admin review required.')) }}</p>
    @elseif ($compact)
        <span class="tag blue">Unassessed</span>
        <p>{{ $compactNote }}</p>
    @endif
    @unless ($compact)
        @if ($finalCategory && $topCategory)
            <p><strong>Original AI top category:</strong> {{ $topCategory['label'] }} — {{ number_format($topCategory['score'], 1) }}% (review history).</p>
        @endif
        @if (in_array($report->operational_ai_source, ['python_model', 'image_model'], true))
            <div class="tag-row">
                <span class="tag blue">{{ $report->severity_display }} output</span>
                <span class="badge neutral">{{ $report->ai_score_display }}</span>
                <span class="badge blue">Historical photo assessment</span>
                <span class="badge neutral">{{ $report->operational_ai_status === 'complete' ? 'Ready for triage' : 'Awaiting review' }}</span>
            </div>
        @endif
        @if ($report->ai_suggested_severity)
            <p><strong>AI suggestion:</strong> {{ $report->ai_suggested_severity }}. Admin confirmation required.</p>
        @endif
        @if ($reviewedText)
            <p><strong>Reviewed by admin:</strong> {{ $reviewedText['reviewed_severity'] }}.</p>
        @endif
        <p>{{ \App\Support\ReportAssessmentSummary::forReport($report) }}</p>
        @if ($topCategory)<p>Model scores are uncalibrated, not accuracy or certainty. Word/phrase suggestions can differ from the top model category.</p>@endif
    @endunless
    <p role="status" data-ai-analysis-feedback hidden></p>
</div>
