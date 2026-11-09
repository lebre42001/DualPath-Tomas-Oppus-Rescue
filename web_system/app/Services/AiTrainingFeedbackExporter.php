<?php

namespace App\Services;

use App\Models\AiTrainingFeedback;
use App\Support\IncidentCategory;
use Illuminate\Support\Facades\File;

class AiTrainingFeedbackExporter
{
    /** Only explicit, current feedback snapshots are read; historical reports are never mined. */
    public function export(): array
    {
        $counts = ['eligible' => 0, 'exported_rows' => 0, 'excluded' => 0, 'duplicates' => 0, 'conflicts' => 0];
        $groups = [];
        AiTrainingFeedback::query()->where('approved_for_training', true)->whereNull('superseded_at')
            ->whereNotNull('incident_report_id')->whereNotNull('reviewer_id')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$counts, &$groups): void {
                foreach ($rows as $row) {
                    $description = $row->description_snapshot;
                    if (! in_array($row->incident_type, array_keys(IncidentCategory::LABELS), true)
                        || ! in_array($row->language, ['en', 'tl', 'ceb', 'mixed'], true)
                        || ! in_array($row->reviewed_label, ['minor', 'serious', 'critical', 'needs_review'], true)
                        || $row->review_scope !== 'description_only'
                        || ! hash_equals($row->description_sha256, hash('sha256', $description))
                        || ! $this->textMayBeExported($description)) {
                        $counts['excluded']++;

                        continue;
                    }
                    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $description)));
                    $key = hash('sha256', $row->incident_type.'|'.$normalized);
                    $groups[$key][] = $row;
                }
            });

        $lines = [];
        foreach ($groups as $duplicates) {
            if (count(array_unique(array_map(fn ($row) => $row->reviewed_label, $duplicates))) > 1) {
                $counts['conflicts'] += count($duplicates);
            } else {
                $counts['eligible']++;
                $counts['duplicates'] += count($duplicates) - 1;
            }
            // Retain every screened approval, including conflicting reviews.
            // The worker needs all source aliases to union families and quarantine
            // their linked paraphrases before it removes duplicate training rows.
            foreach ($duplicates as $row) {
                $lines[] = json_encode([
                    'schema_version' => 1,
                    'feedback_id' => $this->opaque('feedback', $row->id),
                    'group_id' => $this->opaque('report', $row->incident_report_id),
                    'incident_type' => $row->incident_type,
                    'description' => $row->description_snapshot,
                    'description_sha256' => $row->description_sha256,
                    'label' => $row->reviewed_label,
                    'language' => $row->language,
                    'reviewer' => $this->opaque('reviewer', $row->reviewer_id),
                    'reviewed_at' => $row->reviewed_at->toIso8601String(),
                    'review_scope' => $row->review_scope,
                    'review_method' => $row->review_method,
                    'notes' => '[Internal review reason retained in the private feedback audit.]',
                    'approved_for_training' => true,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $counts['exported_rows'] = count($lines);
        $body = $lines ? implode("\n", $lines)."\n" : '';
        $root = $this->directory();
        File::ensureDirectoryExists($root, 0700, true);
        File::replace($root.DIRECTORY_SEPARATOR.'feedback.jsonl', $body, 0600);
        $manifest = [
            'schema_version' => 1, 'generated_at' => now()->toIso8601String(),
            'snapshot_sha256' => hash('sha256', $body), 'counts' => $counts,
            'partition' => 'candidate_pool', 'contains_independent_holdout' => false,
            'eligible_count_scope' => 'Unique category/text candidates without within-category label conflicts; source-family validation is deferred to the worker.',
            'row_policy' => 'All screened current approvals, retaining duplicate source aliases and conflicting reviews for worker quarantine.',
            'active_model_changed' => false,
        ];
        File::replace($root.DIRECTORY_SEPARATOR.'manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", 0600);

        return [...$manifest, 'feedback_path' => $root.DIRECTORY_SEPARATOR.'feedback.jsonl'];
    }

    public function directory(): string
    {
        // Fixed private location: never the public storage disk or an HTTP upload endpoint.
        return storage_path('app/private/ai-learning');
    }

    private function opaque(string $kind, int $id): string
    {
        return $kind.'_'.substr(hash_hmac('sha256', $kind.'|'.$id, (string) config('app.key')), 0, 32);
    }

    private function textMayBeExported(string $text): bool
    {
        // This is a conservative screen, not a claim of automatic anonymization.
        // The admin must also attest that the complete text has been inspected.
        return mb_strlen(trim($text)) >= 8 && mb_strlen($text) <= 2000
            && ! preg_match('/\b(?:demo|simulated|simulation|fixture|test\s+only)\b/iu', $text)
            && ! preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|https?:\/\/|www\./iu', $text)
            && ! preg_match('/\b\d(?:[\s()+-]*\d){6,}\b/u', $text)
            && ! preg_match('/\b(?:name|pangalan|ngalan|address|email|phone|contact|mobile)\s*[:=]/iu', $text);
    }
}
