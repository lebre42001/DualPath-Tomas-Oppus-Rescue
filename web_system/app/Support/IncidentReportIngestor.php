<?php

namespace App\Support;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Support\Str;

class IncidentReportIngestor
{
    public function onlineKey(User $reporter, mixed $submissionId): ?string
    {
        $submissionId = trim((string) $submissionId);

        if ($submissionId === '') {
            return null;
        }

        return $this->key('online', (string) $reporter->getKey(), $submissionId);
    }

    public function loraKey(string $senderId, string $sequence): string
    {
        return $this->key('lora', $senderId, $sequence);
    }

    public function existing(?string $ingestKey): ?IncidentReport
    {
        if ($ingestKey === null) {
            return null;
        }

        return IncidentReport::query()->where('ingest_key', $ingestKey)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{report: IncidentReport, created: bool}
     */
    public function persist(?string $ingestKey, array $attributes): array
    {
        if ($ingestKey === null) {
            return [
                'report' => IncidentReport::query()->create($attributes),
                'created' => true,
            ];
        }

        $report = IncidentReport::query()->firstOrCreate(
            ['ingest_key' => $ingestKey],
            $attributes,
        );

        return [
            'report' => $report,
            'created' => $report->wasRecentlyCreated,
        ];
    }

    private function key(string $source, string ...$parts): string
    {
        $normalized = array_map(
            fn (string $part): string => Str::lower(trim($part)),
            $parts,
        );

        return $source.':'.hash('sha256', implode("\0", $normalized));
    }
}
