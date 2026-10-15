<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->foreignId('assigned_responder_id')->nullable()->after('reported_by')->constrained('users')->nullOnDelete();
            $table->string('transmission_type', 20)->default('online')->after('channel');
            $table->unsignedTinyInteger('ai_confidence')->nullable()->after('ai_summary');
            $table->string('evidence_type', 20)->nullable()->after('ai_confidence');
            $table->string('evidence_path')->nullable()->after('evidence_type');
            $table->string('evidence_original_name')->nullable()->after('evidence_path');
            $table->text('response_notes')->nullable()->after('evidence_original_name');
            $table->timestamp('status_updated_at')->nullable()->after('resolved_at');
        });

        DB::table('incident_reports')->whereIn('severity', ['Low', 'low'])->update(['severity' => 'Minor']);
        DB::table('incident_reports')->whereIn('severity', ['Medium', 'medium'])->update(['severity' => 'Minor']);
        DB::table('incident_reports')->whereIn('severity', ['High', 'high'])->update(['severity' => 'Serious']);

        DB::table('incident_reports')
            ->where('channel', 'LoRa fallback')
            ->update([
                'channel' => 'LoRa Mesh',
                'transmission_type' => 'lora',
                'status_updated_at' => now(),
            ]);

        DB::table('incident_reports')
            ->where('channel', '!=', 'LoRa Mesh')
            ->orWhereNull('channel')
            ->update([
                'channel' => 'Internet',
                'transmission_type' => 'online',
                'status_updated_at' => now(),
            ]);

        DB::table('incident_reports')
            ->where('status', 'queued')
            ->update(['status' => 'received']);

        DB::table('incident_reports')
            ->where('status', 'transmitted')
            ->update(['status' => 'received']);
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_responder_id');
            $table->dropColumn([
                'transmission_type',
                'ai_confidence',
                'evidence_type',
                'evidence_path',
                'evidence_original_name',
                'response_notes',
                'status_updated_at',
            ]);
        });
    }
};
