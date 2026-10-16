<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\AiTextSeverityClient;
use App\Support\IncidentFeedBroadcaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncidentTextSuggestionController extends Controller
{
    public function __invoke(Request $request, IncidentReport $incidentReport, AiTextSeverityClient $client): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_if(IncidentReport::manualOnlyMode() || ! $client->enabled(), 422, 'Text AI is unavailable.');
        $humanSources = ['human_responder', 'text_human_override', 'manual_override'];
        abort_if(in_array($incidentReport->ai_source, $humanSources, true), 422, 'An admin has already assessed this report.');
        $description = (string) $incidentReport->description;
        $analysis = $client->analyze($description);
        $modelAbstained = $analysis['source'] === 'text_review' && $analysis['model_name'] !== null;
        if (! in_array($analysis['source'], ['text_model', 'text_model_experimental', 'text_rule_advisory'], true) && ! $modelAbstained) {
            $message = 'Text analysis is unavailable. The existing assessment was preserved; please retry or review it manually.';
            return $request->expectsJson() ? response()->json(['message' => $message], 422)
                : back()->with('status', $message)->with('ai_review_report_id', $incidentReport->id);
        }
        if ($modelAbstained) {
            $analysis['severity'] = null;
            if (! str_starts_with($analysis['summary'], 'Experimental word/phrase rules;')) {
                $analysis['summary'] = 'The text model could not suggest a supported severity from this description. Admin review is required.';
            }
        }

        $report = DB::transaction(function () use ($incidentReport, $description, $analysis, $request, $humanSources): IncidentReport {
            $report = IncidentReport::query()->lockForUpdate()->findOrFail($incidentReport->id);
            abort_if((string) $report->description !== $description || in_array($report->ai_source, $humanSources, true),
                409, 'The report changed during analysis. Refresh and review its current assessment.');
            $log = $report->coordination_log ?? [];
            $log[] = [
                'label' => 'Text AI suggestion requested',
                'actor_id' => $request->user()->id,
                'actor_name' => $request->user()->name,
                'actor_role' => 'Admin',
                'status' => $report->status,
                'description_sha256' => hash('sha256', $description),
                'model_name' => $analysis['model_name'],
                'model_version' => $analysis['model_version'],
                'suggested_severity' => $analysis['severity'],
                'occurred_at' => now()->toIso8601String(),
            ];
            $report->update([
                'severity' => $analysis['severity'],
                'ai_confidence' => $analysis['confidence'],
                'ai_summary' => $analysis['summary'],
                'ai_source' => $analysis['source'],
                'ai_status' => $analysis['status'],
                'ai_model_name' => $analysis['model_name'],
                'ai_model_version' => $analysis['model_version'],
                'ai_probabilities' => $analysis['probabilities'],
                'ai_review_required' => true,
                'ai_processed_at' => now(),
                'ai_error_message' => null,
                'coordination_log' => $log,
            ]);

            return $report;
        });
        IncidentFeedBroadcaster::dispatch($report, 'ai_suggestion');

        $message = $modelAbstained
            ? 'Text analysis completed without a supported severity suggestion. More information or admin review is required.'
            : 'AI suggestion saved. Read the description and confirm or correct the severity.';
        if ($topCategory = $report->ai_top_category) {
            $message = 'Text analyzed. Highest model category: '.$topCategory['label'].' ('
                .number_format($topCategory['score'], 1).'%). Admin review is required.';
        }
        if ($request->expectsJson()) {
            return response()->json([
                'report_id' => $report->id,
                'message' => $message,
                'top_category' => $report->ai_top_category,
                'current_severity_category' => $report->current_severity_category,
                'analysis_html' => view('reports.partials.ai-analysis', ['report' => $report])->render(),
                'compact_html' => view('reports.partials.ai-analysis', ['report' => $report, 'compact' => true])->render(),
                'review_html' => view('reports.partials.severity-review-form', ['report' => $report])->render(),
            ]);
        }

        return back()->with('status', $message)->with('ai_review_report_id', $report->id);
    }
}
