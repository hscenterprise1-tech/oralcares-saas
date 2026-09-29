<?php

namespace App\Http\Controllers\Company\Operations;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmployeeActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Company audit trail.
 *
 * Read-only, and deliberately: there is no write route, no edit and no delete
 * anywhere in this controller. History that can be corrected is not history.
 *
 * It creates no new audit store. Everything comes from the tables that already
 * record it — `activities` for the CRM and account timeline,
 * `company_employee_activities` for HQ staff events — surfaced together rather
 * than copied into a fourth place that would immediately start to disagree with
 * the three that came first.
 */
class AuditController extends Controller
{
    /** Subject types that belong to the Company side of the product. */
    protected const COMPANY_SUBJECTS = ['lead', 'organization', 'contact', 'clinic', 'App\\Models\\Lead', 'App\\Models\\Organization'];

    public function index(Request $request): Response
    {
        Gate::authorize('view-audit-logs');

        $filters = $request->only(['search', 'type', 'subject_type', 'source', 'user_id']);

        /*
         * Two sources, unioned in PHP rather than in SQL.
         *
         * They have different shapes — one is polymorphic across CRM subjects,
         * the other is per-employee — and forcing them into one query would
         * mean inventing columns for whichever is missing them.
         */
        $activities = DB::table('activities')
            ->leftJoin('users', 'users.id', '=', 'activities.user_id')
            ->select([
                'activities.id',
                'activities.subject_type',
                'activities.subject_id',
                'activities.type',
                'activities.description',
                'activities.created_at',
                'users.name as actor',
            ])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('activities.description', 'like', "%{$search}%")
                        ->orWhere('activities.type', 'like', "%{$search}%");
                });
            })
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('activities.type', $type))
            ->when($filters['subject_type'] ?? null, fn ($query, $subject) => $query->where('activities.subject_type', $subject))
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('activities.user_id', $id))
            ->orderByDesc('activities.id')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'id' => 'act-'.$row->id,
                'source' => 'activity',
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'type' => $row->type,
                'description' => $row->description,
                'actor' => $row->actor,
                'at' => $row->created_at,
            ]);

        $employeeEvents = CompanyEmployeeActivity::query()
            ->with('employee.user:id,name')
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('description', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CompanyEmployeeActivity $row) => [
                'id' => 'emp-'.$row->id,
                'source' => 'employee',
                'subject_type' => 'company_employee',
                'subject_id' => $row->company_employee_id,
                'type' => $row->event,
                'description' => $row->description,
                'actor' => $row->employee?->user?->name,
                /*
                 * A plain string, not a Carbon instance: this model sets
                 * `$timestamps = false` and casts nothing, so `created_at`
                 * arrives raw from the driver.
                 */
                'at' => $row->created_at ? (string) $row->created_at : null,
            ]);

        $entries = $activities
            ->concat($employeeEvents)
            ->when(
                $filters['source'] ?? null,
                fn ($collection, $source) => $collection->where('source', $source),
            )
            ->sortByDesc('at')
            ->take(200)
            ->values()
            ->map(fn (array $entry) => $entry + [
                'at_label' => $entry['at'] ? date('d M Y, H:i', strtotime((string) $entry['at'])) : null,
            ]);

        return Inertia::render('Company/Operations/AuditWorkspace', [
            'entries' => $entries,
            'filters' => $filters,
            'facets' => [
                'types' => DB::table('activities')->distinct()->orderBy('type')->pluck('type')->filter()->values(),
                'subjects' => DB::table('activities')->distinct()->orderBy('subject_type')->pluck('subject_type')->filter()->values(),
            ],
            'totals' => [
                'activities' => DB::table('activities')->count(),
                'employee_events' => CompanyEmployeeActivity::query()->count(),
                'showing' => $entries->count(),
            ],
        ]);
    }
}
