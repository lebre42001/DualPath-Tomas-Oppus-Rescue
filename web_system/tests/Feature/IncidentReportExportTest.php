<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class IncidentReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_export_controls_are_shown_only_to_authorized_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->actingAs($admin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Export Reports')
            ->assertSee(route('reports.export.preview'), false)
            ->assertSee('Download Excel (.xlsx)');

        $civilian = User::factory()->create(['role' => 'civilian', 'is_admin' => false]);
        $this->actingAs($civilian)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('Export Reports');

        $this->actingAs($civilian)
            ->get(route('reports.export.preview', ['period' => 'daily']))
            ->assertRedirect(route('dashboard'));
    }

    public function test_daily_preview_uses_manila_day_boundaries_and_all_matching_reports(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->createReport('INC-BEFORE-MANILA-DAY', '2026-10-06 15:59:59');
        $this->createReport('INC-START-MANILA-DAY', '2026-10-06 16:00:00');
        $this->createReport('INC-END-MANILA-DAY', '2026-10-07 15:59:59');
        $this->createReport('INC-AFTER-MANILA-DAY', '2026-10-07 16:00:00');

        $this->actingAs($admin)
            ->getJson(route('reports.export.preview', ['period' => 'daily']))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('from', '2026-10-07')
            ->assertJsonPath('to', '2026-10-07')
            ->assertJsonPath('timezone', 'Asia/Manila');
    }

    public function test_weekly_and_monthly_previews_use_manila_calendar_periods(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)
            ->getJson(route('reports.export.preview', ['period' => 'weekly']))
            ->assertOk()
            ->assertJsonPath('from', '2026-10-05')
            ->assertJsonPath('to', '2026-10-11');

        $this->actingAs($admin)
            ->getJson(route('reports.export.preview', ['period' => 'monthly']))
            ->assertOk()
            ->assertJsonPath('from', '2026-10-01')
            ->assertJsonPath('to', '2026-10-31');
    }

    public function test_preview_applies_search_severity_status_transmission_and_custom_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $match = $this->createReport('INC-FILTER-MATCH', '2026-10-07 02:00:00', [
            'incident_type' => 'Road Accident',
            'severity' => 'Serious',
            'status' => 'received',
            'transmission_type' => 'online',
            'location_text' => 'Bontoc Main Road',
        ]);
        $this->createReport('INC-FILTER-WRONG-TRANSMISSION', '2026-10-07 02:00:00', [
            'severity' => 'Serious',
            'transmission_type' => 'lora',
            'location_text' => 'Bontoc Main Road',
        ]);
        $this->createReport('INC-FILTER-WRONG-SEVERITY', '2026-10-07 02:00:00', [
            'severity' => 'Minor',
            'location_text' => 'Bontoc Main Road',
        ]);

        $this->actingAs($admin)
            ->getJson(route('reports.export.preview', [
                'period' => 'custom',
                'date_from' => '2026-10-07',
                'date_to' => '2026-10-07',
                'search' => 'Bontoc',
                'severity' => 'Serious',
                'status' => 'received',
                'transmission' => 'online',
            ]))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('from', '2026-10-07')
            ->assertJsonPath('to', '2026-10-07');

        $this->assertDatabaseHas('incident_reports', ['id' => $match->id]);
    }

    public function test_custom_date_range_rejects_invalid_or_reversed_dates(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);

        $this->actingAs($admin)
            ->getJson(route('reports.export.preview', [
                'period' => 'custom',
                'date_from' => '2026-10-08',
                'date_to' => '2026-10-07',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_csv_download_includes_all_matches_and_excludes_reporter_contact_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        for ($index = 1; $index <= 5; $index++) {
            $this->createReport('INC-CSV-'.$index, '2026-10-07 02:00:00', [
                'description' => $index === 1 ? '=HYPERLINK("https://example.invalid")' : 'Incident description '.$index,
                'reporter_name' => 'Private Reporter '.$index,
                'reporter_contact' => '0917000000'.$index,
            ]);
        }

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['period' => 'daily', 'format' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', explode("\n", trim(substr($csv, 3))));
        $this->assertCount(6, $rows); // Header plus all five results, not the current three-row page.
        $this->assertContains('Report code', $rows[0]);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Private Reporter', $csv);
        $this->assertStringNotContainsString('0917000000', $csv);
    }

    public function test_xlsx_download_is_a_valid_workbook_and_untrusted_text_is_stored_as_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->createReport('INC-XLSX-1', '2026-10-07 02:00:00', [
            'description' => '=1+1',
            'reporter_name' => 'Private Reporter',
            'reporter_contact' => '09179998888',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.export', ['period' => 'daily', 'format' => 'xlsx']))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('x-content-type-options', 'nosniff');

        $path = tempnam(sys_get_temp_dir(), 'incident-export-');
        try {
            file_put_contents($path, $response->streamedContent());
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();

            $this->assertSame('Report code', $sheet->getCell('A1')->getValue());
            $this->assertSame('INC-XLSX-1', $sheet->getCell('A2')->getValue());
            $this->assertSame('=1+1', $sheet->getCell('M2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());
            $this->assertNull($sheet->getCell('N2')->getValue());
            $this->assertSame('2026-10-07 10:00:00', $sheet->getCell('B2')->getValue());
            $spreadsheet->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_personal_data_is_exported_only_when_explicitly_requested_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
        $this->createReport('INC-PII-1', '2026-10-07 02:00:00', [
            'reporter_name' => 'Private Reporter',
            'reporter_contact' => '09179998888',
        ]);
        Log::spy();

        $response = $this->actingAs($admin)->get(route('reports.export', [
            'period' => 'daily',
            'format' => 'csv',
            'include_personal_data' => '1',
        ]))->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Reporter name', $csv);
        $this->assertStringContainsString('Private Reporter', $csv);
        $this->assertStringContainsString('09179998888', $csv);
        Log::shouldHaveReceived('info')->once()->with(
            'Incident reports exported',
            \Mockery::on(fn (array $context): bool => $context['personal_data_included'] === true
                && $context['actor_id'] === $admin->id
                && $context['report_count'] === 1)
        );
    }

    private function createReport(string $code, string $createdAt, array $overrides = []): IncidentReport
    {
        $report = IncidentReport::query()->create(array_merge([
            'report_code' => $code,
            'reporter_name' => 'Test Reporter',
            'reporter_contact' => '09170000000',
            'incident_type' => 'Road Accident',
            'severity' => 'Minor',
            'status' => 'received',
            'channel' => 'Internet',
            'transmission_type' => 'online',
            'location_text' => 'Test Road, Bontoc',
            'latitude' => '10.300000',
            'longitude' => '124.900000',
            'description' => 'One vehicle stopped near the road.',
            'ai_source' => 'human_responder',
            'ai_status' => 'complete',
            'ai_review_required' => false,
        ], $overrides));

        $report->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $report->refresh();
    }
}
