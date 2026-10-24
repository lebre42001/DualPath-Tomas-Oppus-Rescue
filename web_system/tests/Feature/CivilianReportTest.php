<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CivilianReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_report_list_is_scoped_to_the_account_and_paginated(): void
    {
        $account = $this->civilian();
        $ids = [];
        for ($i = 1; $i <= 17; $i++) $ids[] = $this->report($account, 'INC-OWN-'.$i)->id;
        $this->report($this->civilian(), 'INC-OTHER-CIVILIAN');
        $this->actingAs($this->admin());
        $first = $this->getJson('/civilian-accounts/'.$account->id.'/reports')
            ->assertOk()->assertJsonCount(15, 'reports')->assertJsonPath('pagination.total', 17)
            ->assertJsonPath('pagination.lastPage', 2)->assertJsonPath('account.name', $account->name)
            ->assertJsonMissingPath('reports.0.evidence_path')->assertJsonMissingPath('reports.0.reporter_selfie_path')
            ->assertJsonMissingPath('reports.0.description')->assertJsonMissingPath('reports.0.coordinates')
            ->assertJsonPath('reports.0.detailsUrl', route('civilian-accounts.reports.details', [$account, $ids[16]]));
        $second = $this->getJson('/civilian-accounts/'.$account->id.'/reports?page=2')
            ->assertOk()->assertJsonCount(2, 'reports');
        $this->assertSame(array_reverse($ids), array_merge(array_column($first->json('reports'), 'id'), array_column($second->json('reports'), 'id')));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
    }

    public function test_print_all_includes_every_page_and_only_the_selected_accounts_reports(): void
    {
        $account = $this->civilian();
        for ($i = 1; $i <= 17; $i++) $this->report($account, 'INC-PRINT-'.$i);
        $this->report($this->civilian(), 'INC-SECRET-OTHER');
        $response = $this->actingAs($this->admin())->get('/civilian-accounts/'.$account->id.'/reports/print?page=1&include_location=1')
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 17)
            ->assertSee('INC-PRINT-1')->assertSee('INC-PRINT-17')->assertDontSee('INC-SECRET-OTHER')
            ->assertSee('Asia/Manila')->assertSee('Location')->assertSee('GPS: 10.300000, 124.900000')
            ->assertSee('Under review')->assertSee('Serious')->assertSee('Assigned responder')
            ->assertSee('Latest admin response')->assertDontSee('private-selfie.jpg')
            ->assertSee('Choose print details')->assertSee('data-auto-print="false"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('incident_reports', 18);
    }

    public function test_single_report_print_cannot_access_another_accounts_report(): void
    {
        $account = $this->civilian();
        $report = $this->report($account, 'INC-SINGLE-SELECTED');
        $this->report($account, 'INC-SINGLE-EXCLUDED');
        $other = $this->report($this->civilian(), 'INC-PRIVATE-OTHER');
        $this->actingAs($this->admin())->get('/civilian-accounts/'.$account->id.'/reports/print?report='.$report->id)
            ->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 1)
            ->assertSee('INC-SINGLE-SELECTED')->assertDontSee('INC-SINGLE-EXCLUDED')->assertDontSee('INC-PRIVATE-OTHER');
        $this->get('/civilian-accounts/'.$account->id.'/reports/print?report='.$other->id)->assertNotFound();
        $this->get('/civilian-accounts/'.$account->id.'/reports/print?report=999999')->assertNotFound();
    }

    public function test_reports_and_printing_require_admin_and_civilian_target(): void
    {
        $account = $this->civilian();
        $report = $this->report($account, 'INC-AUTH');
        $suffixes = ['/reports', '/reports/print', '/reports/'.$report->id.'/details'];
        foreach ($suffixes as $suffix) {
            $this->getJson('/civilian-accounts/'.$account->id.$suffix)->assertUnauthorized();
        }
        foreach (['civilian', 'responder'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_admin' => false]);
            foreach ($suffixes as $suffix) {
                $this->actingAs($user)->getJson('/civilian-accounts/'.$account->id.$suffix)->assertForbidden();
            }
        }
        $admin = $this->admin();
        foreach ($suffixes as $suffix) {
            $this->actingAs($admin)->getJson('/civilian-accounts/'.$admin->id.$suffix)->assertNotFound();
        }
    }

    public function test_empty_history_invalid_requests_and_html_are_handled(): void
    {
        $account = $this->civilian();
        $this->actingAs($this->admin())->getJson('/civilian-accounts/'.$account->id.'/reports')
            ->assertOk()->assertJsonPath('pagination.total', 0)->assertJsonCount(0, 'reports');
        $this->get('/civilian-accounts/'.$account->id.'/reports/print')->assertOk()->assertSee('has not submitted any reports');
        $this->getJson('/civilian-accounts/'.$account->id.'/reports?page=0')->assertUnprocessable();
        $this->getJson('/civilian-accounts/'.$account->id.'/reports/print?report=abc')->assertUnprocessable();
        $this->report($account, 'INC-ESCAPED', ['description' => '<img src=x onerror=alert(1)>']);
        $this->get('/civilian-accounts/'.$account->id.'/reports/print')->assertOk()
            ->assertSee('<img src=x onerror=alert(1)>')->assertDontSee('<img src=x onerror=alert(1)>', false);
        $this->get('/civilian-accounts')->assertOk()->assertSee('data-civilian-reports-url', false);
    }

    public function test_dates_and_unconfirmed_severity_are_presented_without_claiming_responder_confirmation(): void
    {
        $account = $this->civilian();
        $this->report($account, 'INC-REVIEW', ['severity' => null, 'ai_source' => 'manual_only', 'ai_review_required' => true]);
        $this->actingAs($this->admin())->getJson('/civilian-accounts/'.$account->id.'/reports')->assertOk()
            ->assertJsonPath('reports.0.submittedAt', 'Oct 23, 2026 05:00 PM')
            ->assertJsonPath('reports.0.severity', 'Unassessed')->assertJsonPath('reports.0.reviewRequired', true);
        $this->get('/civilian-accounts/'.$account->id.'/reports/print')->assertOk()->assertSee('responder review required');
    }

    public function test_account_report_details_share_online_review_and_enforce_report_ownership(): void
    {
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
        $account = $this->civilian();
        $report = $this->report($account, 'INC-SHARED-DETAILS', [
            'evidence_type' => 'photo', 'evidence_path' => 'private-photo.jpg',
            'ai_source' => 'text_model', 'ai_status' => 'complete', 'ai_review_required' => true,
            'ai_model_name' => 'INTERNAL-MODEL', 'ai_summary' => 'PRIVATE-DIAGNOSTIC',
            'ai_probabilities' => ['minor' => .1, 'serious' => .7, 'critical' => .15, 'needs_review' => .05],
        ]);
        $other = $this->report($this->civilian(), 'INC-FOREIGN');
        $this->actingAs($this->admin());
        $response = $this->getJson(route('civilian-accounts.reports.details', [$account, $report]))->assertOk();
        $html = $response->json('html');
        foreach (['online-report-detail', 'online-report-map-preview', 'View map', 'Attachments', 'Assessment &amp; admin actions', 'Confirm severity', 'Print report'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('INTERNAL-MODEL', $html);
        $this->assertStringNotContainsString('PRIVATE-DIAGNOSTIC', $html);
        $this->assertStringContainsString(e(route('civilian-accounts.reports.print', [$account, 'report' => $report->id])), $html);
        $online = $this->getJson(route('reports.online-details', $report))->assertOk()->json('html');
        $this->assertSame(str_replace(e(route('reports.print', $report)), e(route('civilian-accounts.reports.print', [$account, 'report' => $report->id])), $online), $html);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson(route('civilian-accounts.reports.details', [$account, $other]))->assertNotFound();
        $this->getJson(route('civilian-accounts.reports.details', [$account, 999999]))->assertNotFound();
    }

    public function test_single_and_all_prints_share_privacy_options_and_preserve_the_selected_report(): void
    {
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
        $account = $this->civilian();
        $report = $this->report($account, 'INC-PRIVATE-PRINT', ['reporter_contact' => '09990001111',
            'evidence_type' => 'photo', 'evidence_path' => 'private-photo.jpg', 'location_text' => 'GPS 10.300000, 124.900000',
            'ai_source' => 'text_model', 'ai_status' => 'complete', 'ai_review_required' => true,
            'ai_model_name' => 'PRIVATE-MODEL', 'ai_model_version' => 'PRIVATE-VERSION', 'ai_summary' => 'PRIVATE-DIAGNOSTIC',
            'ai_probabilities' => ['minor' => .1, 'serious' => .7, 'critical' => .15, 'needs_review' => .05]]);
        $video = $this->report($account, 'INC-VIDEO', ['evidence_type' => 'video', 'evidence_path' => 'private-video.mp4']);
        $this->actingAs($this->admin());
        foreach ([[], ['report' => $report->id]] as $selection) {
            $this->get(route('civilian-accounts.reports.print', [$account] + $selection))->assertOk()
                ->assertSee('Choose print details')->assertSee('Omitted from this copy.')
                ->assertDontSee('09990001111')->assertDontSee('10.300000')->assertDontSee('PRIVATE-MODEL')
                ->assertDontSee('PRIVATE-VERSION')->assertDontSee('PRIVATE-DIAGNOSTIC')
                ->assertDontSee('data-print-scene-photo', false)->assertDontSee(route('reports.selfie', $report), false);
            $selected = $this->get(route('civilian-accounts.reports.print', [$account] + $selection + ['include_photo' => 1, 'include_location' => 1, 'include_contact' => 1, 'include_selfie' => 1, 'print' => 1]))
                ->assertOk()->assertSee('09990001111')->assertSee('GPS: 10.300000, 124.900000')
                ->assertSee(route('reports.evidence', $report), false)->assertDontSee(route('reports.evidence', $video), false)
                ->assertDontSee(route('reports.selfie', $report), false)->assertSee('data-auto-print="true"', false);
            if ($selection) $selected->assertSee('name="report" value="'.$report->id.'"', false)->assertDontSee('INC-VIDEO');
            else $selected->assertSee('INC-VIDEO')->assertDontSee('name="report"', false);
        }
        $this->getJson(route('civilian-accounts.reports.print', [$account, 'include_location' => 'bad']))->assertUnprocessable();
    }

    public function test_admin_review_returns_to_the_same_report_on_the_civilian_accounts_page(): void
    {
        $account = $this->civilian();
        $report = $this->report($account, 'INC-REOPEN-REVIEW');
        $this->actingAs($this->admin());
        $url = route('civilian-accounts.index', ['page' => 2]);
        $this->from($url)->post(route('reports.severity-review', $report), [
            'severity_review_report_id' => $report->id, 'severity_review_label' => '', 'severity_review_notes' => 'Preserve my review note.',
        ])->assertRedirect($url)->assertSessionHasErrors('severity_review_label');
        $this->get($url)->assertOk()->assertSee('data-civilian-report-review-on-load', false)
            ->assertSee('Preserve my review note.')->assertViewHas('reviewReport', fn ($item) => $item->id === $report->id);
        $this->from($url)->post(route('reports.severity-review', $report), [
            'severity_review_report_id' => $report->id, 'severity_review_label' => 'critical', 'severity_review_notes' => 'Confirmed after reviewing details.',
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->get($url)->assertOk()->assertViewHas('reviewReport', fn ($item) => $item->id === $report->id && $item->severity === 'Critical');
        $html = $this->getJson(route('civilian-accounts.reports.details', [$account, $report]))->assertOk()->json('html');
        $this->assertStringContainsString('Reviewed by admin', $html);
        $this->assertStringContainsString('Critical', $html);
    }

    private function report(User $account, string $code, array $overrides = []): IncidentReport
    {
        $report = IncidentReport::query()->create(array_merge([
            'reported_by' => $account->id, 'report_code' => $code, 'reporter_name' => $account->name,
            'incident_type' => 'Road Accident', 'severity' => 'Serious', 'status' => 'acknowledged',
            'channel' => 'Internet', 'transmission_type' => 'online', 'location_text' => 'Test road',
            'latitude' => '10.300000', 'longitude' => '124.900000', 'description' => 'Incident description',
            'ai_source' => 'human_responder', 'ai_review_required' => false, 'assigned_responder_id' => $account->id,
            'response_notes' => 'Responders contacted.', 'reporter_selfie_path' => 'private-selfie.jpg',
        ], $overrides));
        $report->forceFill(['created_at' => '2026-10-23 09:00:00', 'updated_at' => '2026-10-23 09:00:00'])->save();
        return $report;
    }

    private function civilian(): User
    {
        return User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    }
}
