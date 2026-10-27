<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('face_liveness_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 12);
            $table->unsignedTinyInteger('blink_count');
            $table->char('selfie_hash', 64)->nullable();
            $table->char('proof_hash', 64)->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('face_liveness_challenges'); }
};
