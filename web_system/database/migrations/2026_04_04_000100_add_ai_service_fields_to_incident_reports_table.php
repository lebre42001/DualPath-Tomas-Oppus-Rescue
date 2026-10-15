<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('ai_source', 40)->nullable()->after('ai_confidence');
            $table->string('ai_status', 30)->nullable()->after('ai_source');
            $table->string('ai_model_name')->nullable()->after('ai_status');
            $table->string('ai_model_version', 40)->nullable()->after('ai_model_name');
            $table->boolean('ai_review_required')->default(false)->after('ai_model_version');
            $table->json('ai_probabilities')->nullable()->after('ai_review_required');
            $table->timestamp('ai_processed_at')->nullable()->after('ai_probabilities');
            $table->text('ai_error_message')->nullable()->after('ai_processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_source',
                'ai_status',
                'ai_model_name',
                'ai_model_version',
                'ai_review_required',
                'ai_probabilities',
                'ai_processed_at',
                'ai_error_message',
            ]);
        });
    }
};
