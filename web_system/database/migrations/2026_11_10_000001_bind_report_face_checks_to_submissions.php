<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_liveness_challenges', function (Blueprint $table): void {
            $table->uuid('submission_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('face_liveness_challenges', function (Blueprint $table): void {
            $table->dropColumn('submission_id');
        });
    }
};
