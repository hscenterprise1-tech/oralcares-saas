<?php

namespace App\Support\Dashboard;

use App\BillingStatus;
use App\ClinicPermission;
use App\EmploymentStatus;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\PatientVisit;
use App\QueueItemStatus;
use App\Services\Clinical\DoctorToday;
use App\Services\ClinicContext;
use App\Services\Reception\FrontDesk;
use App\Services\ResourceLimitService;
use App\Support\ClinicAccess\BranchReach;
use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use App\Support\ClinicNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 * Each figure has one definition, shared with the list or report it sums up:
 *  - a visit counts unless it was cancelled;
 *  - "waiting" is the Front Desk's waiting list (`FrontDesk::LISTS`);
 *  - money is the Revenue report's: payments by the day they were paid
 *    (`paid_at`) less refunds recorded that day, each at its own branch;
 *  - an open bill has a balance due and was not cancelled.
 *
 * Sources marked clinic-wide (branch overview, owner figures) are reached
 * only by widgets that require clinic-wide access.
 */
class DashboardData
{
    private const LIST_LIMIT = 6;

    /** Rows the queue preview shows; the counts are always the full day's. */
    public const QUEUE_PREVIEW = 8;

    /** Upcoming appointments the preview shows; the total is always the full day's. */
    public const APPOINTMENT_PREVIEW = 4;

    /** Pending bills the preview shows; the total and the balance due are over all of today's. */
    public const DUES_PREVIEW = 5;

    private const BRANCH_LIMIT = 8;

    /** A doctor with this many waiting is flagged as overloaded. */
    private const OVERLOAD = 5;

