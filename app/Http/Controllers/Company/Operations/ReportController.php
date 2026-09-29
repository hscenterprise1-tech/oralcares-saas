<?php

namespace App\Http\Controllers\Company\Operations;

use App\Http\Controllers\Controller;
use App\Services\Reporting\CompanyReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-wide reporting.
 *
 * Reads only. Every figure comes from the module that owns it, so a report can
 * never disagree with the page it came from, and nothing here computes an
 * accounting metric this system cannot support — there is no margin, burn or
 * runway, because there is no cost-of-revenue anywhere to derive them from.
 *
 * HQ-global by design, like Finance.
 */
class ReportController extends Controller
{
    public function __construct(protected CompanyReportService $reports) {}

    public function index(Request $request)
    {
        Gate::authorize('view-reports');

        [$from, $to] = $this->window($request);

        return inertia('Company/Operations/ReportsWorkspace', [
            'report' => $this->reports->build($from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'presets' => [
                ['key' => 'this_month', 'label' => 'This month'],
                ['key' => 'last_month', 'label' => 'Last month'],
                ['key' => 'quarter', 'label' => 'Last 90 days'],
                ['key' => 'year', 'label' => 'This year'],
            ],
        ]);
    }

    /**
     * The same report as a spreadsheet.
     *
     * Streamed rather than assembled in memory, so a wide window does not need
     * the whole report resident before the download starts.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-reports');

        [$from, $to] = $this->window($request);

        $rows = $this->reports->toRows($this->reports->build($from, $to));
        $filename = "company-report-{$from->format('Ymd')}-{$to->format('Ymd')}.csv";

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Domain', 'Metric', 'Value']);

            foreach ($rows as $row) {
                fputcsv($handle, [$row['domain'], $row['metric'], $row['value']]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The reporting window.
     *
     * A named preset, or an explicit pair. Defaults to this month rather than
     * to all time, because an unbounded default silently makes every figure a
     * lifetime total.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function window(Request $request): array
    {
        $preset = $request->string('preset')->toString();

        return match ($preset) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'quarter' => [now()->subDays(89)->startOfDay(), now()->endOfDay()],
            'year' => [now()->startOfYear(), now()->endOfDay()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [
                $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : now()->startOfMonth(),
                $request->filled('to') ? Carbon::parse($request->string('to')->toString())->endOfDay() : now()->endOfMonth(),
            ],
        };
    }
}
