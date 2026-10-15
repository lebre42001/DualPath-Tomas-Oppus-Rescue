<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lora_node_states', function (Blueprint $table): void {
            $table->decimal('snr', 7, 2)->nullable();
            $table->unsignedInteger('round_trip_ms')->nullable();
            $table->string('round_trip_sample', 40)->nullable();
            $table->timestamp('round_trip_measured_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lora_node_states', function (Blueprint $table): void {
            $table->dropColumn(['snr', 'round_trip_ms', 'round_trip_sample', 'round_trip_measured_at']);
        });
    }
};
