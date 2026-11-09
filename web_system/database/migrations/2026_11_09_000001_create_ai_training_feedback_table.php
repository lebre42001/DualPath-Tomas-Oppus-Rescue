<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_training_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fingerprint', 64)->unique();
            $table->string('description_sha256', 64);
            $table->text('description_snapshot');
            $table->string('incident_type', 80)->nullable();
            $table->string('reviewed_label', 20);
            $table->string('review_scope', 30)->default('description_only');
            $table->string('review_method', 30);
            $table->text('review_reason');
            $table->json('original_ai_snapshot')->nullable();
            $table->boolean('approved_for_training')->default(false);
            $table->string('language', 10)->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['approved_for_training', 'superseded_at']);
            $table->index(['incident_report_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_feedback');
    }
};
