<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->string('last_login_ip_address', 45)->nullable()->after('last_login_at');
            $table->text('last_login_user_agent')->nullable()->after('last_login_ip_address');
            $table->timestamp('blocked_at')->nullable()->after('last_login_user_agent');
            $table->foreignId('blocked_by')->nullable()->after('blocked_at')->constrained('users')->nullOnDelete();
            $table->string('blocked_reason', 255)->nullable()->after('blocked_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn([
                'last_login_at',
                'last_login_ip_address',
                'last_login_user_agent',
                'blocked_at',
                'blocked_reason',
            ]);
        });
    }
};
