@extends('layouts.app')

@section('title', 'Text AI Lab')
@section('page_label', 'Experimental Evaluation')
@section('page_heading', 'Text AI Lab')
@section('page_subheading', 'Test report descriptions against the connected text-analysis service.')

@section('content')
    <main class="ai-text-lab" aria-labelledby="ai-text-lab-title">
        <section class="ai-lab-notice" role="note">
            <strong>Experimental model — evaluation only</strong>
            <p>This page does not create or change reports, dispatch decisions, training data, or the active model. Model scores are not calibrated probabilities. A responder must independently assess every real emergency.</p>
        </section>

        <section class="ai-lab-card">
            <h2 id="ai-text-lab-title">Try an emergency description</h2>
            <p>Use fictional or anonymized incident text. A model may require review for categories outside its trained scope. Do not enter names, phone numbers, exact addresses, or other personal information.</p>
            <form method="POST" action="{{ route('admin.text-ai-test.predict') }}" class="ai-lab-form">
                @csrf
                <label for="incident_type">Incident category</label>
                <select id="incident_type" name="incident_type">
                    @foreach (\App\Support\IncidentCategory::LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(old('incident_type', $incidentType ?? 'road_accident') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('incident_type')<p class="ai-lab-error">{{ $message }}</p>@enderror
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="5" maxlength="2000" required placeholder="Example: Nagbangga ang duha ka motorsiklo; usa ka tawo walay panimuot ug kusog ang pagdugo sa ulo.">{{ old('description', $description ?? '') }}</textarea>
                @error('description')<p class="ai-lab-error">{{ $message }}</p>@enderror
                <button class="btn btn-primary" type="submit">Analyze for testing</button>
            </form>
            @if (! empty($reportedFatalityKeyword))
                <div class="ai-lab-fatality-flag" role="alert"><strong>Possible fatality keyword detected — Critical-priority responder verification required.</strong><p>This is a keyword flag only. It does not confirm a death and does not change or override the model prediction.</p></div>
            @endif
        </section>

        <section class="ai-lab-card" aria-labelledby="ai-learning-status-title" data-ai-learning-status>
            <h2 id="ai-learning-status-title">Automatic learning status</h2>
            <p><strong>{{ $learningStatus['heading'] }}</strong></p>
            <p>{{ $learningStatus['message'] }}</p>
            <dl class="ai-lab-scores">
                <div><dt>Candidate training</dt><dd>{{ $learningStatus['training_enabled'] ? 'Enabled' : 'Disabled in this web runtime' }}</dd></div>
                <div><dt>Reviewed snapshots</dt><dd>{{ $learningStatus['captured'] ?? 'Unavailable' }}</dd></div>
                <div><dt>Current admin-approved snapshots</dt><dd>{{ $learningStatus['approved'] ?? 'Unavailable' }}</dd></div>
                <div><dt>Eligible examples at last run</dt><dd>{{ $learningStatus['eligible'] ?? 'Not yet checked' }}</dd></div>
                <div><dt>New examples at last run</dt><dd>{{ $learningStatus['new_examples'] ?? 'Not yet checked' }}</dd></div>
                <div><dt>Minimum new examples for training</dt><dd>{{ $learningStatus['minimum_new'] }}</dd></div>
                <div><dt>Independent evaluation target</dt><dd>{{ $learningStatus['target_percent'] }}% (target, not measured accuracy)</dd></div>
            </dl>
            <p>Admin approval prepares learning data; privacy, duplicate, and label checks may exclude examples. Export and candidate training run on the configured schedule or separate learning worker.</p>
            <p><strong>Activation is manual.</strong> A candidate never automatically replaces the active model. No accuracy or support for every incident category is established by these counts.</p>
        </section>

        @if (! empty($error))
            <section class="ai-lab-card ai-lab-error-panel" role="alert"><strong>Test unavailable</strong><p>{{ $error }}</p></section>
        @endif

        @if (! empty($result))
            <section class="ai-lab-card ai-lab-result" aria-live="polite">
                <div class="ai-lab-result-head">
                    <div>
                        <span class="ai-lab-kicker">{{ ($result['assessment_method'] ?? 'trained_model') === 'text_rules' ? 'Word/phrase suggestion' : ($result['status'] === 'needs_review' ? 'Provisional top-class suggestion' : 'Model suggestion') }}</span>
                        <h2>{{ $result['severity'] ? ucwords(str_replace('_', ' ', $result['severity'])) : ($result['predicted_label'] !== 'needs_review' ? ucwords(str_replace('_', ' ', $result['predicted_label'])) : 'Needs responder review') }}</h2>
                    </div>
                    <span class="ai-lab-badge">{{ strtoupper($result['deployment_status']) }}</span>
                </div>
                <p>{{ $result['summary'] }}</p>
                @if (($result['assessment_method'] ?? 'trained_model') === 'text_rules')
                    <p>Method: multilingual word/phrase rules. No model confidence score is assigned.</p>
                @endif
                @if ($result['status'] === 'needs_review')
                    <p><strong>Decision status:</strong> Needs responder review. This suggested class is not an assigned severity.</p>
                @endif
                <p><strong>Review:</strong> {{ $result['responder_review_required'] ? 'Human responder review required' : 'Review flag not set' }}</p>
                @php($scoreProbabilities = $result['model_assessment']['probabilities'] ?? $result['probabilities'])
                @if (is_array($scoreProbabilities) && count($scoreProbabilities) === 4)
                    <h3>Model scores (uncalibrated)</h3>
                    <dl class="ai-lab-scores">
                        @foreach (['minor' => 'Minor', 'serious' => 'Serious', 'critical' => 'Critical', 'needs_review' => 'Needs review'] as $key => $label)
                            <div><dt>{{ $label }}</dt><dd>{{ number_format((float) $scoreProbabilities[$key] * 100, 1) }}%</dd></div>
                        @endforeach
                    </dl>
                @endif
                <p class="ai-lab-model">Model: {{ $result['model_name'] }} · {{ $result['model_version'] }} · {{ $result['status'] }}</p>
            </section>
        @endif

        <section class="ai-lab-card ai-lab-howto">
            <h2>How to judge whether it is useful</h2>
            <ol>
                <li>Test a diverse set of cases in English, Tagalog, Bisaya, and mixed language.</li>
                <li>Have a qualified responder assign the expected label independently before viewing the AI output.</li>
                <li>Record every mismatch, especially Critical predicted as Minor or Serious, then review by language and scenario.</li>
                <li>Do not call the model accurate from a few examples or its displayed score. This is not an operational release or safety approval.</li>
            </ol>
        </section>
    </main>
@endsection
