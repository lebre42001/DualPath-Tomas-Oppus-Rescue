<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('lora_verification', 30)->default('unverified');
            $table->text('lora_verification_notes')->nullable();
            $table->unsignedBigInteger('lora_verified_by')->nullable();
            $table->timestamp('lora_verified_at')->nullable();
            $table->string('lora_incident_landmark', 100)->nullable();
            $table->string('lora_radio_auth', 30)->nullable();
        });
        Schema::table('lora_node_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('blocked_submissions')->nullable();
            $table->unsignedBigInteger('radio_rejections')->nullable();
            $table->string('guard_boot', 16)->nullable();
            $table->string('radio_auth', 30)->nullable();
            $table->timestamp('guard_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', fn (Blueprint $table) => $table->dropColumn([
            'lora_verification', 'lora_verification_notes', 'lora_verified_by', 'lora_verified_at',
            'lora_incident_landmark', 'lora_radio_auth',
        ]));
        Schema::table('lora_node_states', fn (Blueprint $table) => $table->dropColumn([
            'blocked_submissions', 'radio_rejections', 'guard_boot', 'radio_auth', 'guard_reported_at',
        ]));
    }
};
