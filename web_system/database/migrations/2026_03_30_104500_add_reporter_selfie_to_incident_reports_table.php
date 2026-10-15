<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('reporter_selfie_path')->nullable()->after('evidence_original_name');
            $table->string('reporter_selfie_original_name')->nullable()->after('reporter_selfie_path');
            $table->timestamp('reporter_selfie_captured_at')->nullable()->after('reporter_selfie_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropColumn([
                'reporter_selfie_path',
                'reporter_selfie_original_name',
                'reporter_selfie_captured_at',
            ]);
        });
    }
};
