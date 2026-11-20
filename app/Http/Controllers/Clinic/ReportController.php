<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Jobs\ExportClinicReport;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\ClinicReportExport;
use App\Services\ClinicContext;
use App\Services\Reports\ClinicReports;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports: one page, one report at a time, filtered on the server. The
 * report list is what this user may open in this clinic (its specialties,
 * their permissions); a report outside it is a 404. Exports are queued and
 * downloaded by the person who asked.
 */
class ReportController extends Controller
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicReports $reports,
        protected SpecialtyRegistry $specialties,
    ) {}

    public function index(Request $request): Response
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $available = $this->reports->available($clinic, $request->user());
        // Neither the clinic's reports nor a practitioner's own: nothing to open here.
        abort_if($available === [], 403);
        $mine = $this->reports->scopedPractitioner($request->user());
        $key = $request->query('report');
        $key = is_string($key) && isset($available[$key]) ? $key : array_key_first($available);
        abort_if($request->filled('report') && $key !== $request->query('report'), 404);
        $filters = [...$this->reports->filters($clinic, $request->query(), $mine), 'money' => $this->reports->seesMoney($request->user(), $mine !== null)];

        return Inertia::render('ClinicPortal/Reports/Index', [
            'reports' => collect($available)->map(fn (array $r, string $k) => [
                'key' => $k, 'group' => $r['group'], 'label' => $r['label'], 'description' => $r['description'],
                // A practitioner's own reports have no practitioner to choose: it is always them.
                'filters' => $mine !== null ? array_values(array_diff($r['filters'], ['practitioner'])) : $r['filters'],
            ])->values(),
            // The current report's own filters, with the choices this clinic has.
            'facets' => $key ? collect($available[$key]['facets'] ?? [])
                ->map(fn (string $facet) => ['key' => $facet, 'label' => ClinicReports::FACETS[$facet]['label'], 'all' => ClinicReports::FACETS[$facet]['all'],
                    'options' => collect($this->reports->facetOptions($clinic, $facet))->map(fn (string $label, string $value) => ['value' => (string) $value, 'label' => $label])->values()])
                ->filter(fn (array $facet) => $facet['options']->isNotEmpty())->values() : [],
            // 'clinic' for the clinic's reports, 'mine' for a practitioner's own work.
            'scope' => $mine !== null ? 'mine' : 'clinic',
            'report' => $key,
            'filters' => $filters,
            // Built with the page, so the report shows at once (no loading state).
            'result' => $key ? fn () => $this->reports->build($clinic, $key, $filters) : null,
            'options' => [
                'branches' => Branch::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('name')->get(['id', 'name']),
                'practitioners' => ClinicEmployee::query()->where('clinic_id', $clinic->id)->whereHas('practitionerProfile')->orderBy('name')->limit(300)->get(['id', 'name']),
                'specialties' => collect($this->specialties->forClinic($clinic))->map(fn (string $s) => ['key' => $s, 'label' => $this->specialties->get($s)['label'] ?? $s])->values(),
            ],
            'exports' => ClinicReportExport::query()->where('clinic_id', $clinic->id)->where('requested_by', $request->user()->id)->orderByDesc('id')->limit(5)->get()
                ->map(fn (ClinicReportExport $e) => ['id' => $e->id, 'report' => $available[$e->report]['label'] ?? $e->report, 'status' => $e->status, 'rows' => $e->rows,
                    'at' => $e->created_at?->toIso8601String(), 'url' => $e->status === 'ready' ? route('clinic.reports.exports.download', $e->id, false) : null]),
        ]);
    }

    public function export(Request $request): RedirectResponse
    {
        $clinic = $this->clinicContext->clinic() ?? abort(403);
        $key = (string) $request->input('report');
        abort_unless(isset($this->reports->available($clinic, $request->user())[$key]), 404);

        $export = ClinicReportExport::query()->create([
            'clinic_id' => $clinic->id, 'requested_by' => $request->user()->id, 'report' => $key,
            'filters' => [
                ...$this->reports->filters($clinic, $request->all(), $mine = $this->reports->scopedPractitioner($request->user())),
                'money' => $this->reports->seesMoney($request->user(), $mine !== null),
            ], 'status' => 'queued',
        ]);
        ExportClinicReport::dispatch($export->id)->afterCommit();

        return back()->with('success', 'Export started. It appears under Exports when it is ready.');
    }

    public function download(Request $request, int $export): StreamedResponse
    {
        $record = ClinicReportExport::query()->where('clinic_id', $this->clinicContext->clinic()?->id)->where('requested_by', $request->user()->id)->findOrFail($export);
        abort_unless($record->status === 'ready' && $record->path && Storage::disk('private')->exists($record->path), 404);

        return Storage::disk('private')->download($record->path, basename($record->path));
    }
}
