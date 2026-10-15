<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lora_node_states', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 40)->unique();
            $table->string('gateway_id', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_known_route', 200)->nullable();
            $table->json('known_peers')->nullable();
            $table->string('battery', 20)->nullable();
            $table->unsignedSmallInteger('hop')->nullable();
            $table->unsignedSmallInteger('ttl')->nullable();
            $table->integer('rssi')->nullable();
            $table->string('last_packet_type', 12)->nullable();
            $table->timestamps();
        });

        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->timestamp('lora_ack_queued_at')->nullable()->after('lora_satellites');
            $table->timestamp('lora_ack_delivered_at')->nullable()->after('lora_ack_queued_at');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropColumn(['lora_ack_queued_at', 'lora_ack_delivered_at']);
        });

        Schema::dropIfExists('lora_node_states');
    }
};
