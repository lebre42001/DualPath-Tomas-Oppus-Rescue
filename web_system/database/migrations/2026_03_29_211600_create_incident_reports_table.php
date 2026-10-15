<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('report_code')->unique();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reporter_name');
            $table->string('reporter_contact', 40)->nullable();
            $table->string('incident_type');
            $table->string('severity', 20);
            $table->string('status', 30)->default('queued');
            $table->string('channel', 40)->default('auto_select');
            $table->string('location_text');
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->text('description');
            $table->text('ai_summary')->nullable();
            $table->timestamp('transmitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['severity', 'status']);
            $table->index('incident_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_reports');
    }
};