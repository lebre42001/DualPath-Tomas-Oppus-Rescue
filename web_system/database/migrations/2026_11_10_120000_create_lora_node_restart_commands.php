<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lora_node_restart_commands', function (Blueprint $table): void {
            $table->id();
            $table->string('node_id', 16)->index();
            // A nullable unique key enforces at most one live command per node,
            // including two administrators requesting a restart simultaneously.
            $table->string('active_node_id', 16)->nullable()->unique();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedBigInteger('actor_reference')->nullable();
            $table->string('actor_name');
            $table->string('expected_boot', 16);
            $table->string('accepted_boot', 16)->nullable();
            $table->string('restarted_boot', 16)->nullable();
            $table->string('failure_reason', 40)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_offered_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('restarted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['node_id', 'requested_at']);
        });

        Schema::table('lora_node_states', function (Blueprint $table): void {
            $table->string('restart_protocol', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lora_node_states', function (Blueprint $table): void {
            $table->dropColumn('restart_protocol');
        });
        Schema::dropIfExists('lora_node_restart_commands');
    }
};
