<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('responder_profiles')
            ->whereIn('assigned_station', ['ResQMesh Command Center', 'RESQMESH COMMAND CENTER'])
            ->update(['assigned_station' => 'AksyRes Command Center']);
    }

    public function down(): void
    {
        // Keep user-edited station names and incident history intact.
    }
};
