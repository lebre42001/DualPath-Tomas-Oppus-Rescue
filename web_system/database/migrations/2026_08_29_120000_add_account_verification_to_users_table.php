<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('account_verified_at')->nullable()->after('email_verified_at');
            $table->string('account_verification_selfie_path')->nullable()->after('profile_photo_path');
            $table->string('account_verification_method', 60)->nullable()->after('account_verification_selfie_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'account_verified_at',
                'account_verification_selfie_path',
                'account_verification_method',
            ]);
        });
    }
};
