<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('civilian_account_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('civilian_account_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('target_name', 120);
            $table->string('target_email')->nullable();
            $table->string('target_phone', 40)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('civilian_account_audits');
    }
};
