<?php

use App\Services\CivilianActivityRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('civilian_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_report_id')->nullable()->constrained('incident_reports')->nullOnDelete();
            $table->string('report_code')->nullable();
            $table->string('kind', 40);
            $table->string('title', 160);
            $table->text('message');
            $table->string('app_version', 16)->nullable();
            $table->string('source_key', 64)->nullable()->unique();
            $table->boolean('notifiable')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'notifiable', 'read_at']);
        });

        // Import only surviving reports and audit entries with recorded times.
        // Old sessions and deleted-report ownership cannot be reconstructed.
        app(CivilianActivityRecorder::class)->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('civilian_activities');
    }
};
