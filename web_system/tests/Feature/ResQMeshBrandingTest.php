<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResQMeshBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_label_migration_preserves_incident_places_and_custom_stations(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $default = $admin->responderProfile()->create(['assigned_station' => 'Tomas Oppus Command Center']);
        $other = User::factory()->create()->responderProfile()->create(['assigned_station' => 'Tomas Oppus roadside station']);
        $report = IncidentReport::create([
            'reported_by' => $admin->id, 'report_code' => 'INC-BRAND-PLACE',
            'reporter_name' => $admin->name, 'incident_type' => 'Road Accident',
            'severity' => 'Serious', 'status' => 'received', 'channel' => 'Internet',
            'transmission_type' => 'online', 'location_text' => 'Bogo, Tomas Oppus, Southern Leyte',
            'description' => 'Road collision in Tomas Oppus.',
        ]);

        $migration = require database_path('migrations/2026_10_24_160000_rename_command_center_brand_to_resqmesh.php');
        $migration->up();
        $migration->up();

        $this->assertSame('ResQMesh Command Center', $default->fresh()->assigned_station);
        $this->assertSame('Tomas Oppus roadside station', $other->fresh()->assigned_station);
        $this->assertSame('Bogo, Tomas Oppus, Southern Leyte', $report->fresh()->location_text);
        $this->assertSame('Road collision in Tomas Oppus.', $report->fresh()->description);
    }
}
