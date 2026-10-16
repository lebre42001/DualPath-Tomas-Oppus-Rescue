<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Support\IncidentFeedBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidentSeverityReviewController extends Controller
{
    public function __invoke(Request $request, IncidentReport $incidentReport): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $validated = $request->validate([
            'severity_review_label' => ['required', Rule::in(['minor', 'serious', 'critical', 'needs_review'])],
            'severity_review_notes' => ['required', 'string', 'max:1000'],
        ], [
            'severity_review_label.required' => 'Choose a severity or Needs review after reading the description.',
            'severity_review_notes.required' => 'Explain what in the description supports your review, or what information is missing.',
        ]);

        $report = DB::transaction(function () use ($request, $incidentReport, $validated): IncidentReport {
            $report = IncidentReport::query()->lockForUpdate()->findOrFail($incidentReport->id);
            $label = $validated['severity_review_label'];
            $needsReview = $label === 'needs_review';
            $severity = $needsReview ? null : ucfirst($label);
            $display = $needsReview ? 'Needs review' : $severity;
            $priorReview = $report->description_severity_review;
            $aiSuggestion = $priorReview['ai_suggestion'] ?? null;
            $hasSuggestion = in_array($report->ai_source, ['text_model', 'text_model_experimental', 'text_rule_advisory'], true)
                && $report->ai_status === 'complete' && in_array($report->severity, ['Minor', 'Serious', 'Critical'], true);
            $hasAbstention = $report->ai_source === 'text_review' && $report->ai_status === 'needs_review'
                && filled($report->ai_model_name);
            if (! IncidentReport::manualOnlyMode() && ($hasSuggestion || $hasAbstention)) {
                $aiSuggestion = [
                    'label' => $hasSuggestion ? strtolower($report->severity) : 'needs_review',
                    'model_name' => $report->ai_model_name,
                    'model_version' => $report->ai_model_version,
                    'model_score' => $report->ai_confidence,
                    'model_probabilities' => $report->ai_probabilities,
                    'source' => $report->ai_source,
                    'summary' => $report->ai_summary,
                    'processed_at' => $report->ai_processed_at?->toIso8601String(),
                ];
            }
            $reviewMethod = ! IncidentReport::manualOnlyMode() && $aiSuggestion && $aiSuggestion['label'] !== 'needs_review'
                ? ($label === $aiSuggestion['label'] ? 'confirmed_ai' : 'corrected_ai')
                : 'human_assessment';
            $log = $report->coordination_log ?? [];
            $log[] = [
                'label' => 'Description severity review: '.$display,
                'actor_name' => $request->user()->name,
                'actor_id' => $request->user()->id,
                'actor_role' => 'Admin',
                'status' => $report->status,
                'review_scope' => 'description_only',
                'reviewed_label' => $label,
                'reviewed_severity' => $display,
                'review_notes' => $validated['severity_review_notes'],
                'review_method' => $reviewMethod,
                'ai_suggestion' => $aiSuggestion,
                'description_sha256' => hash('sha256', (string) $report->description),
                'previous_severity' => $report->severity_display,
                'previous_stored_severity' => $report->severity,
                'previous_ai_source' => $report->ai_source,
                'previous_ai_summary' => $report->ai_summary,
                'previous_ai_confidence' => $report->ai_confidence,
                'previous_ai_probabilities' => $report->ai_probabilities,
                'previous_ai_model_name' => $report->ai_model_name,
                'previous_ai_model_version' => $report->ai_model_version,
                'historical_ai_data_label' => $report->isLegacyUnreviewedTextSuggestion()
                    ? 'Legacy AI suggestion; audit only; not used for responder severity.' : null,
                'occurred_at' => now()->toIso8601String(),
            ];

            $report->update([
                'severity' => $severity,
                'ai_source' => 'human_responder',
                'ai_status' => $needsReview ? 'needs_review' : 'complete',
                'ai_review_required' => $needsReview,
                'ai_summary' => $needsReview
                    ? 'An admin reviewed the description but could not assign a supported severity. More information is required.'
                    : 'An admin reviewed the submitted description and assigned severity: '.$severity.'.',
                'ai_confidence' => null,
                'ai_probabilities' => null,
                'ai_model_name' => null,
                'ai_model_version' => null,
                'ai_processed_at' => null,
                'ai_error_message' => null,
                'coordination_log' => $log,
            ]);

            return $report;
        });

        IncidentFeedBroadcaster::dispatch($report, 'severity_reviewed');

        return back()->with('status', 'Description severity review saved.');
    }
}
