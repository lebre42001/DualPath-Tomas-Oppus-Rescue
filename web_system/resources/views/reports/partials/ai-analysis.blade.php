@php
    $topCategory = $report->ai_top_category;
    $reviewedText = $report->description_severity_review;
    $compact = $compact ?? false;
    $categoryTone = match ($topCategory['key'] ?? '') { 'critical' => 'red', 'serious' => 'amber', 'minor' => 'green', default => 'blue' };
@endphp
<div class="stack" data-ai-analysis-for="{{ $report->id }}" data-ai-analysis-layout="{{ $compact ? 'compact' : 'full' }}" tabindex="-1">
    @if ($topCategory)
        <div class="tag-row">
            <strong>{{ $topCategory['original'] ? 'Original AI top category' : 'AI top category' }}:</strong>
            <span class="tag {{ $categoryTone }}">{{ $topCategory['label'] }} — {{ number_format($topCategory['score'], 1) }}%</span>
        </div>
        <p>{{ $topCategory['tied'] ? 'Highest scores are tied. ' : '' }}{{ $reviewedText ? 'Original model score; admin assessment is recorded separately.' : 'Provisional model score; admin review required.' }}</p>
    @elseif ($compact)
        <p>{{ $report->ai_score_display }}</p>
    @endif
    @unless ($compact)
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
        <p>{{ $topCategory && ! $reviewedText && $report->ai_source === 'text_review'
            ? 'Text analyzed. Confirm or correct the category after reviewing the description.'
            : ($report->operational_ai_summary ?: 'No text analysis available yet.') }}</p>
        @if ($report->operational_ai_model_name)
            <p><strong>Model:</strong> {{ $report->operational_ai_model_name }} v{{ $report->operational_ai_model_version }}</p>
        @endif
        @if ($topCategory)<p>Model scores are uncalibrated, not accuracy or certainty. Word/phrase suggestions can differ from the top model category.</p>@endif
    @endunless
    <p role="status" data-ai-analysis-feedback hidden></p>
</div>
