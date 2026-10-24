<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename the former system station label, not incident/location history.
        DB::table('responder_profiles')
            ->whereIn('assigned_station', ['Tomas Oppus Command Center', 'TOMAS OPPUS COMMAND CENTER'])
            ->update(['assigned_station' => 'ResQMesh Command Center']);
    }

    public function down(): void
    {
        // Existing and newly edited station labels cannot be distinguished safely.
    }
};
