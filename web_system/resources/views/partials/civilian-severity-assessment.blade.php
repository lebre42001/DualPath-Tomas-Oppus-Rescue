@php
    $assessment = $assessment ?? \App\Support\CivilianSeverityAssessment::forReport($report);
    $assessmentReviewed = $assessment['state'] === 'reviewed';
    $assessmentTone = $assessmentReviewed ? match ($assessment['severity']) {
        'Critical' => 'critical',
        'Serious' => 'serious',
        default => 'minor',
    } : 'pending';
@endphp

<section class="civilian-severity-assessment" data-civilian-assessment data-assessment-state="{{ $assessment['state'] }}" aria-label="Severity assessment">
    <div class="civilian-assessment-heading">
        <h3>{{ $assessment['heading'] }}</h3>
        @if ($assessmentReviewed)
            <strong class="civilian-assessment-severity civilian-assessment-severity--{{ $assessmentTone }}">{{ $assessment['severity'] }}</strong>
        @endif
    </div>
    <p class="civilian-assessment-message">{{ $assessment['message'] }}</p>

    @if ($assessmentReviewed)
        <details class="civilian-assessment-details">
            <summary>Assessment details</summary>
            <dl class="civilian-assessment-facts">
                <div>
                    <dt>Original AI suggestion</dt>
                    <dd>{{ $assessment['originalAi'] ?? 'AI result unavailable' }}</dd>
                </div>
                <div>
                    <dt>Review outcome</dt>
                    <dd>{{ $assessment['outcome'] }}</dd>
                </div>
                @if ($assessment['reviewedAtLabel'])
                    <div>
                        <dt>Reviewed (Philippine time)</dt>
                        <dd><time datetime="{{ $assessment['reviewedAt'] }}">{{ $assessment['reviewedAtLabel'] }}</time></dd>
                    </div>
                @endif
                @if ($assessment['reviewer'])
                    <div>
                        <dt>Reviewed by</dt>
                        <dd>{{ $assessment['reviewer'] }}</dd>
                    </div>
                @endif
            </dl>
            <p class="civilian-assessment-advisory">{{ $assessment['aiMessage'] }}</p>
            @if ($assessment['explanation'])
                <div class="civilian-assessment-explanation">
                    <strong>Review explanation</strong>
                    <p>{{ $assessment['explanation'] }}</p>
                </div>
            @endif
        </details>
    @endif
</section>
