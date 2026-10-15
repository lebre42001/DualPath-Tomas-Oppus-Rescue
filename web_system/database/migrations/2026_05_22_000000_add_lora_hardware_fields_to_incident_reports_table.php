<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('hardware_source', 60)->nullable()->after('status_updated_at');
            $table->string('lora_sender_id', 40)->nullable()->after('hardware_source');
            $table->string('lora_sequence', 40)->nullable()->after('lora_sender_id');
            $table->string('lora_gateway_id', 40)->nullable()->after('lora_sequence');
            $table->integer('lora_gateway_rssi')->nullable()->after('lora_gateway_id');
            $table->decimal('lora_gateway_snr', 6, 2)->nullable()->after('lora_gateway_rssi');
            $table->integer('lora_receiver_rssi')->nullable()->after('lora_gateway_snr');
            $table->decimal('lora_receiver_snr', 6, 2)->nullable()->after('lora_receiver_rssi');
            $table->unsignedTinyInteger('lora_satellites')->nullable()->after('lora_receiver_snr');

            $table->index(['hardware_source', 'lora_sender_id'], 'incident_reports_hardware_sender_index');
            $table->unique(['lora_sender_id', 'lora_sequence'], 'incident_reports_lora_sender_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->dropUnique('incident_reports_lora_sender_sequence_unique');
            $table->dropIndex('incident_reports_hardware_sender_index');
            $table->dropColumn([
                'hardware_source',
                'lora_sender_id',
                'lora_sequence',
                'lora_gateway_id',
                'lora_gateway_rssi',
                'lora_gateway_snr',
                'lora_receiver_rssi',
                'lora_receiver_snr',
                'lora_satellites',
            ]);
        });
    }
};
