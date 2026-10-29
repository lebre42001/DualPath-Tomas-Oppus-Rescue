<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Models\IncidentReport;
use App\Models\LoraNodeLocation;
use App\Models\User;
use App\Support\LoraMeshNodeDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoraNodeLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These editor tests start with an empty deployment; the installed
        // defaults have separate coverage in ConfirmedNodeLocationsTest.
        foreach (array_keys(config('lora_nodes')) as $nodeId) {
            config(["lora_nodes.$nodeId.latitude" => null, "lora_nodes.$nodeId.longitude" => null]);
        }
    }

    public function test_only_admins_can_view_locations_and_save_coordinates(): void
    {
        $this->get('/admin/node-locations')->assertRedirect(route('admin.login'));
        $this->get('/admin/node-locations/points')->assertRedirect(route('admin.login'));
        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]));
            $this->get('/admin/node-locations')->assertRedirect(route('dashboard'));
            $this->get('/admin/node-locations/points')->assertRedirect(route('dashboard'));
            $this->put('/admin/node-locations/NODE_2', $this->location())->assertRedirect(route('dashboard'));
        }
        $this->assertDatabaseCount('lora_node_locations', 0);
    }

    public function test_admin_saved_coordinates_are_shared_by_mesh_and_map_even_when_offline(): void
    {
        $this->signInAdmin();
        $this->get('/admin/node-locations')->assertOk()->assertSee('Save location')->assertSee('NODE_10');
        $this->put('/admin/node-locations/NODE_2', $this->location())
            ->assertRedirect(route('admin.node-locations.index').'#location-NODE_2');
        $this->assertDatabaseHas('lora_node_locations', ['node_id' => 'NODE_2', 'location_label' => 'Test installation']);
        $node = app(LoraMeshNodeDirectory::class)->all()->firstWhere('sender_id', 'NODE_2');
        $this->assertSame(10.35427, $node['latitude']);
        $this->assertSame(124.9704, $node['longitude']);
        $this->assertSame('UNAVAILABLE', $node['status']);
        $this->getJson('/admin/node-locations/points')->assertOk()
            ->assertJsonCount(1, 'points')->assertJsonPath('points.0.id', 'node-NODE_2')
            ->assertJsonPath('points.0.connection_status', 'UNAVAILABLE')
            ->assertJsonPath('points.0.location_text', 'Test installation');
        $this->get('/admin/dashboard')->assertOk()->assertSee('Node locations')
            ->assertViewHas('nodeMapPoints', fn ($points): bool => $points->first()['node_id'] === 'NODE_2');
    }

    public function test_coordinates_must_be_in_range_and_supplied_as_a_pair(): void
    {
        $this->signInAdmin();
        foreach ([
            ['latitude' => 91], ['longitude' => 181], ['latitude' => -91],
            ['longitude' => -181], ['latitude' => 'abc'], ['longitude' => 'NaN'],
            ['latitude' => null], ['longitude' => null],
        ] as $invalid) {
            $this->put('/admin/node-locations/NODE_2', array_replace($this->location(), $invalid))
                ->assertSessionHasErrors();
        }
        $this->put('/admin/node-locations/NODE_99', $this->location())->assertNotFound();
        $this->put('/admin/node-locations/S01', $this->location())->assertNotFound();
        $this->assertDatabaseCount('lora_node_locations', 0);
    }

    public function test_validation_keeps_the_selected_node_and_coordinates_for_correction(): void
    {
        $this->signInAdmin();
        $this->from(route('admin.node-locations.index'))->put('/admin/node-locations/NODE_2', [
            ...$this->location(), '_node_id' => 'NODE_2', 'latitude' => 100,
        ])->assertRedirect(route('admin.node-locations.index'))
            ->assertSessionHasErrors('latitude')->assertSessionHas('_old_input._node_id', 'NODE_2');
        $this->get('/admin/node-locations')->assertOk()->assertSee('value="100"', false);
    }

    public function test_clear_coordinates_removes_pin_and_overrides_configured_defaults(): void
    {
        $this->signInAdmin();
        config(['lora_nodes.NODE_2.latitude' => 10.1, 'lora_nodes.NODE_2.longitude' => 124.1]);
        $this->assertCount(1, app(LoraMeshNodeDirectory::class)->mapPoints());
        $this->put('/admin/node-locations/NODE_2', [
            'location_label' => 'Not installed', 'latitude' => '', 'longitude' => '',
        ])->assertSessionHasNoErrors();
        $this->assertCount(0, app(LoraMeshNodeDirectory::class)->mapPoints());
        $this->assertNull(LoraNodeLocation::query()->firstOrFail()->latitude);
        $this->getJson('/admin/node-locations/points')->assertOk()->assertJsonCount(0, 'points');
    }

    public function test_zero_coordinates_are_valid_and_repeated_saves_update_one_record(): void
    {
        $this->signInAdmin();
        $this->put('/admin/node-locations/NODE_2', $this->location())->assertSessionHasNoErrors();
        $this->put('/admin/node-locations/NODE_2', [
            'location_label' => 'Zero coordinate test', 'latitude' => 0, 'longitude' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lora_node_locations', 1);
        $point = app(LoraMeshNodeDirectory::class)->mapPoints()->first();
        $this->assertSame(0.0, $point['latitude']);
        $this->assertSame(0.0, $point['longitude']);
    }

    public function test_new_lora_reports_use_saved_location_while_old_reports_keep_their_coordinates(): void
    {
        Event::fake([IncidentFeedUpdated::class]);
        config(['services.lora_ingest.token' => 'location-test-token']);
        $this->signInAdmin();
        $this->put('/admin/node-locations/NODE_2', $this->location())->assertSessionHasNoErrors();
        $headers = ['X-STITCH-LORA-TOKEN' => 'location-test-token'];
        $payload = [
            'sender_id' => 'NODE_2', 'gateway_id' => 'NODE_1', 'sequence' => 'NODE_2-loc-1',
            'description' => 'Test location report', 'latitude' => 55, 'longitude' => 55,
        ];
        $this->postJson('/api/v1/lora/alerts', $payload, $headers)->assertCreated();
        $original = IncidentReport::query()->firstOrFail();
        $this->assertSame('10.354270', $original->latitude);
        $this->assertStringContainsString('Test installation', $original->location_text);
        $this->put('/admin/node-locations/NODE_2', [
            'location_label' => 'New installation', 'latitude' => 10.4, 'longitude' => 124.9,
        ])->assertSessionHasNoErrors();
        $this->postJson('/api/v1/lora/alerts', [...$payload, 'sequence' => 'NODE_2-loc-2'], $headers)->assertCreated();
        $this->assertSame('10.354270', $original->fresh()->latitude);
        $latest = IncidentReport::query()->latest('id')->firstOrFail();
        $this->assertSame('10.400000', $latest->latitude);
        $this->assertSame('124.900000', $latest->longitude);
        $this->assertStringContainsString('New installation', $latest->location_text);
        $response = $this->get('/admin/dashboard')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        foreach ($document->getElementsByTagName('div') as $element) {
            if (! $element->hasAttribute('data-node-points')) continue;
            $incidents = json_decode($element->getAttribute('data-points'), true, flags: JSON_THROW_ON_ERROR);
            $nodes = json_decode($element->getAttribute('data-node-points'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('nodes', $element->getAttribute('data-map-scope'));
            $this->assertCount(0, $incidents);
            $this->assertCount(1, $nodes);
            $this->assertSame('node-NODE_2', $nodes[0]['id']);
            $this->assertDatabaseCount('incident_reports', 2);
            return;
        }
        $this->fail('Command map must show installed node points.');
    }

    private function signInAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_admin' => true]));
    }

    private function location(): array
    {
        return ['location_label' => 'Test installation', 'latitude' => 10.354270, 'longitude' => 124.970400];
    }
}
