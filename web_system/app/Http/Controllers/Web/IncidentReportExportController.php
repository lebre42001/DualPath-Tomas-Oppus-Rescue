<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\IncidentReportFilterQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportExportController extends Controller
{
    private const REPORTING_TIMEZONE = 'Asia/Manila';

    public function preview(Request $request, IncidentReportFilterQuery $filters): JsonResponse
    {
        $options = $this->validatedOptions($request);
        $window = $this->resolveWindow($options);
        $count = $this->reportsQuery($request, $filters, $window)->count();

        return response()->json([
            'count' => $count,
            'period' => $options['period'],
            'from' => $window['from']->toDateString(),
            'to' => $window['to']->subDay()->toDateString(),
            'timezone' => self::REPORTING_TIMEZONE,
        ]);
    }

    public function download(Request $request, IncidentReportFilterQuery $filters): StreamedResponse
    {
        $options = $this->validatedOptions($request, true);
        $window = $this->resolveWindow($options);
        $includePersonalData = (bool) ($options['include_personal_data'] ?? false);
        $query = $this->reportsQuery($request, $filters, $window)
            ->with(['assignedResponder:id,name'])
            ->orderBy('incident_reports.created_at')
            ->orderBy('incident_reports.id');

        $columns = [
            'incident_reports.id',
            'incident_reports.report_code',
            'incident_reports.incident_type',
            'incident_reports.severity',
            'incident_reports.status',
            'incident_reports.transmission_type',
            'incident_reports.location_text',
            'incident_reports.latitude',
            'incident_reports.longitude',
            'incident_reports.description',
            'incident_reports.assigned_responder_id',
            'incident_reports.ai_source',
            'incident_reports.ai_review_required',
            'incident_reports.ai_status',
            'incident_reports.created_at',
            'incident_reports.reported_fatality_flag',
        ];
        if ($includePersonalData) {
            $columns[] = 'incident_reports.reporter_name';
            $columns[] = 'incident_reports.reporter_contact';
        }
        $reports = $query->get($columns);

        Log::info('Incident reports exported', [
            'actor_id' => $request->user()?->id,
            'actor_role' => $request->user()?->role,
            'format' => $options['format'],
            'period' => $options['period'],
            'date_from' => $window['from']->toDateString(),
            'date_to' => $window['to']->subDay()->toDateString(),
            'timezone' => self::REPORTING_TIMEZONE,
            'report_count' => $reports->count(),
            'personal_data_included' => $includePersonalData,
            'severity_filter' => $request->input('severity'),
            'status_filter' => $request->input('status'),
            'transmission_filter' => $request->input('transmission'),
            'search_filter_applied' => trim((string) $request->input('search', '')) !== '',
        ]);

        $filename = $this->filename($options['period'], $window, $options['format']);

        if ($options['format'] === 'xlsx') {
            return $this->xlsxResponse($reports, $filename, $includePersonalData);
        }

        $headings = $this->columns($includePersonalData);

        return response()->streamDownload(function () use ($reports, $headings, $includePersonalData): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headings, ',', '"', '');

            foreach ($reports as $report) {
                fputcsv($stream, array_map(fn ($value) => $this->safeCsvValue($value), $this->rowFor($report, $includePersonalData)), ',', '"', '');
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function validatedOptions(Request $request, bool $includeFormat = false): array
    {
        $rules = [
            'period' => ['required', 'in:daily,weekly,monthly,custom'],
            'date_from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:180'],
            'severity' => ['nullable', 'in:Minor,Serious,Fatal,Critical'],
            'status' => ['nullable', 'in:received,acknowledged,dispatched,responding,resolved,rejected,queued'],
            'transmission' => ['nullable', 'in:online,lora'],
            'include_personal_data' => ['sometimes', 'boolean'],
        ];

        if ($includeFormat) {
            $rules['format'] = ['required', 'in:csv,xlsx'];
        }

        $validator = Validator::make($request->query(), $rules);
        $validator->after(function ($validator) use ($request): void {
            if ($request->query('period') !== 'custom') {
                return;
            }

            $from = $request->query('date_from');
            $to = $request->query('date_to');

            if (is_string($from) && is_string($to)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
                && $from > $to) {
                $validator->errors()->add('date_to', 'The end date must be the same as or later than the start date.');
            }
        });

        return $validator->validate();
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable} The end is exclusive. */
    private function resolveWindow(array $options): array
    {
        $today = CarbonImmutable::now(self::REPORTING_TIMEZONE)->startOfDay();

        [$from, $to] = match ($options['period']) {
            'weekly' => [$today->startOfWeek(CarbonImmutable::MONDAY), $today->startOfWeek(CarbonImmutable::MONDAY)->addWeek()],
            'monthly' => [$today->startOfMonth(), $today->startOfMonth()->addMonth()],
            'custom' => [
                CarbonImmutable::createFromFormat('!Y-m-d', $options['date_from'], self::REPORTING_TIMEZONE),
                CarbonImmutable::createFromFormat('!Y-m-d', $options['date_to'], self::REPORTING_TIMEZONE)->addDay(),
            ],
            default => [$today, $today->addDay()],
        };

        return ['from' => $from, 'to' => $to];
    }

    private function reportsQuery(Request $request, IncidentReportFilterQuery $filters, array $window): Builder
    {
        return $filters->build($request, $request->user())
            ->where('incident_reports.created_at', '>=', $window['from']->utc()->format('Y-m-d H:i:s'))
            ->where('incident_reports.created_at', '<', $window['to']->utc()->format('Y-m-d H:i:s'));
    }

    private function columns(bool $includePersonalData): array
    {
        $columns = [
            'Report code',
            'Reported at (Asia/Manila)',
            'Incident type',
            'Severity',
            'Severity source',
            'Responder review required',
            'Status',
            'Location',
            'Latitude',
            'Longitude',
            'Assigned responder',
            'Transmission',
            'Description',
        ];

        if ($includePersonalData) {
            array_splice($columns, 1, 0, ['Reporter name', 'Reporter contact']);
        }

        return $columns;
    }

    private function rowFor(IncidentReport $report, bool $includePersonalData): array
    {
        $values = [
            $report->report_code,
            $report->created_at?->copy()->timezone(self::REPORTING_TIMEZONE)->format('Y-m-d H:i:s'),
            $report->incident_type,
            $report->severity_display,
            $this->severitySourceLabel($report),
            $report->operational_ai_review_required ? 'Yes' : 'No',
            $report->status,
            $report->location_text,
            $report->latitude,
            $report->longitude,
            $report->assignedResponder?->name,
            $report->transmission_type,
            $report->description,
        ];

        if ($includePersonalData) {
            array_splice($values, 1, 0, [$report->reporter_name, $report->reporter_contact]);
        }

        return array_map(fn ($value) => (string) ($value ?? ''), $values);
    }

    private function severitySourceLabel(IncidentReport $report): string
    {
        return match ($report->operational_ai_source) {
            'manual_only' => 'Manual responder assessment required',
            'text_review' => 'Text AI suggestion; responder review required',
            'text_model' => 'Text AI suggestion',
            'text_model_experimental' => 'Experimental text AI suggestion',
            'text_human_override' => 'Responder-assigned after text AI review',
            'human_responder', 'manual_override' => 'Responder-assigned',
            'image_model', 'python_model' => 'Historical model source; verify record',
            'description_fallback' => 'Historical description fallback; verify record',
            null, '' => 'Source unavailable',
            default => ucwords(str_replace('_', ' ', $report->operational_ai_source)),
        };
    }

    private function safeCsvValue(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\x00-\x20]*[=+@\-]/u', $text) === 1
            ? "'".$text
            : $text;
    }

    private function xlsxResponse($reports, string $filename, bool $includePersonalData): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Incident Reports');
        $columns = $this->columns($includePersonalData);
        $sheet->fromArray($columns, null, 'A1');
        $rowNumber = 2;

        foreach ($reports as $report) {
            foreach ($this->rowFor($report, $includePersonalData) as $column => $value) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column + 1).$rowNumber);
                $cell->setValueExplicit($value, DataType::TYPE_STRING);
            }
            $rowNumber++;
        }

        $lastColumn = $sheet->getHighestColumn();
        $lastRow = max(1, $rowNumber - 1);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('AF101A');
        $sheet->getAutoFilter()->setRange("A1:{$lastColumn}{$lastRow}");
        $sheet->freezePane('A2');
        $widths = $includePersonalData
            ? ['A' => 22, 'B' => 24, 'C' => 24, 'D' => 24, 'E' => 24, 'F' => 22, 'G' => 36, 'H' => 25, 'I' => 18, 'J' => 34, 'K' => 14, 'L' => 14, 'M' => 26, 'N' => 18, 'O' => 70]
            : ['A' => 22, 'B' => 24, 'C' => 24, 'D' => 22, 'E' => 36, 'F' => 25, 'G' => 18, 'H' => 34, 'I' => 14, 'J' => 14, 'K' => 26, 'L' => 18, 'M' => 70];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $descriptionColumn = $includePersonalData ? 'O' : 'M';
        $sheet->getStyle("{$descriptionColumn}2:{$descriptionColumn}{$lastRow}")->getAlignment()->setWrapText(true);

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function filename(string $period, array $window, string $format): string
    {
        $lastDay = $window['to']->subDay();
        $datePart = $window['from']->isSameDay($lastDay)
            ? $window['from']->format('Y-m-d')
            : $window['from']->format('Y-m-d').'_to_'.$lastDay->format('Y-m-d');

        return "incident-reports-{$period}-{$datePart}.{$format}";
    }
}
