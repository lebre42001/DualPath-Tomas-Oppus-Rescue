<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('photo_relevance_label', 40)->nullable()->after('ai_error_message');
            $table->unsignedTinyInteger('photo_relevance_confidence')->nullable()->after('photo_relevance_label');
            $table->json('photo_relevance_probabilities')->nullable()->after('photo_relevance_confidence');
            $table->boolean('photo_relevance_overridden')->default(false)->after('photo_relevance_probabilities');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'photo_relevance_label',
                'photo_relevance_confidence',
                'photo_relevance_probabilities',
                'photo_relevance_overridden',
            ]);
        });
    }
};