    /** Appointments still to arrive. */
    private const TO_ARRIVE = ['scheduled', 'confirmed'];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        protected ClinicContext $context,
        protected ClinicAccess $access,
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
        return ['value' => array_sum($this->visitCounts())];
    }

    /* --------------------------------------------------------- appointments */

    /** @return array<string, mixed> */
    protected function appointmentsToday(): array
    {
        $byStatus = $this->appointmentCounts();
        $toArrive = (int) array_sum(array_intersect_key($byStatus, array_flip(self::TO_ARRIVE)));

        return [
            'value' => array_sum($byStatus),
            'detail' => $toArrive > 0 ? "{$toArrive} still to arrive" : null,
        ];
    }

    /**
     * Today's bookings still to arrive, earliest first: the ones the desk is
     * waiting for. A booking whose time has passed is marked late. The total
     * is the figure's "still to arrive".
     *
     * @return array<string, mixed>
     */
    protected function upcomingAppointments(): array
    {
        $query = DB::table('appointments')
            ->leftJoin('patients', 'patients.id', '=', 'appointments.patient_id')
            ->leftJoin('clinic_employees', 'clinic_employees.id', '=', 'appointments.practitioner_id')
            ->where('appointments.clinic_id', $this->clinic()->id)
            ->whereOnDate('appointments.scheduled_at', $this->today())
            ->whereIn('appointments.status', self::TO_ARRIVE);
        $this->applyBranchScope($query, 'appointments.branch_id');

        $now = ClinicClock::now();
        // A row opens that booking on the Calendar, for whoever may open it.
        $calendar = ClinicFrontend::available('clinic.appointments.index') && $this->access->allows(ClinicNavigation::CALENDAR);
        $total = (clone $query)->count();
        $rows = $query->orderBy('appointments.scheduled_at')->orderBy('appointments.id')->limit(self::APPOINTMENT_PREVIEW)
            ->get(['appointments.id', 'appointments.scheduled_at', 'appointments.status', 'appointments.appointment_type', 'appointments.prospect_name',
                'patients.first_name', 'patients.last_name', 'patients.name as patient_name', 'clinic_employees.name as practitioner']);

        return [
            'total' => $total,
            // How many rows the preview holds, so the page keeps the panel's size whatever is in it.
            'limit' => self::APPOINTMENT_PREVIEW,
            'items' => $rows->map(function ($row) use ($now, $calendar) {
                // Appointment times are the clinic's wall clock, as the calendar books them.
                $at = CarbonImmutable::parse($row->scheduled_at, $now->getTimezone());

                return [
                    'id' => $row->id,
                    'time' => $at->format('h:i A'),
                    'primary' => $row->first_name || $row->last_name || $row->patient_name ? $this->patientName($row) : ($row->prospect_name ?? 'New patient'),
                    'secondary' => $this->joinParts([$row->appointment_type ? $this->humanize($row->appointment_type) : null, $row->practitioner ?? 'Any doctor']),
                    'status' => $at->lt($now) ? 'late' : $row->status,
                    'href' => $calendar ? route('clinic.appointments.index', ['view' => 'day', 'date' => $at->toDateString(), 'appointment' => $row->id], false) : null,
                ];
            })->values()->all(),
        ];
    }

    /* ---------------------------------------------------------------- queue */

    /** @return array<string, mixed> */
    protected function waitingNow(): array
    {
        // Queue times are the clinic's wall clock, as the desk reads them.
        $now = ClinicClock::now();
        $waits = $this->queueItems()->whereIn('clinic_queue_items.status', $this->waitingStatuses())->whereNotNull('clinic_queue_items.checked_in_at')
            ->pluck('clinic_queue_items.checked_in_at')
            ->map(fn ($at) => max(0, (int) CarbonImmutable::parse($at, $now->getTimezone())->diffInMinutes($now)));

        return [
            'value' => (int) array_sum(array_intersect_key($this->queueCounts(), array_flip($this->waitingStatuses()))),
            'detail' => $waits->isEmpty() ? null : 'Avg. wait '.(int) round($waits->avg()).' min',
        ];
    }

    /** Patients with a doctor right now. @return array<string, mixed> */
    protected function withDoctorNow(): array
    {
        return ['value' => (int) ($this->queueCounts()[QueueItemStatus::InConsultation->value] ?? 0)];
    }

    /** Active session packages whose validity ends within a week. */
    private function expiringPackages(): Builder
    {
        $query = DB::table('patient_session_packages')->where('clinic_id', $this->clinic()->id)->where('status', 'active')
            ->whereBetween('valid_until', [$this->today()->toDateString(), $this->today()->addDays(7)->toDateString()]);
        $this->applyBranchScope($query, 'branch_id');

        return $query;
    }

    /* -------------------------------------------------------------- billing */

    /**
     * Today's net collections: payments made today less refunds recorded
     * today, the Revenue report's own definition for the same day.
     *
     * @return array<string, mixed>
     */
    protected function collectionsToday(): array
    {
        $today = $this->today();
        $collected = (float) $this->payments($today, $today)->sum('amount');
        $refunded = (float) $this->refunds($today, $today)->sum('amount');

        return [
            'value' => round($collected - $refunded, 2),
            'format' => 'currency',
            'currency' => $this->currency(),
            'detail' => $refunded > 0 ? 'Net of refunds' : null,
        ];
    }

    /**
     * What today's visits still owe: open bills (a balance due, not
     * cancelled) that belong to a visit made today at the user's branch scope,
     * either through its consultation or as its check-in charge. An older
     * visit's balance is the Billing page's, never this list's. Paid and
     * balance are the bill's own figures as `PaymentService` keeps them (a
     * refund returns money already paid; it does not reopen the balance).
     * The total is over every such bill, of which the first few are listed.
     *
     * @return array<string, mixed>
     */
    protected function pendingPayments(): array
    {
        $visits = $this->visits($this->today());
        $this->applyBranchScope($visits, 'branch_id');
        $visitIds = $visits->select('id');

        $bills = $this->openBills()->where(fn (Builder $query) => $query
            ->whereIn('patient_billings.encounter_id', DB::table('clinical_encounters')->whereIn('patient_visit_id', $visitIds)->select('id'))
            ->orWhereIn('patient_billings.id', DB::table('patient_billing_items')
                ->where('source_type', (new PatientVisit)->getMorphClass())->whereIn('source_id', $visitIds)->select('patient_billing_id')));
        $this->applyBranchScope($bills, 'patient_billings.branch_id');

        $summary = (clone $bills)->selectRaw('count(*) as bills, coalesce(sum(outstanding_balance), 0) as due')->first();
        $rows = $bills->leftJoin('patients', 'patients.id', '=', 'patient_billings.patient_id')
            ->orderByDesc('patient_billings.outstanding_balance')->orderBy('patient_billings.id')->limit(self::DUES_PREVIEW)
            ->get(['patient_billings.id', 'patient_billings.billing_number', 'patient_billings.status', 'patient_billings.final_amount',
                'patient_billings.paid_amount', 'patient_billings.outstanding_balance', 'patients.first_name', 'patients.last_name', 'patients.name as patient_name']);

        return [
            'currency' => $this->currency(),
            'total' => (int) $summary->bills,
            'limit' => self::DUES_PREVIEW,
            'due' => round((float) $summary->due, 2),
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'patient' => $this->patientName($row),
                'reference' => $row->billing_number,
                'amount' => round((float) $row->final_amount, 2),
                'paid' => round((float) $row->paid_amount, 2),
                'balance' => round((float) $row->outstanding_balance, 2),
                'status' => $row->status === BillingStatus::PartiallyPaid->value ? 'partially_paid' : 'unpaid',
            ])->values()->all(),
        ];
    }

    /**
     * Lab and imaging work expected back by today. Imaging records carry no
     * branch, so they are counted only for someone who sees the whole clinic.
     */
    private function reportsDue(): int
    {
        $due = 0;

        if (in_array('lab', $this->access->capabilities(), true)) {
            $lab = DB::table('lab_orders')->where('clinic_id', $this->clinic()->id)
                ->whereIn('status', ['accepted', 'in_production', 'quality_check'])
                ->whereNotNull('delivery_due_date')->whereDate('delivery_due_date', '<=', $this->today()->toDateString());
            $this->applyBranchScope($lab, 'branch_id');
            $due += $lab->count();
        }

        if (in_array('xray', $this->access->capabilities(), true) && $this->access->clinicWide()) {
            $due += DB::table('xray_records')->where('clinic_id', $this->clinic()->id)->where('status', 'captured')->count();
        }

        return $due;
    }

    /**
     * What is waiting on someone. Each kind of item is included only when
     * this user may see it, through the same `ClinicAccess` rules as the
     * widgets: no permission, no query, no item. An item links onward only to
     * a page that exists and that the user may open; nothing here repeats a
     * list the dashboard already shows (bookings not yet arrived are in
     * Appointments; today's dues in Today's pending payments; follow-ups in
     * Follow-ups due when the user has it). Older balances belong to Billing.
     *
     * @return array<string, mixed>
     */
    protected function needsAttention(): array
    {
        $items = [];

        if ($this->access->allows(['permissions' => ['clinical.view', 'queue.manage'], 'capabilities' => ['lab', 'xray']])) {
            $due = $this->reportsDue();
            if ($due > 0) {
                $items[] = ['id' => 'reports_due', 'primary' => 'Reports due', 'secondary' => 'Expected back from the lab or imaging', 'count' => $due, 'status' => 'overdue', 'href' => null];
            }
        }

        // Queue exceptions, for the desk and whoever runs the clinic.
        if ($this->access->allows(['permissions' => ['queue.manage'], 'workspace' => ['front_desk', 'command_center']])) {
            $frontDesk = $this->link('clinic.reception', [], ['workspace' => ['front_desk']]);
            $waiting = fn () => $this->queueItems()->whereIn('clinic_queue_items.status', $this->waitingStatuses());
            $longWait = (int) config('reception.thresholds.long_wait_minutes');
            $long = $waiting()->where('clinic_queue_items.checked_in_at', '<=', ClinicClock::now()->subMinutes($longWait)->toDateTimeString())->count();
            if ($long > 0) {
                $items[] = ['id' => 'long_waits', 'primary' => 'Long waits', 'secondary' => "Waiting over {$longWait} min", 'count' => $long, 'status' => 'overdue', 'href' => $frontDesk];
            }
            $unassigned = $waiting()->whereNull('clinic_queue_items.doctor_id')->count();
            if ($unassigned > 0) {
                $items[] = ['id' => 'unassigned', 'primary' => 'No doctor yet', 'secondary' => 'Checked in, waiting for any doctor', 'count' => $unassigned, 'status' => 'pending', 'href' => $frontDesk];
            }
            $overloaded = $waiting()->whereNotNull('clinic_queue_items.doctor_id')->groupBy('clinic_queue_items.doctor_id')
                ->havingRaw('count(*) >= ?', [self::OVERLOAD])->select('clinic_queue_items.doctor_id')->get()->count();
            if ($overloaded > 0) {
                $items[] = ['id' => 'doctor_overload', 'primary' => 'Doctors with a long queue', 'secondary' => self::OVERLOAD.' or more waiting', 'count' => $overloaded, 'status' => 'alert', 'href' => $frontDesk];
            }
        }

        if (! $this->access->allows(DashboardWidgets::FOLLOW_UPS)
            && $this->access->allows(['permissions' => ['clinical.view', 'appointment.manage'], 'workspace' => ['command_center']])) {
            $followUps = DB::table('patient_follow_ups')->where('clinic_id', $this->clinic()->id)->where('status', 'due')
                ->where('due_on', '<=', $this->today()->toDateString());
            $this->applyBranchScope($followUps, 'branch_id');
            $count = $followUps->count();
            if ($count > 0) {
                $items[] = ['id' => 'follow_ups_due', 'primary' => 'Follow-ups due', 'secondary' => 'Due today or earlier, not booked', 'count' => $count, 'status' => 'pending', 'href' => $this->link('clinic.follow-ups.index', ['tab' => 'overdue', 'mine' => '0'])];
            }
        }

        if ($this->access->hasExtension('session_packages') && $this->access->allows(['permissions' => ['clinical.view', 'billing.view'], 'workspace' => ['command_center']])) {
            $count = $this->expiringPackages()->count();
            if ($count > 0) {
                $items[] = ['id' => 'packages_expiring', 'primary' => 'Packages ending soon', 'secondary' => 'Validity ends within 7 days', 'count' => $count, 'status' => 'pending', 'href' => null];
            }
        }

        // Spending waiting on someone: every pending expense (deciding is
        // someone other than its recorder, `ClinicExpenses`), and every
        // approved one not yet paid. Counted as the Expenses list will show
        // them, so the figure and the list it opens agree.
        if ($this->access->allows(['permissions' => ['expense.approve'], 'workspace' => ['command_center']])) {
            $pending = $this->expensesInReach()->where('status', 'pending')->count();
            if ($pending > 0) {
                $items[] = ['id' => 'expenses_to_decide', 'primary' => 'Expenses to decide', 'secondary' => 'Recorded, waiting for an approver', 'count' => $pending, 'status' => 'pending', 'href' => $this->link('clinic.expenses.index', ['status' => 'pending', 'period' => 'all'])];
            }
        }
        if ($this->access->allows(['permissions' => ['expense.manage'], 'workspace' => ['command_center']])) {
            $unpaid = $this->expensesInReach()->where('status', 'approved')->count();
            if ($unpaid > 0) {
                $items[] = ['id' => 'expenses_to_pay', 'primary' => 'Expenses to pay', 'secondary' => 'Approved, not yet paid', 'count' => $unpaid, 'status' => 'pending', 'href' => $this->link('clinic.expenses.index', ['status' => 'approved', 'period' => 'all'])];
            }
        }

        // Payroll: a month opened but not finalised, and finalised pay not yet paid.
        if ($this->access->allows(['permissions' => ['payroll.manage'], 'workspace' => ['command_center']])) {
            $drafts = DB::table('clinic_payroll_periods')->where('clinic_id', $this->clinic()->id)->where('status', 'draft')->count();
            if ($drafts > 0) {
                $items[] = ['id' => 'payroll_to_finalise', 'primary' => 'Payroll to finalise', 'secondary' => 'A month is open, not yet finalised', 'count' => $drafts, 'status' => 'pending', 'href' => $this->link('clinic.payroll.index')];
            }
            $unpaidSlips = DB::table('clinic_payroll_entries')->where('clinic_id', $this->clinic()->id)->where('status', 'approved')->count();
            if ($unpaidSlips > 0) {
                $items[] = ['id' => 'payslips_to_pay', 'primary' => 'Payslips to pay', 'secondary' => 'Finalised, not yet paid', 'count' => $unpaidSlips, 'status' => 'pending', 'href' => $this->link('clinic.payroll.index')];
            }
        }

        // Patient messages that failed in the last 7 days, for whoever runs the clinic's WhatsApp.
        if ($this->access->allows(['permissions' => ['settings.manage'], 'workspace' => ['command_center']])) {
            $failed = DB::table('clinic_whatsapp_messages')->where('clinic_id', $this->clinic()->id)->where('status', 'failed')
                ->where('created_at', '>=', now()->subDays(7))->count();
            if ($failed > 0) {
                $items[] = ['id' => 'messages_failed', 'primary' => 'Messages not delivered', 'secondary' => 'WhatsApp messages that failed in the last 7 days', 'count' => $failed, 'status' => 'overdue', 'href' => $this->link('clinic.whatsapp.index', ['status' => 'failed'])];
            }
        }

        // Deletion and other governed requests wait for the owner.
        if ($this->access->isOwner()) {
            $count = DB::table('deletion_requests')->where('clinic_id', $this->clinic()->id)->whereIn('status', ['pending', 'clarification_requested'])->count();
            if ($count > 0) {
                $items[] = ['id' => 'approvals', 'primary' => 'Approvals waiting', 'secondary' => 'Requests that need your decision', 'count' => $count, 'status' => 'pending', 'href' => $this->link('clinic.approvals.index')];
            }
        }

        return ['total' => (int) array_sum(array_column($items, 'count')), 'items' => $items];
    }

    /**
     * The clinic's expenses as the Expenses list shows them to this member
     * (`BranchReach` for `expense.manage`, the list's own rule), so a count and
     * the list it opens agree: the whole clinic for the owner; otherwise the
     * branches where they hold the permission, and clinic-wide expenses only
     * for a member working across every branch.
     */
    private function expensesInReach(): Builder
    {
        $query = DB::table('clinic_expenses')->where('clinic_id', $this->clinic()->id);
        $membership = $this->context->membership();
        $user = auth()->user();
        if ($membership === null || $user === null) {
            return $query->whereRaw('1 = 0');
        }
        BranchReach::for($membership, $user, ClinicPermission::ExpenseManage)->apply($query, 'clinic_expenses.branch_id');

        return $query;
    }

    /**
     * A page an item may open: only one that exists (`ClinicFrontend`) and
     * that this user may also open; otherwise the item is not a link.
     *
     * @param  array<string, string>  $query
     * @param  array<string, mixed>  $requires
     */
    private function link(string $route, array $query = [], array $requires = []): ?string
    {
        return ClinicFrontend::available($route) && $this->access->allows(['route' => $route] + $requires)
            ? route($route, $query, false)
            : null;
    }

    /* ----------------------------------------------------- staff & branches */

    /** @return array<string, mixed> */
    protected function branchActivity(): array
    {
        $clinicId = $this->clinic()->id;
        $today = $this->today();

        $branches = DB::table('branches')->where('clinic_id', $clinicId)->where('status', 'active')
            ->orderByDesc('is_main')->orderBy('name')->limit(self::BRANCH_LIMIT)->get(['id', 'name']);
        $ids = $branches->pluck('id')->all();

        $appointments = DB::table('appointments')->where('clinic_id', $clinicId)->whereIn('branch_id', $ids)
            ->whereOnDate('scheduled_at', $today)->whereNotIn('status', ['cancelled'])
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as total')->pluck('total', 'branch_id');
        $visits = $this->visits($today)->whereIn('branch_id', $ids)
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as total')->pluck('total', 'branch_id');
        // Money only for whoever may see it: net collected, each record at its own branch.
        $collected = null;
        if ($this->access->allows(DashboardWidgets::BILLING_AUDIENCE)) {
            $paid = $this->payments($today, $today, clinicWide: true)->whereIn('branch_id', $ids)
                ->groupBy('branch_id')->selectRaw('branch_id, sum(amount) as total')->pluck('total', 'branch_id');
            $refunded = $this->refunds($today, $today, clinicWide: true)->whereIn('branch_id', $ids)
                ->groupBy('branch_id')->selectRaw('branch_id, sum(amount) as total')->pluck('total', 'branch_id');
            $collected = collect($ids)->mapWithKeys(fn (int $id) => [$id => round((float) ($paid[$id] ?? 0) - (float) ($refunded[$id] ?? 0), 2)]);
        }

        return [
            'currency' => $this->currency(),
            'rows' => $branches->map(fn ($branch) => [
                'id' => $branch->id,
                'label' => $branch->name,
                'values' => array_values(array_filter([
                    ['label' => 'Appointments', 'value' => (int) ($appointments[$branch->id] ?? 0), 'tone' => 'blue'],
                    ['label' => 'Visits', 'value' => (int) ($visits[$branch->id] ?? 0), 'tone' => 'brand'],
                    $collected === null ? null : ['label' => 'Collected', 'value' => (float) $collected[$branch->id], 'tone' => 'green', 'format' => 'currency'],
                ])),
            ])->values()->all(),
        ];
    }

    /* ---------------------------------------------------------------- owner */

    /**
     * The owner's money, clinic-wide: billed and net collected today, this
     * week and this month; what is outstanding and overdue; this month's
     * refunds and discounts.
     *
     * @return array<string, mixed>
     */
    protected function ownerFinance(): array
    {
        $clinicId = $this->clinic()->id;
        $today = $this->today();
        $monthStart = $today->startOfMonth();
        $billed = fn (CarbonImmutable $from, CarbonImmutable $to) => DB::table('patient_billings')->where('clinic_id', $clinicId)->where('status', '!=', BillingStatus::Cancelled->value)
            ->where('issued_at', '>=', $this->dayStart($from))->where('issued_at', '<', $this->dayStart($to->addDay()));
        $net = fn (CarbonImmutable $from, CarbonImmutable $to) => (float) $this->payments($from, $to, clinicWide: true)->sum('amount') - (float) $this->refunds($from, $to, clinicWide: true)->sum('amount');
        $open = $this->openBills();
        $overdueBefore = $this->dayStart($today->subDays((int) config('clinic_whatsapp.overdue_after_days', 7)));
        $money = fn (string $key, string $label, float $value, string $tone) => ['key' => $key, 'label' => $label, 'value' => round($value, 2), 'format' => 'currency', 'tone' => $tone];

        return [
            'currency' => $this->currency(),
            'stats' => [
                $money('billed_today', 'Billed today', (float) $billed($today, $today)->sum('final_amount'), 'brand'),
                $money('collected_today', 'Net collected today', $net($today, $today), 'green'),
                $money('collected_week', 'Net collected this week', $net($today->startOfWeek(), $today), 'green'),
                $money('billed_month', 'Billed this month', (float) $billed($monthStart, $today)->sum('final_amount'), 'brand'),
                $money('collected_month', 'Net collected this month', $net($monthStart, $today), 'green'),
                $money('outstanding', 'Outstanding', (float) (clone $open)->sum('outstanding_balance'), 'amber'),
                $money('overdue', 'Overdue', (float) (clone $open)->where('issued_at', '<', $overdueBefore)->sum('outstanding_balance'), 'red'),
                $money('refunds_month', 'Refunds this month', (float) $this->refunds($monthStart, $today, clinicWide: true)->sum('amount'), 'slate'),
                $money('discounts_month', 'Discounts this month', (float) $billed($monthStart, $today)->sum('discount_amount'), 'slate'),
                // Balances written off or conceded after billing (`PaymentService::recordAdjustment`).
                $money('adjustments_month', 'Adjusted this month', (float) DB::table('patient_billing_adjustments')->where('clinic_id', $clinicId)
                    ->where('created_at', '>=', $this->dayStart($monthStart))->where('created_at', '<', $this->dayStart($today->addDay()))->sum('amount'), 'slate'),
                // Spending, for whoever may see expenses: approved or paid this month, and what is approved but not yet paid.
                ...($this->access->allows(['permissions' => ['expense.manage']]) ? [
                    $money('expenses_month', 'Expenses this month', (float) DB::table('clinic_expenses')->where('clinic_id', $clinicId)
                        ->whereIn('status', ['approved', 'paid'])->whereBetween('spent_on', [$monthStart->toDateString(), $today->toDateString()])->sum('amount'), 'violet'),
                    $money('payables', 'Expenses to pay', (float) DB::table('clinic_expenses')->where('clinic_id', $clinicId)->where('status', 'approved')->sum('amount'), 'amber'),
                ] : []),
            ],
        ];
    }

    /**
     * The owner's people and plan, clinic-wide: logins held against the plan's
     * limits (as `ResourceLimitService` enforces them, nothing approximated),
     * branches against the branch limit, profiles that hold no login (payroll
     * and treating-only staff, never a seat) and who is on approved leave today.
     *
     * @return array<string, mixed>
     */
    protected function ownerPeople(): array
    {
        $clinic = $this->clinic();
        $limits = app(ResourceLimitService::class);
        $subscription = $limits->getActiveSubscription($clinic);
        $of = fn (int $used, ?int $allowed) => $allowed === null ? "{$used} in use" : "{$used} of {$allowed}";

        $doctors = $limits->getCurrentDoctorCount($clinic);
        $logins = $limits->getCurrentUserCount($clinic);
        $staffAllowed = $subscription ? $limits->getAllowedStaff($subscription) : null;
        $noLogin = DB::table('clinic_employees')->where('clinic_id', $clinic->id)->whereNull('user_id')
            ->whereNotIn('status', EmploymentStatus::accessEnding())->count();
        $today = $this->today()->toDateString();
        $onLeave = DB::table('leaves')->where('clinic_id', $clinic->id)->where('status', 'approved')
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today)->distinct()->count('employee_id');

        return [
            'rows' => array_values(array_filter([
                ['label' => 'Plan', 'value' => $subscription ? ($subscription->plan?->name ?? 'Plan on record') : 'No active plan on record'],
                ['label' => 'Doctor logins', 'value' => $of($doctors, $subscription ? $limits->getAllowedDoctors($subscription) : null)],
                $staffAllowed === null ? null : ['label' => 'Staff logins', 'value' => $of($logins - $doctors, $staffAllowed)],
                ['label' => 'All logins', 'value' => $of($logins, $subscription ? $limits->getAllowedUsers($subscription) : null)],
                ['label' => 'Branches', 'value' => $of($limits->getCurrentBranchCount($clinic), $subscription ? $limits->getAllowedBranches($subscription) : null)],
                ['label' => 'Profiles without a login', 'value' => (string) $noLogin],
                ['label' => 'On leave today', 'value' => (string) $onLeave],
            ])),
        ];
    }

    /**
     * Money, access and governance changes in the last 7 days, newest first:
     * the trail the owner reviews. Read from the security audit log, which
     * every one of these actions writes.
     *
     * @return array<string, mixed>
     */
    protected function ownerActivity(): array
    {
        $labels = [
            'billing.refunded' => 'Refund recorded',
            'billing.adjusted' => 'Balance adjusted',
            'expense.approved' => 'Expense approved',
            'expense.rejected' => 'Expense rejected',
            'expense.paid' => 'Expense paid',
            'expense.paid_sole_owner_direct' => 'Expense paid directly by the owner',
            'payroll.period_finalised' => 'Payroll finalised',
            'payroll.paid' => 'Payslip paid',
            'payroll.salary_set' => 'Salary changed',
            'access.roles_changed' => 'Staff roles changed',
            'access.branches_changed' => 'Staff branch access changed',
            'deletion.executed' => 'Record removed or voided',
            'owner_mode.action_refused' => 'Sensitive action refused',
        ];
        $query = DB::table('security_audit_logs')->where('clinic_id', $this->clinic()->id)
            ->whereIn('event', array_keys($labels))->where('occurred_at', '>=', now()->subDays(7));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('occurred_at')->orderByDesc('id')->limit(6)->get(['id', 'event', 'occurred_at', 'actor_user_id']);
        $actors = DB::table('users')->whereIn('id', $rows->pluck('actor_user_id')->filter()->unique())->pluck('name', 'id');

        return [
            'total' => $total,
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => CarbonImmutable::parse($row->occurred_at)->timezone(ClinicClock::timezone())->format('d M, h:i A'),
                'primary' => $labels[$row->event] ?? $row->event,
                'secondary' => $actors[$row->actor_user_id] ?? null,
                'status' => $row->event === 'owner_mode.action_refused' ? 'alert' : 'info',
                // The event's family (billing, expense, payroll, access, deletion, owner_mode), for its icon.
                'kind' => strtok($row->event, '.'),
            ])->values()->all(),
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
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'time' => CarbonImmutable::parse($row->occurred_at)->timezone(ClinicClock::timezone())->format('d M, h:i A'),
                'primary' => $labels[$row->event] ?? $row->event,
                'secondary' => null,
                'status' => $row->event === 'owner_mode.activated' ? 'info' : 'alert',
                // The event's family (auth, owner_mode), for its icon.
                'kind' => strtok($row->event, '.'),
            ])->values()->all(),
        ];
    }

    /* --------------------------------------------- front office (`FrontDesk`) */

    /** The desk's day at the branch being worked: the same read Reception's desk is built on. */
    private function desk(): array
    {
        return $this->memo['desk'] ??= app(FrontDesk::class)->desk($this->clinic(), $this->context->requireBranch(), auth()->user());
    }

    /**
     * Today's patients at the branch, as the Front Desk sees them: who is
     * waiting (in queue order), who is with a doctor, then who is done, at
     * most `QUEUE_PREVIEW` rows. `total` is the whole day's, so the preview
     * never passes for everyone. Each row carries only the queue actions this
     * user may take (the Queue module and `queue.manage`; every action is
     * checked again by its route).
     *
     * @return array<string, mixed>
     */
    protected function todaysPatients(): array
    {
        $desk = $this->desk();
        $rows = collect($desk['waiting'])->concat($desk['with_doctor'])->concat($desk['done'])->take(self::QUEUE_PREVIEW)->values();
        $kinds = $rows->isEmpty() ? collect() : DB::table('clinic_queue_items')
            ->join('patient_visits', 'patient_visits.id', '=', 'clinic_queue_items.patient_visit_id')
            ->whereIn('clinic_queue_items.id', $rows->pluck('id')->all())
            ->pluck('patient_visits.registration_type', 'clinic_queue_items.id');
        $canAct = $this->access->allows(['permissions' => ['queue.manage'], 'clinic_module' => 'queue', 'workspace' => ['front_desk']]);
        // "Case" where the clinic registers cases; a plain visit elsewhere.
        $cases = $this->access->hasSpecialtyFlag('case_registration');
        $kindLabel = fn (?string $kind) => match ($kind) {
            'new' => $cases ? 'New case' : 'First visit',
            'repeat' => $cases ? 'Repeat case' : 'Follow-up',
            default => null,
        };

        return [
            'counts' => [
                'waiting' => $desk['counts']['waiting'],
                'in_room' => $desk['counts']['with_doctor'],
                'completed' => $desk['counts']['done'],
            ],
            // Everyone checked in today (waiting, with a doctor or done), of whom `rows` shows the first few.
            'total' => $desk['counts']['waiting'] + $desk['counts']['with_doctor'] + $desk['counts']['done'],
            'limit' => self::QUEUE_PREVIEW,
            'rows' => $rows->map(fn (array $row) => [
                'id' => $row['id'],
                'patient' => $row['patient'] ? ['name' => $row['patient']['name'], 'age' => $row['patient']['age'], 'gender' => $row['patient']['gender']] : null,
                'kind' => $kinds[$row['id']] ?? null,
                'kind_label' => $kindLabel($kinds[$row['id']] ?? null),
                'doctor' => $row['doctor']['name'] ?? null,
                'status' => $row['status'],
                'priority' => $row['priority'],
                'wait_minutes' => $row['wait_minutes'],
                'actions' => $canAct && in_array($row['status'], $this->waitingStatuses(), true) ? [
                    ['key' => $row['status'] === QueueItemStatus::Called->value ? 'recall' : 'call', 'label' => $row['status'] === QueueItemStatus::Called->value ? 'Call again' : 'Call to the room', 'url' => $row['links']['status']],
                    ['key' => 'skip', 'label' => 'Not present', 'url' => $row['links']['status']],
                ] : [],
            ])->all(),
        ];
    }

    /** Follow-ups due today or overdue, not yet booked: the desk's own read (`FrontDesk::followUps`). @return array<string, mixed> */
    protected function followUpsDue(): array
    {
        $followUps = app(FrontDesk::class)->followUps($this->clinic(), $this->context->requireBranch());

        return [
            'total' => $followUps['count']['overdue'] + $followUps['count']['today'],
            'items' => collect($followUps['rows'])->take(self::LIST_LIMIT)->map(fn (array $row) => [
                'id' => $row['id'],
                'primary' => $row['patient']['name'] ?? 'Patient',
                'secondary' => $this->joinParts([$row['reason'], $row['practitioner']]),
                'status' => $row['days_overdue'] > 0 ? 'overdue' : 'due',
                'time' => $row['days_overdue'] > 0 ? $this->plural($row['days_overdue'], 'day').' overdue' : 'Due today',
            ])->values()->all(),
        ];
    }

    /* ------------------------------------------ the practitioner (`DoctorToday`) */

    /** The signed-in practitioner's day at the branch being worked: the same read the doctor's Today is built on. */
    private function doctorDay(): array
    {
        return $this->memo['doctor_day'] ??= app(DoctorToday::class)->build($this->clinic(), $this->context->requireBranch(), $this->practitioner());
    }

    private function practitioner(): ClinicEmployee
    {
        return $this->context->currentEmployee() ?? throw new LogicException('A practitioner widget needs the signed-in staff record.');
    }

    /** @return array<string, mixed> */
    protected function myWaiting(): array
    {
        return ['value' => count($this->doctorDay()['waiting'])];
    }

    /** @return array<string, mixed> */
    protected function mySeen(): array
    {
        return ['value' => count($this->doctorDay()['completed'])];
    }

    /** Today's consultations, with the practitioner or finished, whose note is still a draft. @return array<string, mixed> */
    protected function myNotes(): array
    {
        $day = $this->doctorDay();

        return ['value' => collect($day['in_consultation'])->concat($day['completed'])
            ->filter(fn (array $row) => ($row['encounter']['status'] ?? null) === 'draft')->count()];
    }

    /** @return array<string, mixed> */
    protected function myQueue(): array
    {
        $waiting = $this->doctorDay()['waiting'];

        return [
            'total' => count($waiting),
            'items' => collect($waiting)->take(self::LIST_LIMIT)->map(fn (array $row) => [
                'id' => $row['key'],
                'time' => $row['checked_in_at'] ? CarbonImmutable::parse($row['checked_in_at'])->format('h:i A') : null,
                'primary' => $row['patient']['name'] ?? 'Patient',
                'secondary' => $this->joinParts([$this->visitReason($row['visit'] ?? []), $row['doctor'] ?? 'Any doctor', $row['wait_minutes'] !== null ? $row['wait_minutes'].' min' : null]),
                'status' => $row['priority'] !== 'normal' ? $row['priority'] : $row['status'],
            ])->values()->all(),
        ];
    }

    /** @param  array<string, mixed>  $visit */
    private function visitReason(array $visit): ?string
    {
        return $visit['reason'] ?? $visit['type'] ?? null;
    }

    /** @param  list<string|null>  $parts */
    private function joinParts(array $parts): string
    {
        return implode(' · ', array_values(array_filter($parts, fn ($part) => $part !== null && $part !== '')));
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

    /** A day's visits in the clinic, without the cancelled ones. Not branch-scoped. */
    private function visits(CarbonImmutable $day): Builder
    {
        return DB::table('patient_visits')->where('clinic_id', $this->clinic()->id)
            ->whereOnDate('visit_date', $day)
            ->where(fn (Builder $query) => $query->whereNull('visit_status')->orWhere('visit_status', '!=', 'cancelled'));
    }

    /** @return array<string, int> */
    private function visitCounts(): array
    {
        return $this->memo['visits'] ??= (function (): array {
            $query = $this->visits($this->today());
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
                ->whereOnDate('scheduled_at', $this->today())->whereNotIn('status', ['cancelled']);
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

    /** "Waiting", as the Front Desk's waiting list holds it. @return list<string> */
    private function waitingStatuses(): array
    {
        return array_map(fn (QueueItemStatus $status) => $status->value, FrontDesk::LISTS['waiting']);
    }

    /** Today's queue items in scope, joined to their queue. */
    private function queueItems(): Builder
    {
        $query = DB::table('clinic_queue_items')
            ->join('clinic_queues', 'clinic_queues.id', '=', 'clinic_queue_items.clinic_queue_id')
            ->where('clinic_queues.clinic_id', $this->clinic()->id)
            ->whereOnDate('clinic_queues.queue_date', $this->today());
        $this->applyBranchScope($query, 'clinic_queues.branch_id');

        return $query;
    }

    /** Bills with a balance due that were not cancelled. Not branch-scoped. */
    private function openBills(): Builder
    {
        return DB::table('patient_billings')->where('patient_billings.clinic_id', $this->clinic()->id)->whereNull('patient_billings.cancelled_at')
            ->whereIn('patient_billings.status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('patient_billings.outstanding_balance', '>', 0);
    }

    /** Payments paid on the clinic days `$from`..`$to`, each at its own branch. */
    private function payments(CarbonImmutable $from, CarbonImmutable $to, bool $clinicWide = false): Builder
    {
        $query = DB::table('patient_billing_payments')->where('clinic_id', $this->clinic()->id)
            ->where('paid_at', '>=', $this->dayStart($from))->where('paid_at', '<', $this->dayStart($to->addDay()));
        if (! $clinicWide) {
            $this->applyBranchScope($query, 'branch_id');
        }

        return $query;
    }

    /** Refunds recorded on the clinic days `$from`..`$to`, each at its own branch. */
    private function refunds(CarbonImmutable $from, CarbonImmutable $to, bool $clinicWide = false): Builder
    {
        $query = DB::table('patient_billing_refunds')->where('clinic_id', $this->clinic()->id)
            ->where('created_at', '>=', $this->dayStart($from))->where('created_at', '<', $this->dayStart($to->addDay()));
        if (! $clinicWide) {
            $this->applyBranchScope($query, 'branch_id');
        }

        return $query;
    }

    /** The UTC instant a clinic day begins, for comparing with stored timestamps. */
    private function dayStart(CarbonImmutable $day): string
    {
        return CarbonImmutable::parse($day->toDateString(), ClinicClock::timezone())->startOfDay()->utc()->toDateTimeString();
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

    private function humanize(string $value): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $value));
    }

    private function plural(int $count, string $word): string
    {
        return $count.' '.($count === 1 ? $word : $word.'s');
    }
}
