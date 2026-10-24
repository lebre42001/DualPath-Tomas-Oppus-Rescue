<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlineReportHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.ai_text.mode' => 'MODEL_ADVISORY']);
    }

    public function test_main_feed_keeps_latest_three_even_with_old_page_or_review_links(): void
    {
        $reports = collect(range(1, 6))->map(fn ($id) => $this->report('HISTORY-'.$id));
        $this->actingAs($this->admin());
        foreach ([[], ['page' => 2], ['sort' => 'oldest'], ['review' => $reports->first()->id]] as $params) {
            $response = $this->get(route('reports.index', $params))->assertOk()->assertSee('View all reports')
                ->assertDontSee('aria-label="Report feed pagination"', false);
            $this->assertSame($reports->reverse()->take(3)->pluck('id')->values()->all(), $response->viewData('reports')->pluck('id')->all());
        }
    }

    public function test_history_paginates_online_reports_and_displays_provisional_ai_scores(): void
    {
        $reports = collect(range(1, 31))->map(fn ($id) => $this->report('ALL-'.$id));
        $this->report('HIDDEN-LORA', ['transmission_type' => 'lora']);
        $this->actingAs($this->admin());
        $ids = [];
        foreach ([1 => 15, 2 => 15, 3 => 1] as $page => $size) {
            $response = $this->getJson(route('reports.all', ['page' => $page]))->assertOk()->assertJsonCount($size, 'reports')
                ->assertJsonPath('pagination.total', 31)->assertJsonMissingPath('reports.0.description')
                ->assertJsonMissingPath('reports.0.reporter_contact')->assertJsonMissingPath('reports.0.evidence_path');
            $ids = array_merge($ids, array_column($response->json('reports'), 'id'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->assertSame($reports->reverse()->pluck('id')->values()->all(), $ids);
        $ai = $this->report('AI-PENDING', ['severity' => null, 'ai_source' => 'text_review',
            'ai_probabilities' => ['minor' => .1, 'serious' => .2, 'critical' => .6, 'needs_review' => .1]]);
        $this->getJson(route('reports.all', ['search' => $ai->report_code, 'severity' => 'Critical']))->assertOk()
            ->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.severity', 'Critical')
            ->assertJsonPath('reports.0.analysis', 'Model score 60.0% · review required');
        $this->getJson(route('reports.all', ['sort' => 'oldest']))->assertJsonPath('reports.0.id', $reports->first()->id);
    }

    public function test_details_and_print_support_reports_without_a_linked_civilian_account(): void
    {
        $report = $this->report('ORPHAN-REPORT', ['reported_by' => null, 'description' => 'Full incident description.',
            'reporter_name' => 'Original civilian', 'evidence_type' => 'photo', 'evidence_path' => 'private-evidence.jpg']);
        $this->report('NOT-PRINTED');
        $this->actingAs($this->admin());
        $html = $this->getJson(route('reports.online-details', $report))->assertOk()->json('html');
        foreach (['Full incident description.', 'Original civilian', 'Severity / AI analysis', 'Print report', route('reports.print', $report), route('reports.coordination', $report), route('reports.severity-review', $report), route('reports.evidence', $report)] as $text) $this->assertStringContainsString($text, $html);
        $this->get(route('reports.print', $report))->assertOk()->assertSee('ORPHAN-REPORT')->assertSee('Original civilian')
            ->assertSee('Full incident description.')->assertDontSee('NOT-PRINTED')->assertDontSee('private-evidence.jpg')
            ->assertSee('Oct 24, 2026 05:00 PM');
    }

    public function test_admin_authorization_and_online_scope_apply_to_all_new_routes(): void
    {
        $report = $this->report('PROTECTED');
        $urls = [route('reports.all'), route('reports.online-details', $report), route('reports.print', $report)];
        foreach ($urls as $url) $this->getJson($url)->assertUnauthorized();
        foreach (['civilian', 'responder'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'is_admin' => false]));
            foreach ($urls as $url) $this->getJson($url)->assertForbidden();
        }
        $this->actingAs($this->admin());
        $lora = $this->report('LORA', ['transmission_type' => 'lora']);
        foreach (['reports.online-details', 'reports.print'] as $route) {
            $this->getJson(route($route, $lora))->assertNotFound();
            $this->getJson(route($route, 999999))->assertNotFound();
        }
        $this->getJson(route('reports.all', ['page' => 0]))->assertUnprocessable();
    }

    public function test_old_report_reopens_after_admin_review_and_response_actions(): void
    {
        $old = $this->report('OLD-REVIEW');
        $latest = collect(range(1, 3))->map(fn ($id) => $this->report('LATEST-'.$id));
        $this->actingAs($this->admin())->from(route('reports.index'))->post(route('reports.severity-review', $old), [
            'severity_review_label' => 'serious', 'severity_review_notes' => 'Reviewed the incident description.',
        ])->assertRedirect(route('reports.index'))->assertSessionHas('ai_review_report_id', $old->id);
        $response = $this->get(route('reports.index'))->assertOk()->assertSee('data-online-review-on-load', false);
        $this->assertSame($old->id, $response->viewData('reviewReport')->id);
        $this->assertSame($latest->reverse()->pluck('id')->values()->all(), $response->viewData('reports')->pluck('id')->all());
        $this->from(route('reports.index'))->post(route('reports.coordination', $old), ['status' => 'responding',
            'response_notes' => 'Response team contacted.', 'review_report_id' => $old->id])->assertRedirect()->assertSessionHas('ai_review_report_id', $old->id);
        $this->assertSame('responding', $old->fresh()->status);
        $this->get(route('reports.index'))->assertOk();
        $this->from(route('reports.index'))->post(route('reports.severity-review', $old), [
            'severity_review_report_id' => $old->id, 'severity_review_label' => '', 'severity_review_notes' => 'Keep this review note.',
        ])->assertSessionHasErrors('severity_review_label');
        $this->get(route('reports.index'))->assertOk()->assertSee('Keep this review note.')
            ->assertViewHas('reviewReport', fn ($report) => $report->id === $old->id);
    }

    public function test_search_empty_results_html_escaping_and_civilian_pagination(): void
    {
        $owner = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        for ($i = 1; $i <= 6; $i++) $this->report('MINE-'.$i, ['reported_by' => $owner->id, 'description' => '<img src=x onerror=alert(1)>']);
        $this->actingAs($owner)->get(route('reports.index', ['page' => 2]))->assertOk()
            ->assertViewHas('reports', fn ($reports) => $reports->count() === 1)->assertSee('Report feed pagination');
        $this->actingAs($this->admin())->getJson(route('reports.all', ['search' => 'NO-MATCH']))->assertOk()->assertJsonCount(0, 'reports');
        $html = $this->getJson(route('reports.online-details', IncidentReport::first()))->assertOk()->json('html');
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    private function admin(): User { return User::factory()->create(['role' => 'admin', 'is_admin' => true]); }

    private function report(string $code, array $attributes = []): IncidentReport
    {
        $report = IncidentReport::create(array_replace(['report_code' => $code, 'reporter_name' => 'Civilian Reporter',
            'incident_type' => 'Road Accident', 'description' => 'Incident description.', 'location_text' => 'National road',
            'latitude' => 10.3, 'longitude' => 124.9, 'status' => 'received', 'transmission_type' => 'online',
            'severity' => 'Minor', 'ai_source' => 'human_responder', 'ai_review_required' => false], $attributes));
        $report->forceFill(['created_at' => '2026-10-24 09:00:00'])->save();
        return $report;
    }
}
