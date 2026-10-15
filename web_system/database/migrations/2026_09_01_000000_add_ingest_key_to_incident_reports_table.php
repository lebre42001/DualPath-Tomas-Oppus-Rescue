<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('ingest_key', 80)->nullable()->after('report_code');
        });

        DB::table('incident_reports')
            ->where('transmission_type', 'lora')
            ->whereNotNull('lora_sender_id')
            ->whereNotNull('lora_sequence')
            ->orderBy('id')
            ->chunkById(100, function ($reports): void {
                foreach ($reports as $report) {
                    $identity = implode("\0", [
                        Str::lower(trim((string) $report->lora_sender_id)),
                        Str::lower(trim((string) $report->lora_sequence)),
                    ]);

                    DB::table('incident_reports')
                        ->where('id', $report->id)
                        ->update(['ingest_key' => 'lora:'.hash('sha256', $identity)]);
                }
            });

        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->unique('ingest_key', 'incident_reports_ingest_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropUnique('incident_reports_ingest_key_unique');
            $table->dropColumn('ingest_key');
        });
    }
};
