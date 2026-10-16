<?php

use App\Models\IncidentReport;
use App\Services\IncidentReportAuditRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_report_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_report_id')->nullable()->constrained('incident_reports')->nullOnDelete();
            $table->string('entry_key', 64)->unique();
            $table->string('report_code');
            $table->string('incident_type');
            $table->string('transmission_type', 20)->index();
            $table->string('node_id', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name');
            $table->string('actor_role', 40);
            $table->string('action', 40)->index();
            $table->string('label');
            $table->string('status_before', 40)->nullable();
            $table->string('status_after', 40)->nullable();
            $table->string('severity_before', 80)->nullable();
            $table->string('severity_after', 80)->nullable();
            $table->text('notes')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->boolean('imported')->default(false);
            $table->timestamps();
        });

        // Import only surviving, explicitly recorded admin entries; do not fabricate old deletions.
        IncidentReport::query()->whereNotNull('coordination_log')->chunkById(100, function ($reports): void {
            foreach ($reports as $report) {
                app(IncidentReportAuditRecorder::class)->sync($report, true);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_report_audits');
    }
};
