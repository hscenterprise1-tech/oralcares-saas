<?php

namespace App\Support\Dashboard;

use App\Models\Clinic;
use App\Services\ClinicContext;
use App\Services\Provisioning\EntitlementResolver;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's data sources, one method per source a widget can name.
 *
 * `DashboardWidgets` calls a source only for a widget the user is eligible
 * for, so a source never runs for someone who may not see it. Every query:
 *  - is bound to the session's clinic, explicitly;
 *  - is bound to the user's branch scope: the active branch; or, with none
 *    chosen, the branches this member may open; or the whole clinic only for
 *    an owner or an all-branches member (`applyBranchScope`);
 *  - returns counts, sums or at most a handful of rows, never a full list.
 *
 * Sources marked clinic-wide (branch overview, owner figures) are reached
 * only by widgets that require clinic-wide access.
 */
class DashboardData
{
    private const LIST_LIMIT = 6;

    private const BRANCH_LIMIT = 8;

    /** Queue statuses that mean the patient is still waiting to be seen. */
    private const WAITING = ['waiting', 'calling', 'called', 'recalled', 'held'];

    /** Lab case statuses that are still in flight. */
    private const LAB_OPEN = ['requested', 'accepted', 'in_production', 'quality_check', 'ready_for_delivery', 'delivered'];

    /** X-ray statuses still needing someone's attention. */
    private const XRAY_OPEN = ['requested', 'scheduled', 'captured'];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        protected ClinicContext $context,
        protected EntitlementResolver $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function source(string $name): array
    {
        return $this->{$name}();
    }

    /* ------------------------------------------------------------- patients */

    /** @return array<string, mixed> */
    protected function patientsToday(): array
    {
        $counts = $this->visitCounts();

        return ['value' => array_sum($counts), 'detail' => $this->plural(array_sum($counts), 'visit').' registered today'];
    }

    /** @return array<string, mixed> */
    protected function newCasesToday(): array
    {
        return ['value' => $this->visitCounts()['new'] ?? 0, 'detail' => 'First visits today'];
    }

    /** @return array<string, mixed> */
    protected function repeatCasesToday(): array
    {
        return ['value' => $this->visitCounts()['repeat'] ?? 0, 'detail' => 'Returning patients today'];
    }

    /* --------------------------------------------------------- appointments */

    /** @return array<string, mixed> */
    protected function appointmentsToday(): array
    {
        $byStatus = $this->appointmentCounts();
        $total = array_sum($byStatus);
        $arrived = ($byStatus['arrived'] ?? 0) + ($byStatus['checked_in'] ?? 0) + ($byStatus['in_consultation'] ?? 0);

        return [
            'value' => $total,
            'detail' => "{$arrived} arrived · ".($byStatus['completed'] ?? 0).' completed',
        ];
    }

