<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responder_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 40)->nullable();
            $table->string('assigned_station')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('connectivity_mode')->default('auto_select');
            $table->string('notification_profile')->default('priority_only');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responder_profiles');
    }
};