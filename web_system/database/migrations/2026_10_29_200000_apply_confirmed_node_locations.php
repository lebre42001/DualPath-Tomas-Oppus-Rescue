<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One-time operator-confirmed correction of existing admin overrides.
        // Missing rows continue to use config defaults; future admin edits still win.
        // Freeze this deployment's values rather than reading a future config revision.
        $locations = [
            'NODE_1' => ['MDRRMO office', 10.304469, 124.979829],
            'NODE_2' => ['Node 2 location', 10.3042205, 124.9805496],
            'NODE_3' => ['Node 3 location', 10.3035340, 124.9813019],
            'NODE_4' => ['Node 4 location', 10.3028159, 124.9820385],
            'NODE_5' => ['Cumajig', 10.3025949, 124.9823205],
            'NODE_6' => ['Node 6 location', 10.3002690, 124.9818665],
            'NODE_7' => ['Node 7 location', 10.2990587, 124.9816322],
            'NODE_8' => ['Node 8 location', 10.2971533, 124.9814451],
            'NODE_9' => ['Node 9 location', 10.2960984, 124.9801040],
            'NODE_10' => ['Node 10 location', 10.2932380, 124.9777614],
        ];
        foreach ($locations as $nodeId => [$label, $latitude, $longitude]) {
            DB::table('lora_node_locations')->where('node_id', $nodeId)->update([
                'location_label' => $label, 'latitude' => $latitude,
                'longitude' => $longitude, 'updated_at' => now(),
            ]);
        }
        // Existing incident reports retain their original recorded coordinates.
    }

    public function down(): void
    {
        // Operator location data (including any later admin edits) is retained.
    }
};
