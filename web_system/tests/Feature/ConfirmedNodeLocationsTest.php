<?php

namespace Tests\Feature;

use App\Models\LoraNodeLocation;
use App\Models\User;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmedNodeLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_ten_confirmed_locations_are_visible_even_without_heartbeats(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_admin' => true]));
        $response = $this->getJson('/admin/node-locations/points')->assertOk()->assertJsonCount(10, 'points');
        $points = collect($response->json('points'))->keyBy('node_id');
        $expected = [
            'NODE_1' => [10.304469, 124.979829], 'NODE_2' => [10.3042205, 124.9805496],
            'NODE_3' => [10.3035340, 124.9813019], 'NODE_4' => [10.3028159, 124.9820385],
            'NODE_5' => [10.3025949, 124.9823205], 'NODE_6' => [10.3002690, 124.9818665],
            'NODE_7' => [10.2990587, 124.9816322], 'NODE_8' => [10.2971533, 124.9814451],
            'NODE_9' => [10.2960984, 124.9801040], 'NODE_10' => [10.2932380, 124.9777614],
        ];
        foreach ($expected as $nodeId => [$latitude, $longitude]) {
            $this->assertEqualsWithDelta($latitude, $points[$nodeId]['latitude'], 0.00000001);
            $this->assertEqualsWithDelta($longitude, $points[$nodeId]['longitude'], 0.00000001);
            $this->assertSame('UNAVAILABLE', $points[$nodeId]['connection_status']);
        }
        $this->assertSame('MDRRMO office', $points['NODE_1']['location_text']);
        $this->assertSame('Cumajig', $points['NODE_5']['location_text']);
        $this->assertFalse($points->has('NODE_11'));
    }

    public function test_one_time_correction_updates_stale_overrides_and_future_admin_edits_still_win(): void
    {
        LoraNodeLocation::create(['node_id' => 'NODE_5', 'location_label' => 'Old blank override']);
        LoraNodeLocation::create(['node_id' => 'NODE_10', 'location_label' => 'Old alternative', 'latitude' => 10.2938822, 'longitude' => 124.9781292]);
        $migration = require database_path('migrations/2026_10_29_200000_apply_confirmed_node_locations.php');
        $migration->up();
        $this->assertDatabaseCount('lora_node_locations', 2);
        $nodes = app(LoraMeshNodeDirectory::class)->all()->keyBy('sender_id');
        $this->assertSame('Cumajig', $nodes['NODE_5']['location_label']);
        $this->assertEqualsWithDelta(10.3025949, $nodes['NODE_5']['latitude'], 0.00000001);
        $this->assertEqualsWithDelta(10.2932380, $nodes['NODE_10']['latitude'], 0.00000001);
        $this->assertEqualsWithDelta(124.9777614, $nodes['NODE_10']['longitude'], 0.00000001);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_admin' => true]))
            ->put('/admin/node-locations/NODE_5', ['location_label' => 'Moved by admin', 'latitude' => 10.31, 'longitude' => 124.98])
            ->assertSessionHasNoErrors();
        $migration->down();
        $point = app(LoraMeshNodeDirectory::class)->mapPoints()->firstWhere('node_id', 'NODE_5');
        $this->assertSame('Moved by admin', $point['location_text']);
        $this->assertSame(10.31, $point['latitude']);
        $this->assertDatabaseCount('incident_reports', 0);
    }
}
