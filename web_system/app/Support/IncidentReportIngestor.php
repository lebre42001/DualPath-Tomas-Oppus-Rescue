<?php

namespace App\Support;

use App\Models\IncidentReport;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
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

    /** Serialize a radio identity so deletion and a late retry cannot race. */
    public function withLoraReceipt(string $ingestKey, Closure $operation): mixed
    {
        return DB::transaction(function () use ($ingestKey, $operation): mixed {
            DB::table('lora_report_receipts')->insertOrIgnore([
                'ingest_key' => $ingestKey,
                'deleted_at' => null,
            ]);
            $receipt = DB::table('lora_report_receipts')->where('ingest_key', $ingestKey)
                ->lockForUpdate()->first();

            return $operation($receipt->deleted_at !== null);
        }, 3);
    }

    /** Called inside the report deletion transaction, preserving no report PII. */
    public function rememberLoraDeletion(IncidentReport $report): void
    {
        if (strtolower(trim((string) $report->transmission_type)) !== 'lora') {
            return;
        }

        $ingestKey = filled($report->lora_sender_id) && filled($report->lora_sequence)
            ? $this->loraKey($report->lora_sender_id, $report->lora_sequence)
            : $report->ingest_key;
        if (! is_string($ingestKey) || preg_match('/^lora:[a-f0-9]{64}$/D', $ingestKey) !== 1) {
            return;
        }

        $this->withLoraReceipt($ingestKey, function () use ($ingestKey): void {
            DB::table('lora_report_receipts')->where('ingest_key', $ingestKey)
                ->whereNull('deleted_at')->update(['deleted_at' => now()]);
        });
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
