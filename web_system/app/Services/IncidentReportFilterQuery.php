<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IncidentReportFilterQuery
{
    public function parameters(Request $request, ?User $user): array
    {
        $input = $request->only(['search', 'severity', 'status', 'transmission', 'sort']);
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }
        if (is_string($input['severity'] ?? null)) {
            $input['severity'] = match (strtolower($input['severity'])) {
                'minor' => 'Minor', 'serious' => 'Serious', 'critical', 'fatal' => 'Critical',
                'needs_review', 'needs review' => 'Needs review', 'unassessed' => 'Unassessed',
                default => $input['severity'],
            };
        }
        if (is_string($input['status'] ?? null)) {
            $input['status'] = match (strtolower($input['status'])) {
                'pending', 'queued' => 'received', 'cancelled', 'canceled' => 'rejected',
                default => strtolower($input['status']),
            };
        }
        foreach (['transmission', 'sort'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = strtolower($input[$key]);
            }
        }
        if ($user?->isCivilian()) {
            $input['severity'] = $input['transmission'] = '';
            if (($input['sort'] ?? '') === 'severity') {
                $input['sort'] = 'newest';
            }
        }
        $values = Validator::make($input, [
            'search' => ['nullable', 'string', 'max:180'],
            'severity' => ['nullable', 'string', 'in:Minor,Serious,Critical,Needs review,Unassessed'],
            'status' => ['nullable', 'string', 'in:received,acknowledged,dispatched,responding,resolved,rejected'],
            'transmission' => ['nullable', 'string', 'in:online,lora'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,severity,status'],
        ])->validate();

        return [
            'search' => preg_replace('/\s+/u', ' ', $values['search'] ?? ''),
            'severity' => $values['severity'] ?? '', 'status' => $values['status'] ?? '',
            'transmission' => $values['transmission'] ?? '', 'sort' => ($values['sort'] ?? '') ?: 'newest',
        ];
    }

    public function build(Request $request, ?User $user): Builder
    {
        $filters = $this->parameters($request, $user);
        $query = $this->visibleReports($user);
        // Every search word must match at least one report field. Symbols are literal.
        foreach (preg_split('/\s+/u', $filters['search'], -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
            $query->where(function (Builder $scoped) use ($pattern): void {
                foreach (['report_code', 'incident_type', 'location_text', 'description', 'reporter_name', 'lora_sender_id', 'lora_sequence'] as $field) {
                    $column = $scoped->getQuery()->getGrammar()->wrap($scoped->getModel()->qualifyColumn($field));
                    $scoped->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                $scoped->orWhereHas('reporter', fn (Builder $reporter) => $reporter->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        if ($filters['severity'] !== '') {
            $query->whereRaw('('.$this->severityExpression($query).') = ?', [$filters['severity']]);
        }
        if ($filters['status'] !== '') {
            $statuses = match ($filters['status']) {
                'received' => ['received', 'pending', 'queued'],
                'rejected' => ['rejected', 'cancelled', 'canceled'],
                default => [$filters['status']],
            };
            $query->whereIn('status', $statuses);
        }
        if ($filters['transmission'] !== '') {
            $query->whereRaw('LOWER(TRIM(transmission_type)) = ?', [$filters['transmission']]);
        }

        return $query;
    }

    /** The admin Online Report tab and its exports contain online submissions. */
    public function visibleReports(?User $user): Builder
    {
        $query = IncidentReport::query();

        return $user?->isCivilian()
            ? $query->where('reported_by', $user->id)
            : $query->whereRaw('LOWER(TRIM(transmission_type)) = ?', ['online']);
    }

    /** Match the model's current category in SQL before pagination and exports. */
    public function severityExpression(Builder $query): string
    {
        $column = fn ($name) => $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn($name));
        $source = $column('ai_source');
        $severity = $column('severity');
        $pending = "('manual_only','text_review','text_model','text_model_experimental','text_rule_advisory')";
        $final = "CASE LOWER(TRIM({$severity})) WHEN 'minor' THEN 'Minor' WHEN 'serious' THEN 'Serious' WHEN 'critical' THEN 'Critical' WHEN 'fatal' THEN 'Critical' ELSE 'Unassessed' END";
        $ai = IncidentReport::manualOnlyMode() ? "'Unassessed'" : $this->topCategoryExpression($query, $column('ai_probabilities'));

        return "CASE WHEN {$source} IN ('human_responder','manual_override','text_human_override') AND {$severity} IS NULL THEN 'Needs review' "
            ."WHEN {$severity} IS NOT NULL AND ({$source} IS NULL OR {$source} NOT IN {$pending}) THEN {$final} "
            ."WHEN {$source} IN ('text_review','text_model','text_model_experimental','text_rule_advisory') THEN {$ai} ELSE 'Unassessed' END";
    }

    private function topCategoryExpression(Builder $query, string $column): string
    {
        $driver = $query->getConnection()->getDriverName();
        $keys = ['minor', 'serious', 'critical', 'needs_review'];
        $scores = [];
        $valid = [];
        if ($driver === 'pgsql') {
            $doc = "COALESCE({$column}::jsonb, '{}'::jsonb)";
            $object = "CASE WHEN jsonb_typeof({$doc}) = 'object' THEN {$doc} ELSE '{}'::jsonb END";
            $valid[] = "(SELECT COUNT(*) FROM jsonb_object_keys({$object})) = 4";
            foreach ($keys as $key) {
                $numeric = "jsonb_typeof({$doc}->'{$key}') = 'number'";
                $valid[] = $numeric;
                $scores[$key] = "(CASE WHEN {$numeric} THEN ({$doc}->>'{$key}')::numeric ELSE NULL END)";
            }
        } else {
            $doc = "(CASE WHEN JSON_VALID({$column}) THEN {$column} ELSE '{}' END)";
            $valid[] = $driver === 'sqlite' ? "(SELECT COUNT(*) FROM json_each({$doc})) = 4" : "JSON_LENGTH({$doc}) = 4";
            foreach ($keys as $key) {
                $value = "JSON_EXTRACT({$doc}, '$.{$key}')";
                $valid[] = $driver === 'sqlite'
                    ? "JSON_TYPE({$doc}, '$.{$key}') IN ('integer','real')"
                    : "JSON_TYPE({$value}) IN ('INTEGER','DOUBLE')";
                $scores[$key] = $driver === 'sqlite' ? "CAST({$value} AS REAL)" : "CAST(JSON_UNQUOTE({$value}) AS DECIMAL(18,9))";
            }
        }
        foreach ($scores as $score) {
            $valid[] = "({$score} BETWEEN 0 AND 1)";
        }
        $valid[] = 'ABS(('.implode(' + ', $scores).') - 1) <= 0.0001';
        $ranking = 'CASE ';
        foreach (['minor' => 'Minor', 'serious' => 'Serious', 'critical' => 'Critical'] as $key => $label) {
            $higher = [];
            foreach ($scores as $other => $score) {
                if ($other !== $key) {
                    $higher[] = "ROUND({$scores[$key]} - {$score}, 9) >= 0.000001";
                }
            }
            $ranking .= 'WHEN '.implode(' AND ', $higher)." THEN '{$label}' ";
        }

        return 'CASE WHEN '.implode(' AND ', $valid)." THEN ({$ranking} ELSE 'Needs review' END) ELSE 'Unassessed' END";
    }
}
