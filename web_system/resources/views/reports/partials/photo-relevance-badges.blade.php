@php
    $label = $label ?? ($report->photo_relevance_label ?? null);
    $confidence = $confidence ?? ($report->photo_relevance_confidence ?? null);
    $overridden = (bool) ($overridden ?? ($report->photo_relevance_overridden ?? false));
    $hasPhotoRelevance = $label !== null || $confidence !== null || $overridden;
    $labelText = $label ? ucwords(str_replace('_', ' ', $label)) : 'Not checked';
    $labelTone = match (\Illuminate\Support\Str::lower((string) $label)) {
        'related' => 'green',
        'unrelated' => 'red',
        default => 'neutral',
    };
    $confidenceTone = static function ($value): string {
        if ($value === null) {
            return 'neutral';
        }

        if ($value >= 85) {
            return 'green';
        }

        if ($value >= 60) {
            return 'amber';
        }

        return 'red';
    };
@endphp

@if ($hasPhotoRelevance)
    <span class="badge {{ $labelTone }}">Historical photo relevance: {{ $labelText }}</span>
    <span class="badge {{ $confidenceTone($confidence) }}">Saved relevance {{ $confidence !== null ? $confidence.'%' : 'unavailable' }}</span>
    @if ($overridden)
        <span class="badge amber">Historical gate override</span>
    @endif
@endif