    /** @return array<string, mixed> */
    protected function todaysAppointments(): array
    {
        $query = DB::table('appointments')
            ->leftJoin('patients', 'patients.id', '=', 'appointments.patient_id')
            ->leftJoin('clinic_employees', 'clinic_employees.id', '=', 'appointments.practitioner_id')
            ->where('appointments.clinic_id', $this->clinic()->id)
            ->whereDate('appointments.scheduled_at', $this->today()->toDateString())
            ->whereNotIn('appointments.status', ['cancelled']);
        $this->applyBranchScope($query, 'appointments.branch_id');

        $total = (clone $query)->count();
        $rows = $query->orderBy('appointments.scheduled_at')->limit(self::LIST_LIMIT)
            ->get(['appointments.id', 'appointments.scheduled_at', 'appointments.status', 'appointments.appointment_type', 'patients.first_name', 'patients.last_name', 'patients.name as patient_name', 'clinic_employees.name as practitioner']);

        return [
            'total' => $total,
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => CarbonImmutable::parse($row->scheduled_at)->format('h:i A'),
                'primary' => $this->patientName($row),
                'secondary' => trim(($row->appointment_type ? $this->humanize($row->appointment_type).' · ' : '').($row->practitioner ?? 'Any doctor')),
                'status' => $row->status,
            ])->values()->all(),
        ];
    }

    /* ---------------------------------------------------------------- queue */

    /** @return array<string, mixed> */
    protected function waitingNow(): array
    {
        $counts = $this->queueCounts();
        $waiting = array_sum(array_intersect_key($counts, array_flip(self::WAITING)));

        return ['value' => $waiting, 'detail' => ($counts['in_consultation'] ?? 0).' in consultation'];
    }

    /** @return array<string, mixed> */
    protected function patientFlow(): array
    {
        $counts = $this->queueCounts();

        return [
            'stages' => [
                ['key' => 'waiting', 'label' => 'Waiting', 'count' => array_sum(array_intersect_key($counts, array_flip(self::WAITING))), 'tone' => 'amber'],
                ['key' => 'in_consultation', 'label' => 'In consultation', 'count' => $counts['in_consultation'] ?? 0, 'tone' => 'violet'],
                ['key' => 'completed', 'label' => 'Completed', 'count' => $counts['completed'] ?? 0, 'tone' => 'green'],
                ['key' => 'skipped', 'label' => 'Skipped / left', 'count' => ($counts['skipped'] ?? 0) + ($counts['cancelled'] ?? 0), 'tone' => 'slate'],
            ],
        ];
    }

    /* ------------------------------------------------------ the practitioner */

    /**
     * The practitioner's own day, built from the patients assigned to them
     * (`Patient::assignedTo`, the scope behind "My Patients"), as the
     * dashboard's "My day" band always has been.
     *
     * @return array<string, mixed>
     */
    protected function myDay(): array
    {
        $employee = $this->context->currentEmployee();
        $myPatients = DB::table('patients')
            ->where('clinic_id', $this->clinic()->id)
            ->where('current_practitioner_id', $employee?->id)
            ->select('id');

        $appointments = DB::table('appointments')
            ->where('clinic_id', $this->clinic()->id)
            ->whereDate('scheduled_at', $this->today()->toDateString())
            ->whereNotIn('status', ['cancelled'])
            ->whereIn('patient_id', $myPatients);
        $this->applyBranchScope($appointments, 'branch_id');

        $inQueue = $this->queueItems()
            ->join('patient_visits', 'patient_visits.id', '=', 'clinic_queue_items.patient_visit_id')
            ->whereIn('clinic_queue_items.status', [...self::WAITING, 'in_consultation'])
            ->whereIn('patient_visits.patient_id', $myPatients);

        return [
            'stats' => [
                ['key' => 'today_appointments', 'label' => 'Your patients booked today', 'value' => $appointments->count(), 'tone' => 'blue'],
                ['key' => 'patients_in_queue', 'label' => 'Your patients in the queue', 'value' => $inQueue->count(), 'tone' => 'amber'],
                ['key' => 'total_assigned', 'label' => 'Assigned to you', 'value' => (clone $myPatients)->count(), 'tone' => 'violet'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function myQueue(): array
    {
        $employee = $this->context->currentEmployee();

        $query = $this->queueItems()
            ->leftJoin('patient_visits', 'patient_visits.id', '=', 'clinic_queue_items.patient_visit_id')
            ->leftJoin('patients', 'patients.id', '=', 'patient_visits.patient_id')
            ->where('clinic_queue_items.doctor_id', $employee?->id)
            ->whereIn('clinic_queue_items.status', [...self::WAITING, 'in_consultation']);

        $total = (clone $query)->count();
        $rows = $query->orderBy('clinic_queue_items.position')->limit(self::LIST_LIMIT)
            ->get(['clinic_queue_items.id', 'clinic_queue_items.case_number', 'clinic_queue_items.position', 'clinic_queue_items.status', 'patients.first_name', 'patients.last_name', 'patients.name as patient_name']);

        return [
            'total' => $total,
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => $row->case_number ?? ('#'.$row->position),
                'primary' => $this->patientName($row),
                'secondary' => null,
                'status' => $row->status,
            ])->values()->all(),
        ];
    }

    /* -------------------------------------------------------------- billing */

    /** @return array<string, mixed> */
    protected function collectionsToday(): array
    {
        $payments = $this->paymentsBetween($this->today()->startOfDay(), $this->today()->endOfDay());

        return [
            'value' => (float) (clone $payments)->sum('patient_billing_payments.amount'),
            'format' => 'currency',
            'currency' => $this->currency(),
            'detail' => $this->plural((clone $payments)->count(), 'payment').' today',
        ];
    }

    /** @return array<string, mixed> */
    protected function paymentBreakdown(): array
    {
        $rows = $this->paymentsBetween($this->today()->startOfDay(), $this->today()->endOfDay())
            ->groupBy('patient_billing_payments.payment_method')
            ->selectRaw('patient_billing_payments.payment_method as method, SUM(patient_billing_payments.amount) as total')
            ->get();

        $tones = ['cash' => 'green', 'upi' => 'brand', 'card' => 'blue', 'bank_transfer' => 'violet', 'gateway' => 'violet', 'cheque' => 'slate'];

        return [
            'currency' => $this->currency(),
            'total' => (float) $rows->sum('total'),
            'segments' => $rows->map(fn ($row) => [
                'key' => (string) $row->method,
                'label' => $this->paymentLabel((string) $row->method),
                'value' => (float) $row->total,
                'tone' => $tones[$row->method] ?? 'slate',
            ])->sortByDesc('value')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function pendingPayments(): array
    {
        $query = DB::table('patient_billings')
            ->leftJoin('patients', 'patients.id', '=', 'patient_billings.patient_id')
            ->where('patient_billings.clinic_id', $this->clinic()->id)
            ->whereIn('patient_billings.status', ['pending', 'partially_paid'])
            ->where('patient_billings.outstanding_balance', '>', 0);
        $this->applyBranchScope($query, 'patient_billings.branch_id');

        $total = (clone $query)->count();
        $outstanding = (float) (clone $query)->sum('patient_billings.outstanding_balance');
        $rows = $query->orderByDesc('patient_billings.outstanding_balance')->limit(5)
            ->get(['patient_billings.id', 'patient_billings.billing_number', 'patient_billings.outstanding_balance', 'patients.first_name', 'patients.last_name', 'patients.name as patient_name']);

        return [
            'total' => $total,
            'summary' => ['value' => $outstanding, 'format' => 'currency', 'currency' => $this->currency(), 'label' => 'Outstanding'],
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => $row->billing_number,
                'primary' => $this->patientName($row),
                'secondary' => null,
                'amount' => (float) $row->outstanding_balance,
                'currency' => $this->currency(),
                'status' => 'pending',
            ])->values()->all(),
        ];
    }

    /* --------------------------------------------------------------- orders */

    /** @return array<string, mixed> */
    protected function pendingLabOrders(): array
    {
        $query = DB::table('lab_orders')
            ->where('clinic_id', $this->clinic()->id)
            ->whereIn('status', self::LAB_OPEN);
        $this->applyBranchScope($query, 'branch_id');

        $today = $this->today()->toDateString();
        $total = (clone $query)->count();
        $overdue = (clone $query)->whereNotNull('delivery_due_date')->whereDate('delivery_due_date', '<', $today)->count();
        $rows = $query->orderByRaw('delivery_due_date is null')->orderBy('delivery_due_date')->limit(5)
            ->get(['id', 'lab_order_number', 'laboratory_name', 'status', 'delivery_due_date']);

        return [
            'total' => $total,
            'summary' => ['value' => $overdue, 'format' => 'number', 'label' => 'Overdue', 'tone' => $overdue > 0 ? 'red' : 'green'],
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => $row->lab_order_number,
                'primary' => $row->laboratory_name ?: 'In-house lab',
                'secondary' => $row->delivery_due_date ? 'Due '.CarbonImmutable::parse($row->delivery_due_date)->format('d M') : null,
                'status' => ($row->delivery_due_date && $row->delivery_due_date < $today) ? 'overdue' : $row->status,
            ])->values()->all(),
        ];
    }

    /**
     * X-ray records carry no branch, so this source is clinic-wide and only
     * widgets requiring clinic-wide access may use it.
     *
     * @return array<string, mixed>
     */
    protected function pendingXrays(): array
    {
        $query = DB::table('xray_records')->where('clinic_id', $this->clinic()->id)->whereIn('status', self::XRAY_OPEN);

        $byStatus = (clone $query)->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status')->all();
        $rows = $query->orderByDesc('created_at')->limit(5)->get(['id', 'xray_number', 'status', 'priority', 'requested_region']);

        return [
            'total' => (int) array_sum($byStatus),
            'summary' => ['value' => (int) ($byStatus['captured'] ?? 0), 'format' => 'number', 'label' => 'Awaiting review', 'tone' => 'amber'],
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => $row->xray_number,
                'primary' => $row->requested_region ? $this->humanize($row->requested_region) : 'X-ray',
                'secondary' => $row->priority ? $this->humanize($row->priority).' priority' : null,
                'status' => $row->status,
            ])->values()->all(),
        ];
    }

    /* ----------------------------------------------------- staff & branches */

    /** @return array<string, mixed> */
    protected function staffOverview(): array
    {
        $clinicId = $this->clinic()->id;

        return [
            'stats' => [
                ['key' => 'staff', 'label' => 'Active staff', 'value' => DB::table('clinic_employees')->where('clinic_id', $clinicId)->where('status', 'active')->count(), 'tone' => 'brand'],
                ['key' => 'doctors', 'label' => 'Active doctors', 'value' => DB::table('doctor_profiles')->where('clinic_id', $clinicId)->where('is_active', true)->count(), 'tone' => 'violet'],
                ['key' => 'invitations', 'label' => 'Pending invitations', 'value' => DB::table('clinic_employee_invitations')->where('clinic_id', $clinicId)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(), 'tone' => 'amber'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function branchActivity(): array
    {
        $clinicId = $this->clinic()->id;
        $today = $this->today()->toDateString();

        $branches = DB::table('branches')->where('clinic_id', $clinicId)->where('status', 'active')
            ->orderByDesc('is_main')->orderBy('name')->limit(self::BRANCH_LIMIT)->get(['id', 'name']);
        $ids = $branches->pluck('id')->all();

        $appointments = DB::table('appointments')->where('clinic_id', $clinicId)->whereIn('branch_id', $ids)
            ->whereDate('scheduled_at', $today)->whereNotIn('status', ['cancelled'])
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as total')->pluck('total', 'branch_id');
        $visits = DB::table('patient_visits')->where('clinic_id', $clinicId)->whereIn('branch_id', $ids)
            ->whereDate('visit_date', $today)
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as total')->pluck('total', 'branch_id');

        return [
            'rows' => $branches->map(fn ($branch) => [
                'id' => $branch->id,
                'label' => $branch->name,
                'values' => [
                    ['label' => 'Appointments', 'value' => (int) ($appointments[$branch->id] ?? 0), 'tone' => 'blue'],
                    ['label' => 'Visits', 'value' => (int) ($visits[$branch->id] ?? 0), 'tone' => 'brand'],
                ],
            ])->values()->all(),
        ];
    }

    /* ---------------------------------------------------------------- owner */

    /** @return array<string, mixed> */
    protected function ownerPerformance(): array
    {
        $clinicId = $this->clinic()->id;
        $from = $this->today()->subDays(6);

        $visits = DB::table('patient_visits')->where('clinic_id', $clinicId)
            ->whereDate('visit_date', '>=', $from->toDateString())
            ->groupBy(DB::raw('DATE(visit_date)'))->selectRaw('DATE(visit_date) as day, count(*) as total')->pluck('total', 'day');
        $appointments = DB::table('appointments')->where('clinic_id', $clinicId)
            ->whereDate('scheduled_at', '>=', $from->toDateString())->whereNotIn('status', ['cancelled'])
            ->groupBy(DB::raw('DATE(scheduled_at)'))->selectRaw('DATE(scheduled_at) as day, count(*) as total')->pluck('total', 'day');

        $points = [];
        for ($day = $from; $day->lte($this->today()); $day = $day->addDay()) {
            $key = $day->toDateString();
            $points[] = ['label' => $day->format('D'), 'date' => $key, 'a' => (int) ($visits[$key] ?? 0), 'b' => (int) ($appointments[$key] ?? 0)];
        }

        return ['series' => ['a' => 'Visits', 'b' => 'Appointments'], 'points' => $points];
    }

    /** @return array<string, mixed> */
    protected function ownerRevenue(): array
    {
        $collected = (float) $this->paymentsBetween($this->today()->startOfMonth(), $this->today()->endOfDay(), clinicWide: true)->sum('patient_billing_payments.amount');
        $outstanding = (float) DB::table('patient_billings')->where('clinic_id', $this->clinic()->id)
            ->whereIn('status', ['pending', 'partially_paid'])->sum('outstanding_balance');

        return [
            'currency' => $this->currency(),
            'stats' => [
                ['key' => 'collected', 'label' => 'Collected this month', 'value' => $collected, 'format' => 'currency', 'tone' => 'green'],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => $outstanding, 'format' => 'currency', 'tone' => $outstanding > 0 ? 'amber' : 'green'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function ownerPlan(): array
    {
        $clinic = $this->clinic();
        $entitlement = $this->entitlements->forClinic($clinic);
        $window = $entitlement['window'];
        $limits = $entitlement['limits'];

        $usersUsed = DB::table('clinic_memberships')->where('clinic_id', $clinic->id)->where('status', 'active')->count();
        $branchesUsed = DB::table('branches')->where('clinic_id', $clinic->id)->where('status', 'active')->count();
        $doctorsUsed = DB::table('doctor_profiles')->where('clinic_id', $clinic->id)->where('is_active', true)->count();

        $usage = fn (int $used, ?int $limit) => $limit ? "{$used} of {$limit}" : (string) $used;

        return [
            'rows' => [
                ['label' => 'Plan', 'value' => $entitlement['plan']['name'] ?? 'No plan on record'],
                ['label' => 'Status', 'value' => $this->humanize((string) $window['state']).($window['days_remaining'] !== null ? " · {$window['days_remaining']} days left" : ''), 'tone' => in_array($window['state'], ['grace', 'lapsed'], true) ? 'red' : 'green'],
                ['label' => 'Staff logins', 'value' => $usage($usersUsed, $limits['users'])],
                ['label' => 'Doctors', 'value' => $usage($doctorsUsed, $limits['doctors'])],
                ['label' => 'Branches', 'value' => $usage($branchesUsed, $limits['branches'])],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function ownerSecurity(): array
    {
        $events = ['auth.refused', 'auth.failed', 'owner_mode.verification_failed', 'owner_mode.refused', 'owner_mode.activated'];
        $query = DB::table('security_audit_logs')->where('clinic_id', $this->clinic()->id)
            ->whereIn('event', $events)->where('occurred_at', '>=', now()->subDays(7));

        $failures = (clone $query)->where('event', '!=', 'owner_mode.activated')->count();
        $rows = $query->orderByDesc('occurred_at')->limit(5)->get(['id', 'event', 'occurred_at']);

        $labels = [
            'auth.refused' => 'Sign-in refused',
            'auth.failed' => 'Wrong password at sign-in',
            'owner_mode.verification_failed' => 'Owner Mode: wrong password',
            'owner_mode.refused' => 'Owner Mode: not the owner',
            'owner_mode.activated' => 'Owner Mode entered',
        ];

        return [
            'total' => $failures,
            'summary' => ['value' => $failures, 'format' => 'number', 'label' => 'Failed checks · 7 days', 'tone' => $failures > 0 ? 'red' : 'green'],
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => CarbonImmutable::parse($row->occurred_at)->timezone(ClinicClock::timezone())->format('d M, h:i A'),
                'primary' => $labels[$row->event] ?? $row->event,
                'secondary' => null,
                'status' => $row->event === 'owner_mode.activated' ? 'info' : 'alert',
            ])->values()->all(),
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Narrow a query to the user's branch scope: the active branch; else the
     * branches this member may open; nothing more for an owner or an
     * all-branches member with no branch chosen.
     */
    private function applyBranchScope(Builder $query, string $column): void
    {
        $branch = $this->context->branch();
        if ($branch) {
            $query->where($column, $branch->id);

            return;
        }

        $membership = $this->context->membership();
        if ($membership && ($membership->is_primary_owner || $membership->has_all_branches_access)) {
            return;
        }

        $query->whereIn($column, $membership
            ? $membership->accessibleActiveBranches()->select('branches.id')->toBase()
            : [0]);
    }

    /** @return array<string, int> */
    private function visitCounts(): array
    {
        return $this->memo['visits'] ??= (function (): array {
            $query = DB::table('patient_visits')->where('clinic_id', $this->clinic()->id)
                ->whereDate('visit_date', $this->today()->toDateString());
            $this->applyBranchScope($query, 'branch_id');

            return $query->groupBy('registration_type')->selectRaw('registration_type, count(*) as total')
                ->pluck('total', 'registration_type')->map(fn ($n) => (int) $n)->all();
        })();
    }

    /** @return array<string, int> */
    private function appointmentCounts(): array
    {
        return $this->memo['appointments'] ??= (function (): array {
            $query = DB::table('appointments')->where('clinic_id', $this->clinic()->id)
                ->whereDate('scheduled_at', $this->today()->toDateString())->whereNotIn('status', ['cancelled']);
            $this->applyBranchScope($query, 'branch_id');

            return $query->groupBy('status')->selectRaw('status, count(*) as total')
                ->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        })();
    }

    /** @return array<string, int> */
    private function queueCounts(): array
    {
        return $this->memo['queue'] ??= $this->queueItems()
            ->groupBy('clinic_queue_items.status')
            ->selectRaw('clinic_queue_items.status as status, count(*) as total')
            ->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
    }

    /** Today's queue items in scope, joined to their queue. */
    private function queueItems(): Builder
    {
        $query = DB::table('clinic_queue_items')
            ->join('clinic_queues', 'clinic_queues.id', '=', 'clinic_queue_items.clinic_queue_id')
            ->where('clinic_queues.clinic_id', $this->clinic()->id)
            ->whereDate('clinic_queues.queue_date', $this->today()->toDateString());
        $this->applyBranchScope($query, 'clinic_queues.branch_id');

        return $query;
    }

    private function paymentsBetween(CarbonImmutable $from, CarbonImmutable $to, bool $clinicWide = false): Builder
    {
        $query = DB::table('patient_billing_payments')
            ->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_payments.patient_billing_id')
            ->where('patient_billings.clinic_id', $this->clinic()->id)
            ->whereBetween('patient_billing_payments.created_at', [$this->toInstant($from), $this->toInstant($to)]);

        if (! $clinicWide) {
            $this->applyBranchScope($query, 'patient_billings.branch_id');
        }

        return $query;
    }

    /**
     * A clinic wall-clock time (as `ClinicClock` gives it) as the real UTC
     * instant, for comparing with stored timestamps such as `created_at`.
     */
    private function toInstant(CarbonImmutable $wallClock): CarbonImmutable
    {
        return CarbonImmutable::parse($wallClock->format('Y-m-d H:i:s'), ClinicClock::timezone())->utc();
    }

    private function clinic(): Clinic
    {
        return $this->context->clinic();
    }

    private function today(): CarbonImmutable
    {
        return ClinicClock::today();
    }

    private function currency(): string
    {
        return (string) config('reception.currency', 'INR');
    }

    private function patientName(object $row): string
    {
        $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));

        return $name !== '' ? $name : ($row->patient_name ?? 'Walk-in patient');
    }

    private function paymentLabel(string $method): string
    {
        return ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank_transfer' => 'Bank transfer', 'gateway' => 'Online', 'cheque' => 'Cheque'][$method] ?? $this->humanize($method);
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $value));
    }

    private function plural(int $count, string $word): string
    {
        return $count.' '.($count === 1 ? $word : $word.'s');
    }
}
