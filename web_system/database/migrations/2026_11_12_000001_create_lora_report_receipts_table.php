<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lora_report_receipts', function (Blueprint $table): void {
            // Retain only a hashed radio identity, never deleted report content.
            // The same row serializes ingestion and deletion of that identity.
            $table->string('ingest_key', 80)->primary();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lora_report_receipts');
    }
};
