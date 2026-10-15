<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('severity', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('incident_reports')->whereNull('severity')->exists()) {
            throw new \RuntimeException('Cannot restore NOT NULL severity while manual-review reports have no responder-assigned severity.');
        }

        Schema::table('incident_reports', function (Blueprint $table): void {
            $table->string('severity', 20)->nullable(false)->change();
        });
    }
};
