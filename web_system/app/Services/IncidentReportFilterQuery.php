<?php

namespace App\Services;

use App\Models\IncidentReport;
use App\Models\User;
use App\Support\AiSeverityMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IncidentReportFilterQuery
{
    public function build(Request $request, ?User $user): Builder
    {
        $isCivilian = $user?->isCivilian() ?? false;

        return IncidentReport::query()
            ->when($isCivilian, fn (Builder $query) => $query->where('reported_by', $user?->id))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = trim((string) $request->string('search'));

                $query->where(function (Builder $scoped) use ($term): void {
                    $scoped
                        ->where('report_code', 'like', "%{$term}%")
                        ->orWhere('incident_type', 'like', "%{$term}%")
                        ->orWhere('location_text', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%")
                        ->orWhere('reporter_name', 'like', "%{$term}%");
                });
            })
            ->when(
                ! $isCivilian && $request->filled('severity'),
                fn (Builder $query) => $query->operationallyAssessed()
                    ->where('severity', AiSeverityMapper::normalizeSeverity((string) $request->string('severity')))
            )
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', (string) $request->string('status'))
            )
            ->when(
                ! $isCivilian && $request->filled('transmission'),
                fn (Builder $query) => $query->where('transmission_type', match (Str::lower(trim((string) $request->string('transmission')))) {
                    'lora' => 'lora',
                    default => 'online',
                })
            );
    }
}
