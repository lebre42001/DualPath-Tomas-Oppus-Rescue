<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activities', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_key', 100)->nullable()->unique();
            $table->unsignedBigInteger('actor_reference')->nullable()->index();
            $table->string('actor_name');
            $table->string('category', 30)->index();
            $table->string('action', 100)->index();
            $table->string('label');
            $table->text('summary');
            $table->string('target_label')->nullable()->index();
            $table->foreignId('incident_report_id')->nullable()->constrained('incident_reports')->nullOnDelete();
            $table->string('source', 20)->default('system');
            $table->string('route', 150)->nullable();
            $table->json('context')->nullable();
            $table->boolean('imported')->default(false);
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();
            $table->index(['category', 'occurred_at']);
        });
        Schema::create('admin_duty_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('actor_reference')->index();
            $table->string('actor_name');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamp('last_activity_at');
            $table->timestamps();
        });
        app(\App\Services\AdminActivityRecorder::class)->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_duty_shifts');
        Schema::dropIfExists('admin_activities');
    }
};
