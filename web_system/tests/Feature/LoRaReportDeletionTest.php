<?php

namespace Tests\Feature;

use App\Events\IncidentFeedUpdated;
use App\Jobs\AnalyzeLoraDescription;
use App\Models\IncidentReport;
use App\Models\IncidentReportAudit;
use App\Models\User;
use App\Support\IncidentReportIngestor;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

class LoRaReportDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'services.lora_ingest.token' => 'test-delete-token',
            'services.ai_text.mode' => 'MANUAL_ONLY',
            'services.routing.enabled' => false,
        ]);
        Event::fake([IncidentFeedUpdated::class]);
        Bus::fake([AnalyzeLoraDescription::class]);
    }

    public function test_admin_deletes_only_the_selected_lora_report_and_retains_its_audit(): void
    {
        Storage::fake();
        $report = $this->ingest();
        $other = $this->ingest('NODE_2-43');
        Storage::put('incident-evidence/deleted.jpg', 'selected evidence');
        Storage::put('incident-evidence/retained.jpg', 'other evidence');
        $report->update(['evidence_path' => 'incident-evidence/deleted.jpg']);
        $other->update(['evidence_path' => 'incident-evidence/retained.jpg']);
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('reports.coordination', $report), [
            'status' => 'acknowledged', 'response_notes' => 'Review started.',
        ])->assertSessionHasNoErrors();

        $this->delete(route('reports.destroy', $report), ['return_to' => 'lora-reports'])
            ->assertRedirect(route('lora-reports.index'))
            ->assertSessionHas('status', 'Report deleted successfully.');

        $this->assertDatabaseMissing('incident_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('incident_reports', ['id' => $other->id]);
        Storage::assertMissing('incident-evidence/deleted.jpg');
        Storage::assertExists('incident-evidence/retained.jpg');
        $audits = IncidentReportAudit::where('report_code', $report->report_code)->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame(['coordination', 'deletion'], $audits->pluck('action')->all());
        $this->assertTrue($audits->every(fn ($audit) => $audit->incident_report_id === null && $audit->actor_id === $admin->id));
        $this->assertNotNull(DB::table('lora_report_receipts')->where('ingest_key', $report->ingest_key)->value('deleted_at'));
        $this->assertNull(DB::table('lora_report_receipts')->where('ingest_key', $other->ingest_key)->value('deleted_at'));
    }

    public function test_lora_review_has_an_independent_admin_only_delete_form(): void
    {
        $report = $this->ingest();
        $response = $this->actingAs($this->admin())->get(route('lora-reports.index'))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[@data-lora-report-delete]');
        $this->assertCount(1, $forms);
        $form = $forms->item(0);
        $this->assertSame(route('reports.destroy', $report), $form->getAttribute('action'));
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(1, $xpath->query('.//input[@name="_method" and @value="DELETE"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="_token" and string-length(@value) > 0]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="return_to" and @value="lora-reports"]', $form)->length);
        $this->assertStringContainsString('confirm(', $form->getAttribute('onsubmit'));
        $this->assertSame(0, $xpath->query('ancestor::form', $form)->length);
        $html = $response->getContent();
        $coordinationStart = strpos($html, 'action="'.route('reports.coordination', $report).'"');
        $this->assertNotFalse($coordinationStart);
        $this->assertLessThan(strpos($html, 'data-lora-report-delete'), strpos($html, '</form>', $coordinationStart));

        $this->actingAs(User::factory()->create(['role' => 'responder', 'is_admin' => false]));
        $nonAdmin = view('lora-reports.partials.review', ['report' => $report, 'errors' => new ViewErrorBag])->render();
        $this->assertStringNotContainsString('data-lora-report-delete', $nonAdmin);
    }

    public function test_retransmitting_a_deleted_message_does_not_recreate_a_report_alarm_ai_job_or_review_ack(): void
    {
        $report = $this->ingest();
        $this->actingAs($this->admin())->delete(route('reports.destroy', $report), ['return_to' => 'lora-reports'])
            ->assertRedirect(route('lora-reports.index'));
        Event::fake([IncidentFeedUpdated::class]);
        Bus::fake([AnalyzeLoraDescription::class]);

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/lora/alerts', $this->payload(), $this->headers())
                ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('deleted', true)
                ->assertJsonPath('data', null)->assertDontSee('Test witness')->assertDontSee('Test motorcycle crash');
        }

        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertDatabaseCount('lora_report_receipts', 1);
        $this->assertSame(['ingest_key', 'deleted_at'], array_keys((array) DB::table('lora_report_receipts')->sole()));
        $this->getJson('/api/v1/lora/acks/pending?gatewayId=NODE_1&senderId=NODE_2&msgId=NODE_2-42', $this->headers())
            ->assertOk()->assertJsonPath('pending', false);
        Event::assertNotDispatched(IncidentFeedUpdated::class);
        Bus::assertNotDispatched(AnalyzeLoraDescription::class);

        $this->postJson('/api/v1/lora/alerts', $this->payload('NODE_2-43'), $this->headers())
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('data.lora_sequence', 'NODE_2-43');
        $this->assertDatabaseCount('incident_reports', 1);
        $this->assertDatabaseCount('lora_report_receipts', 2);
        Event::assertDispatchedTimes(IncidentFeedUpdated::class, 1);
    }

    public function test_a_new_report_alarm_is_dispatched_after_the_identity_transaction_finishes(): void
    {
        $outerLevel = DB::connection()->transactionLevel();
        $eventLevels = [];
        Event::fake([function (string $name) use (&$eventLevels): bool {
            if ($name !== IncidentFeedUpdated::class) {
                return false;
            }
            $eventLevels[] = DB::connection()->transactionLevel();

            return true;
        }]);

        $this->ingest();

        // RefreshDatabase owns the test's outer transaction. The controller's
        // identity transaction must already be closed when the alarm is sent.
        $this->assertSame([$outerLevel], $eventLevels);
    }

    public function test_guest_and_non_admin_accounts_cannot_delete_or_mark_a_lora_report(): void
    {
        $report = $this->ingest();
        $this->delete(route('reports.destroy', $report))->assertRedirect(route('login'));
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_admin' => false]);
            $this->actingAs($user)->deleteJson(route('reports.destroy', $report), ['return_to' => 'lora-reports'])
                ->assertForbidden();
        }
        $this->assertDatabaseHas('incident_reports', ['id' => $report->id]);
        $this->assertNull(DB::table('lora_report_receipts')->where('ingest_key', $report->ingest_key)->value('deleted_at'));
        $this->assertDatabaseCount('incident_report_audits', 0);
    }

    public function test_deleting_a_legacy_lora_report_without_an_ingest_key_still_blocks_its_radio_retry(): void
    {
        $report = $this->ingest();
        $key = $report->ingest_key;
        $report->update(['ingest_key' => null]);
        DB::table('lora_report_receipts')->where('ingest_key', $key)->delete();

        $this->actingAs($this->admin())->delete(route('reports.destroy', $report))->assertRedirect();
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $this->headers())
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('deleted', true);
        $this->assertDatabaseCount('incident_reports', 0);
        $this->assertNotNull(DB::table('lora_report_receipts')->where('ingest_key', $key)->value('deleted_at'));
    }

    public function test_failed_deletion_rolls_back_the_deleted_marker_and_audit_with_the_report(): void
    {
        $report = $this->ingest();
        $this->actingAs($this->admin());
        $this->withoutExceptionHandling();
        Event::listen('eloquent.deleted: '.IncidentReport::class, function (): void {
            throw new RuntimeException('Simulated deletion failure.');
        });

        try {
            $this->delete(route('reports.destroy', $report));
            $this->fail('The simulated failure should abort deletion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated deletion failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('incident_reports', ['id' => $report->id]);
        $this->assertDatabaseCount('incident_report_audits', 0);
        $this->assertNull(DB::table('lora_report_receipts')->where('ingest_key', $report->ingest_key)->value('deleted_at'));
        $this->postJson('/api/v1/lora/alerts', $this->payload(), $this->headers())
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonMissingPath('deleted')
            ->assertJsonPath('data.id', $report->id);
    }

    public function test_online_deletion_does_not_create_a_lora_receipt(): void
    {
        $admin = $this->admin();
        $report = IncidentReport::create([
            'report_code' => 'ONLINE-DELETE', 'incident_type' => 'Road Accident', 'status' => 'received',
            'channel' => 'Internet', 'transmission_type' => 'online', 'description' => 'Online incident.',
            'reporter_name' => 'Test witness', 'location_text' => 'Test road',
            'ingest_key' => app(IncidentReportIngestor::class)->onlineKey($admin, 'online-submission'),
        ]);
        $this->actingAs($admin)->delete(route('reports.destroy', $report))->assertRedirect();
        $this->assertDatabaseCount('lora_report_receipts', 0);
    }

    private function ingest(string $sequence = 'NODE_2-42'): IncidentReport
    {
        $this->postJson('/api/v1/lora/alerts', $this->payload($sequence), $this->headers())->assertCreated();

        return IncidentReport::where('lora_sequence', $sequence)->firstOrFail();
    }

    private function payload(string $sequence = 'NODE_2-42'): array
    {
        return [
            'sender_id' => 'NODE_2', 'sequence' => $sequence, 'gateway_id' => 'NODE_1',
            'reporter_name' => 'Test witness', 'incident_type' => 'Road Accident',
            'description' => 'Test motorcycle crash with injuries.', 'incident_landmark' => 'Test bridge',
            'created_at' => '1793145600', 'radio_auth' => 'hmac-sha256-v2',
        ];
    }

    private function headers(): array
    {
        return ['X-STITCH-LORA-TOKEN' => 'test-delete-token'];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }
}
