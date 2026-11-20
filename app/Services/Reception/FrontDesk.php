<?php

namespace App\Services\Reception;

use App\AppointmentStatus;
use App\BillingStatus;
use App\ClinicPermission;
use App\LabCaseStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientFollowUp;
use App\Models\User;
use App\QueueItemStatus;
use App\Services\Billing\BillInstalments;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\ClinicalContextResolver;
use App\Services\ClinicContext;
use App\Services\LaboratoryWorkflowService;
use App\Services\Patient\CaseNumberFormatter;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\PaymentService;
use App\Services\Physio\DeskPhysio;
use App\Support\ClinicClock;
use App\Support\Specialty\PracticeAreas;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Front Desk: Reception's one daily workspace for a branch — who is
 * waiting, who is with a doctor, who is done and ready to pay, what needs a
 * person, and which doctors are seeing patients.
 *
 * Bounded at any size: the day's figures are grouped counts in SQL; each list
 * is a short preview (`PREVIEW` rows, server-ordered) with "View all" going to
 * a cursor-paged list; "Needs attention" is a few rows per kind. Patients are
 * read with the columns a row shows and nothing clinical beyond the safety
 * flags any role that opens a patient may see. Nothing here is analysis: the
 * page polls this every few seconds, so it stays cheap.
 */
class FrontDesk
{
    public const PREVIEW = 10;

    /** Rows per kind in "Needs attention". */
    private const ATTENTION = 3;

    /** Statuses each list holds. */
    public const LISTS = [
        'waiting' => [QueueItemStatus::Waiting, QueueItemStatus::Called],
        'with_doctor' => [QueueItemStatus::InConsultation],
        'done' => [QueueItemStatus::Completed],
        'not_present' => [QueueItemStatus::Skipped],
    ];

    /** @var array<int, bool> whether the desk works for each practitioner, asked once per practitioner, not once per row */
    private array $deskRuns = [];

    private const PATIENT_COLUMNS = ['id', 'clinic_id', 'uhid', 'first_name', 'middle_name', 'last_name', 'name', 'gender', 'age', 'date_of_birth', 'status'];

    public function __construct(
        protected DoctorRoster $roster,
        protected PatientClinicalEntries $entries,
        protected VisitLabels $visitLabels,
        protected EncounterCheckout $checkout,
        protected ClinicContext $clinicContext,
    ) {}

    /**
     * The operational part of the page (polled).
     *
     * @return array<string, mixed>
     */
    public function desk(Clinic $clinic, Branch $branch, User $user): array
    {
        $now = ClinicClock::now();
        $queueIds = $this->queueIds($clinic, $branch, $now);
        // One grouped read for every figure: by status, and whether the visit came from a booking.
        $grouped = $queueIds === [] ? collect() : ClinicQueueItem::query()->withoutGlobalScopes()->whereIn('clinic_queue_id', $queueIds)
            ->groupBy('status', 'walk_in')->selectRaw('status, (appointment_id is null) as walk_in, count(*) as total')->toBase()->get();
        $counts = $grouped->groupBy('status')->map(fn (Collection $rows) => (int) $rows->sum('total'));
        $count = fn (array $statuses) => (int) collect($statuses)->sum(fn (QueueItemStatus $s) => $counts[$s->value] ?? 0);
        $arrived = $grouped->reject(fn (object $row) => $row->status === QueueItemStatus::Cancelled->value);

        $waiting = $this->items($queueIds, self::LISTS['waiting'])
            ->orderByRaw("case priority when 'emergency' then 0 when 'urgent' then 1 else 2 end")->orderBy('checked_in_at')->orderBy('position')->limit(self::PREVIEW)->get();
        $withDoctor = $this->items($queueIds, self::LISTS['with_doctor'])->orderBy('started_at')->limit(self::PREVIEW)->get();
        $done = $this->items($queueIds, self::LISTS['done'])->orderByDesc('completed_at')->limit(self::PREVIEW)->get();
        $attention = $this->attention($clinic, $branch, $queueIds, $now);

        $rows = $waiting->concat($withDoctor)->concat($done)->concat($attention['items']);
        $safety = $this->entries->safetyFlags($clinic->id, $rows->map(fn (ClinicQueueItem $i) => $i->patientVisit?->patient_id)->filter()->unique()->values()->all());
        $canOpenPatient = $user->hasClinicPermission(ClinicPermission::PatientView);
        $present = fn (ClinicQueueItem $item) => $this->row($clinic, $item, $now, $safety, $canOpenPatient);
        // Each finished visit's money, on its own row: shown to whoever reads the ledger or collects; collected from the row.
        $collects = $user->hasClinicPermission(ClinicPermission::PaymentCollect);
        $payments = $user->hasClinicPermission(ClinicPermission::BillingView) || $collects
            ? $this->paymentStates($clinic, $done, $collects, $collects && $user->hasClinicPermission(ClinicPermission::BillingCreate) && $this->clinicContext->currentEmployee() !== null)
            : null;
        $upcoming = $this->upcoming($clinic, $branch, $now);

        return [
            'now' => $now->format(QueueBoard::DATETIME),
            'counts' => [
                // Everyone checked in today, however their visit ends.
                'today' => (int) $arrived->sum('total'),
                'walk_ins' => (int) $arrived->where('walk_in', 1)->sum('total'),
                'waiting' => $count(self::LISTS['waiting']),
                'with_doctor' => $count(self::LISTS['with_doctor']),
                'done' => $count(self::LISTS['done']),
                'not_present' => $count(self::LISTS['not_present']),
                'appointments' => $this->appointmentsToday($clinic, $branch, $now)->count(),
                'booked_not_arrived' => $this->bookedNotArrived($clinic, $branch, $now)->count(),
            ],
            'waiting' => $waiting->map($present)->values()->all(),
            'with_doctor' => $withDoctor->map($present)->values()->all(),
            'done' => $done->map(fn (ClinicQueueItem $item) => $present($item) + [
                'payment' => $payments === null ? null : ($payments[$item->id]['state'] ?? 'not_billed'),
                'collect' => $payments[$item->id]['collect'] ?? null,
            ])->values()->all(),
            'upcoming' => $upcoming,
            'attention' => $this->presentAttention($attention, $present, $now),
            'doctors' => $this->doctors($clinic, $branch, $queueIds),
        ];
    }

    /**
     * Today's bookings still to arrive and not yet late (late ones are in
     * "Needs attention"), soonest first.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(Clinic $clinic, Branch $branch, CarbonImmutable $now): array
    {
        return $this->bookedNotArrived($clinic, $branch, $now)
            ->where('scheduled_at', '>', $now->subMinutes((int) config('reception.thresholds.late_arrival_minutes')))
            ->with(['patient' => fn ($q) => $q->withoutGlobalScopes()->select(self::PATIENT_COLUMNS), 'practitioner:id,name'])
            ->orderBy('scheduled_at')->limit(self::PREVIEW)
            ->get(['id', 'patient_id', 'practitioner_id', 'prospect_name', 'prospect_phone', 'scheduled_at', 'status', 'appointment_type'])
            ->map(fn (Appointment $a) => $this->booking($a, $now))->values()->all();
    }

    /**
     * The dental lab cases the desk has to move: made but not sent, out with the lab (late ones flagged), and arrived back
     * and waiting for the patient to be called in to be fitted. Arrived first, then late, then by due date. Null for a member
     * who does not work the queue; the step routes decide again who may send or receive.
     *
     * @return array{count: array{to_send: int, with_lab: int, late: int, arrived: int}, rows: list<array<string, mixed>>}|null
     */
    public function labCases(Clinic $clinic, Branch $branch, User $user): ?array
    {
        if (! $user->hasClinicPermission(ClinicPermission::QueueManage)) {
            return null;
        }
        $today = ClinicClock::now()->toDateString();
        $open = LabOrder::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereIn('status', [LabCaseStatus::Draft->value, LabCaseStatus::Requested->value, LabCaseStatus::Accepted->value, LabCaseStatus::InProduction->value,
                LabCaseStatus::QualityCheck->value, LabCaseStatus::ReadyForDelivery->value, LabCaseStatus::Delivered->value]);
        $orders = $open->with(['patient' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'first_name', 'middle_name', 'last_name', 'name', 'uhid'])])
            ->orderBy('delivery_due_date')->orderBy('id')->limit(100)->get();
        if ($orders->isEmpty()) {
            return null;
        }

        $rows = $orders->map(function (LabOrder $order) use ($today) {
            $status = $order->status instanceof LabCaseStatus ? $order->status : LabCaseStatus::from((string) $order->status);
            $state = match ($status) {
                LabCaseStatus::Draft => 'to_send',
                LabCaseStatus::Delivered => 'arrived',
                default => 'with_lab',
            };
            $due = $order->delivery_due_date?->toDateString();
            $steps = collect(['send', 'receive'])->filter(fn (string $step) => in_array($status->value, LaboratoryWorkflowService::STEPS[$step]['from'], true))
                ->mapWithKeys(fn (string $step) => [$step => route('clinic.patients.dental.lab.step', [$order->patient_id, $order->id, $step])])->all();

            return [
                'id' => $order->id,
                'number' => $order->lab_order_number,
                'patient' => $order->patient ? ['id' => $order->patient->id, 'name' => $order->patient->full_name, 'uhid' => $order->patient->uhid] : null,
                'what' => trim(($order->restoration ?: 'Lab work').($order->requested_teeth ? ' · '.implode(', ', (array) $order->requested_teeth) : '')),
                'lab' => $order->laboratory_name,
                'state' => $state,
                'due_on' => $due,
                'late' => $state === 'with_lab' && $due !== null && $due < $today,
                'steps' => $steps,
                'links' => ['patient' => route('clinic.patients.show', $order->patient_id)],
            ];
        });
        $rank = fn (array $row) => $row['state'] === 'arrived' ? 0 : ($row['late'] ? 1 : ($row['state'] === 'to_send' ? 2 : 3));

        return [
            'count' => [
                'to_send' => $rows->where('state', 'to_send')->count(), 'with_lab' => $rows->where('state', 'with_lab')->count(),
                'late' => $rows->where('late', true)->count(), 'arrived' => $rows->where('state', 'arrived')->count(),
            ],
            'rows' => $rows->sortBy(fn (array $row) => [$rank($row), $row['due_on'] ?? '9999-12-31'])->take(self::PREVIEW)->values()->all(),
        ];
    }

    /**
     * Follow-ups the desk should act on: due today or overdue and not yet
     * booked, the most urgent first. The caller decides who may see them.
     *
     * @return array{count: array{overdue: int, today: int}, rows: list<array<string, mixed>>, links: array{all: string}}
     */
    public function followUps(Clinic $clinic, Branch $branch): array
    {
        $today = ClinicClock::now()->toDateString();
        // Bounded by tomorrow rather than "<= today", so a date kept with a time part still counts.
        $tomorrow = ClinicClock::now()->addDay()->toDateString();
        $due = fn () => PatientFollowUp::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->where('status', PatientFollowUp::DUE)->where('due_on', '<', $tomorrow);
        $counts = $due()->selectRaw('sum(case when due_on < ? then 1 else 0 end) as overdue, sum(case when due_on >= ? then 1 else 0 end) as today', [$today, $today])->toBase()->first();
        $rows = $due()->with([
            'patient' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'first_name', 'middle_name', 'last_name', 'name', 'uhid']),
            'practitioner' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name']),
        ])->orderByDesc('priority')->orderBy('due_on')->orderBy('id')->limit(5)
            ->get(['id', 'patient_id', 'practitioner_id', 'due_on', 'reason', 'priority']);

        return [
            'count' => ['overdue' => (int) ($counts->overdue ?? 0), 'today' => (int) ($counts->today ?? 0)],
            'rows' => $rows->map(fn (PatientFollowUp $followUp) => [
                'id' => $followUp->id,
                'patient' => $followUp->patient ? ['id' => $followUp->patient->id, 'name' => $followUp->patient->full_name, 'uhid' => $followUp->patient->uhid] : null,
                'practitioner' => $followUp->practitioner?->name,
                'reason' => $followUp->reason,
                'priority' => $followUp->priorityLabel(),
                'days_overdue' => $followUp->due_on && $followUp->due_on->toDateString() < $today ? (int) $followUp->due_on->diffInDays(CarbonImmutable::parse($today)) : 0,
                'links' => ['patient' => route('clinic.patients.show', $followUp->patient_id)],
            ])->values()->all(),
            'links' => ['all' => route('clinic.follow-ups.index', ['tab' => 'today'])],
        ];
    }

    /**
     * Where each finished visit's money stands, and how the desk collects it
     * from the row: `due` (a bill with a balance: pay it), `not_billed`
     * (charges not yet billed: bill and pay in one step, at the clinic's
     * prices), `paid`, or `none` (nothing charged, e.g. a session paid from
     * a package). `$collect` and `$bill` are the member's rights to take a
     * payment and to raise the bill; without them a row shows its state only.
     *
     * @param  Collection<int, ClinicQueueItem>  $items
     * @return array<int, array{state: string, collect: array{amount: float, url: string, bills: bool, encounter_id: int, bill_id: ?int}|null}>
     */
    private function paymentStates(Clinic $clinic, Collection $items, bool $collect = false, bool $bill = false): array
    {
        $encounters = $items->pluck('encounter_id', 'id')->filter();
        if ($encounters->isEmpty()) {
            return [];
        }
        $bills = PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereIn('encounter_id', $encounters->values()->all())
            ->whereNotIn('status', [BillingStatus::Cancelled->value, BillingStatus::Draft->value])
            ->get(['id', 'encounter_id', 'outstanding_balance'])->groupBy('encounter_id');
        $models = ClinicalEncounter::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereIn('id', $encounters->values()->all())->get()->keyBy('id');

        return $encounters->map(function (int $encounterId) use ($bills, $models, $collect, $bill) {
            $billed = $bills[$encounterId] ?? collect();
            $due = $billed->first(fn (PatientBilling $b) => (float) $b->outstanding_balance > 0);
            if ($due) {
                return ['state' => 'due', 'collect' => $collect ? [
                    'amount' => (float) $due->outstanding_balance, 'url' => route('clinic.billing.payments.store', $due->id), 'bills' => false, 'encounter_id' => $encounterId, 'bill_id' => $due->id,
                ] : null];
            }
            // What the visit still has to bill: the checkout's own lines, at the clinic's prices.
            $encounter = $models[$encounterId] ?? null;
            $toBill = $encounter && $encounter->isFinalized()
                ? round(collect($this->checkout->lines($encounter))->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2)
                : 0.0;
            if ($toBill > 0) {
                return ['state' => 'not_billed', 'collect' => $bill ? [
                    'amount' => $toBill, 'url' => route('clinic.reception.billing.collect', $encounterId), 'bills' => true, 'encounter_id' => $encounterId, 'bill_id' => null,
                ] : null];
            }

            return ['state' => $billed->isNotEmpty() ? 'paid' : 'none', 'collect' => null];
        })->all();
    }

    /**
     * How many of today's patients are at each stage (one grouped count).
     *
     * @return array<string, int>
     */
    public function stageCounts(Clinic $clinic, Branch $branch): array
    {
        $queueIds = $this->queueIds($clinic, $branch, ClinicClock::now());
        $counts = $queueIds === [] ? collect() : ClinicQueueItem::query()->withoutGlobalScopes()->whereIn('clinic_queue_id', $queueIds)
            ->groupBy('status')->selectRaw('status, count(*) as total')->toBase()->pluck('total', 'status');

        return collect(self::LISTS)->map(fn (array $statuses) => (int) collect($statuses)->sum(fn (QueueItemStatus $s) => $counts[$s->value] ?? 0))->all();
    }

    /**
     * One list in full: cursor pages, today, this branch.
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function page(Clinic $clinic, Branch $branch, User $user, string $list, ?string $cursor, int $perPage = 25): array
    {
        $now = ClinicClock::now();
        $query = $this->items($this->queueIds($clinic, $branch, $now), self::LISTS[$list]);
        $query = match ($list) {
            'waiting' => $query->orderBy('checked_in_at')->orderBy('id'),
            'with_doctor' => $query->orderBy('started_at')->orderBy('id'),
            default => $query->orderByDesc('completed_at')->orderByDesc('id'),
        };
        $page = $query->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
        $items = collect($page->items());
        $safety = $this->entries->safetyFlags($clinic->id, $items->map(fn (ClinicQueueItem $i) => $i->patientVisit?->patient_id)->filter()->unique()->values()->all());
        $canOpenPatient = $user->hasClinicPermission(ClinicPermission::PatientView);

        return [
            'data' => $items->map(fn (ClinicQueueItem $item) => $this->row($clinic, $item, $now, $safety, $canOpenPatient))->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /**
     * Today's money at the desk: consultations ready to bill and bills with a
     * balance, a few of each, and what was collected today. Nothing for a
     * member without billing rights.
     *
     * @return array<string, mixed>|null
     */
    public function billing(Clinic $clinic, Branch $branch, User $user): ?array
    {
        // Whoever collects payments sees what is waiting to be paid (owner's decision, 2026-11-05: the desk takes the
        // payment when the treatment ends); the day's takings and the ledger stay with `billing.view`.
        $ledger = $user->hasClinicPermission(ClinicPermission::BillingView);
        if (! $ledger && ! $user->hasClinicPermission(ClinicPermission::PaymentCollect)) {
            return null;
        }
        $ready = $this->checkout->readyToBill($clinic, $branch, 2, self::PREVIEW + 1);
        $open = PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0);
        $unpaid = (clone $open)->with(['patient' => fn ($q) => $q->withoutGlobalScopes()->select(self::PATIENT_COLUMNS)])
            ->latest('issued_at')->limit(self::PREVIEW)->get(['id', 'patient_id', 'encounter_id', 'billing_number', 'final_amount', 'outstanding_balance', 'issued_at']);
        $start = CarbonImmutable::now(ClinicClock::timezone())->startOfDay()->utc();
        $collected = ! $ledger ? null : DB::table('patient_billing_payments')->join('patient_billings', 'patient_billings.id', '=', 'patient_billing_payments.patient_billing_id')
            ->where('patient_billings.clinic_id', $clinic->id)->where('patient_billings.branch_id', $branch->id)
            ->where('patient_billing_payments.created_at', '>=', $start)
            ->selectRaw('count(*) as payments, coalesce(sum(patient_billing_payments.amount), 0) as total')->first();

        return [
            'currency' => (string) config('reception.currency', 'INR'),
            'can' => [
                'collect' => $user->hasClinicPermission(ClinicPermission::PaymentCollect),
                // Raising a bill is done by a member of staff (it records who billed).
                'bill' => $user->hasClinicPermission(ClinicPermission::BillingCreate) && $user->hasClinicPermission(ClinicPermission::PaymentCollect)
                    && $this->clinicContext->currentEmployee() !== null,
            ],
            'methods' => app(PaymentService::class)->methodOptions($clinic, atDesk: true),
            'ready' => $ready->take(self::PREVIEW)->map(function (ClinicalEncounter $encounter) {
                $lines = $this->checkout->lines($encounter);

                return [
                    'encounter_id' => $encounter->id,
                    'number' => $encounter->encounter_number,
                    'patient' => $encounter->patient ? ['id' => $encounter->patient->id, 'name' => $encounter->patient->full_name, 'uhid' => $encounter->patient->uhid] : null,
                    'doctor' => $encounter->doctor?->name,
                    'lines' => collect($lines)->map(fn (array $line) => ['description' => $line['description'], 'quantity' => $line['quantity'], 'amount' => round($line['quantity'] * $line['unit_price'], 2)])->values()->all(),
                    'total' => round(collect($lines)->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2),
                    'links' => [
                        'collect' => route('clinic.reception.billing.collect', $encounter),
                        'checkout' => route('clinic.billing.checkout', $encounter),
                    ],
                ];
            })->values()->all(),
            'ready_more' => $ready->count() > self::PREVIEW,
            // A bill with a payment plan shows its next instalment; late ones come first.
            'unpaid' => (function () use ($unpaid) {
                $plans = app(BillInstalments::class)->nextFor($unpaid->pluck('id')->all());

                return $unpaid->map(fn (PatientBilling $bill) => [
                    'id' => $bill->id,
                    'number' => $bill->billing_number,
                    'patient' => $bill->patient ? ['id' => $bill->patient->id, 'name' => $bill->patient->full_name, 'uhid' => $bill->patient->uhid] : null,
                    'total' => (float) $bill->final_amount,
                    'outstanding' => (float) $bill->outstanding_balance,
                    'plan' => $plans[$bill->id] ?? null,
                    'links' => ['pay' => route('clinic.billing.payments.store', $bill)],
                ])->sortBy(fn (array $row) => [($row['plan']['overdue'] ?? false) ? 0 : 1])->values()->all();
            })(),
            'unpaid_count' => (clone $open)->count(),
            'collected_today' => $ledger ? ['total' => (float) ($collected->total ?? 0), 'payments' => (int) ($collected->payments ?? 0)] : null,
            'links' => ['all' => $ledger ? route('clinic.billing.index') : null, 'closing' => $user->hasClinicPermission(ClinicPermission::PaymentCollect) ? route('clinic.reception.day-closing') : null],
        ];
    }

    /**
     * What is waiting to be paid for a finished visit and its patient, for the
     * desk to collect from the visit page itself (no checkout page between):
     * the visit's charges not yet billed (billed and paid in one step, at the
     * clinic's prices) and every bill of the patient at this branch that still
     * has a balance. Null for a member who may not take payments.
     *
     * @return array{currency: string, methods: list<array{value: string, label: string}>, items: list<array{key: string, label: string, detail: ?string, amount: float, url: string, bills: bool}>}|null
     */
    public function visitPayment(ClinicalEncounter $encounter, User $user): ?array
    {
        if (! $user->hasClinicPermission(ClinicPermission::PaymentCollect)) {
            return null;
        }
        $clinic = Clinic::query()->findOrFail($encounter->clinic_id);
        $canBill = $user->hasClinicPermission(ClinicPermission::BillingCreate) && $this->clinicContext->currentEmployee() !== null;

        $items = [];
        if ($canBill && $encounter->isFinalized()) {
            $toBill = round(collect($this->checkout->lines($encounter))->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2);
            if ($toBill > 0) {
                $items[] = ['key' => 'visit', 'label' => 'This visit', 'detail' => 'Charges not billed yet', 'amount' => $toBill, 'url' => route('clinic.reception.billing.collect', $encounter), 'bills' => true];
            }
        }
        $bills = PatientBilling::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->where('branch_id', $encounter->branch_id)->where('patient_id', $encounter->patient_id)
            ->whereIn('status', [BillingStatus::Pending->value, BillingStatus::PartiallyPaid->value])->where('outstanding_balance', '>', 0)
            ->orderBy('issued_at')->get(['id', 'encounter_id', 'billing_number', 'outstanding_balance']);
        foreach ($bills as $bill) {
            $items[] = [
                'key' => "bill-{$bill->id}", 'label' => $bill->encounter_id === $encounter->id ? 'This visit' : 'Earlier balance', 'detail' => $bill->billing_number,
                'amount' => (float) $bill->outstanding_balance, 'url' => route('clinic.billing.payments.store', $bill->id), 'bills' => false,
            ];
        }

        return ['currency' => (string) config('reception.currency', 'INR'), 'methods' => app(PaymentService::class)->methodOptions($clinic, atDesk: true), 'items' => $items];
    }

    /**
     * Who may see patients here now, with their load today (one grouped count).
     *
     * @param  list<int>  $queueIds
     * @return list<array<string, mixed>>
     */
    private function doctors(Clinic $clinic, Branch $branch, array $queueIds): array
    {
        $load = $queueIds === [] ? collect() : ClinicQueueItem::query()->withoutGlobalScopes()->whereIn('clinic_queue_id', $queueIds)->whereNotNull('doctor_id')
            ->groupBy('doctor_id', 'status')->selectRaw('doctor_id, status, count(*) as total')->toBase()->get()->groupBy('doctor_id');

        $roster = $this->roster->forBranch($clinic, $branch);
        app(ClinicalContextResolver::class)->loadProfiles($roster->map(fn (array $entry) => $entry['employee']->practitionerProfile)->filter());
        $areas = app(PracticeAreas::class);

        return $roster->map(function (array $entry) use ($load, $areas) {
            /** @var ClinicEmployee $employee */
            $employee = $entry['employee'];
            $mine = ($load->get($employee->id) ?? collect())->pluck('total', 'status');

            return [
                'id' => $employee->id,
                'name' => $employee->name,
                'specialties' => $employee->practitionerProfile?->specialtyKeys() ?? [],
                'practice' => $areas->contextLabel($employee->practitionerProfile?->primaryPracticeArea()),
                'shift' => $entry['shift'],
                'shift_label' => $entry['shift_label'],
                'room' => $entry['room'],
                'waiting' => (int) (($mine[QueueItemStatus::Waiting->value] ?? 0) + ($mine[QueueItemStatus::Called->value] ?? 0)),
                'with_patient' => (int) ($mine[QueueItemStatus::InConsultation->value] ?? 0) > 0,
                'done' => (int) ($mine[QueueItemStatus::Completed->value] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * What needs a person now: long waits, walk-ins with no doctor, patients
     * marked not present, bookings past their time. A few rows of each, never
     * the whole list (a doctor on leave shows on the doctors panel).
     *
     * @param  list<int>  $queueIds
     * @return array{items: Collection<int, ClinicQueueItem>, long: list<int>, unassigned: list<int>, not_present: list<int>, late: Collection<int, Appointment>, counts: array<string, int>}
     */
    private function attention(Clinic $clinic, Branch $branch, array $queueIds, CarbonImmutable $now): array
    {
        $limits = config('reception.thresholds');
        $waiting = fn () => $this->items($queueIds, self::LISTS['waiting']);
        $longQuery = fn () => $waiting()->where('checked_in_at', '<=', $now->subMinutes((int) $limits['long_wait_minutes']));
        $unassignedQuery = fn () => $waiting()->whereNull('doctor_id');

        $long = $longQuery()->orderBy('checked_in_at')->limit(self::ATTENTION)->get();
        $unassigned = $unassignedQuery()->orderBy('checked_in_at')->limit(self::ATTENTION)->get();
        $notPresent = $this->items($queueIds, self::LISTS['not_present'])->orderBy('checked_in_at')->limit(self::ATTENTION)->get();
        $lateQuery = $this->bookedNotArrived($clinic, $branch, $now)->where('scheduled_at', '<=', $now->subMinutes((int) $limits['late_arrival_minutes']));
        $late = (clone $lateQuery)->with(['patient' => fn ($q) => $q->withoutGlobalScopes()->select(self::PATIENT_COLUMNS), 'practitioner:id,name'])
            ->orderBy('scheduled_at')->limit(self::ATTENTION)->get(['id', 'patient_id', 'practitioner_id', 'prospect_name', 'prospect_phone', 'scheduled_at', 'status', 'appointment_type']);

        return [
            'items' => $long->concat($unassigned)->concat($notPresent)->unique('id')->values(),
            'long' => $long->pluck('id')->all(),
            'unassigned' => $unassigned->pluck('id')->all(),
            'not_present' => $notPresent->pluck('id')->all(),
            'late' => $late,
            'counts' => [
                'long' => $long->count() < self::ATTENTION ? $long->count() : $longQuery()->count(),
                'unassigned' => $unassigned->count() < self::ATTENTION ? $unassigned->count() : $unassignedQuery()->count(),
                'late' => $late->count() < self::ATTENTION ? $late->count() : (clone $lateQuery)->count(),
            ],
        ];
    }

    /**
     * @param  array{items: Collection<int, ClinicQueueItem>, long: list<int>, unassigned: list<int>, not_present: list<int>, late: Collection<int, Appointment>, counts: array<string, int>}  $attention
     * @return array<string, mixed>
     */
    private function presentAttention(array $attention, callable $present, CarbonImmutable $now): array
    {
        $byId = $attention['items']->keyBy('id');
        $pick = fn (array $ids) => collect($ids)->map(fn (int $id) => $present($byId[$id]))->values()->all();

        return [
            'long_wait' => ['count' => $attention['counts']['long'], 'minutes' => (int) config('reception.thresholds.long_wait_minutes'), 'rows' => $pick($attention['long'])],
            'unassigned' => ['count' => $attention['counts']['unassigned'], 'rows' => $pick($attention['unassigned'])],
            'not_present' => ['rows' => $pick($attention['not_present'])],
            'late_bookings' => [
                'count' => $attention['counts']['late'],
                'rows' => $attention['late']->map(fn (Appointment $a) => $this->booking($a, $now))->values()->all(),
            ],
        ];
    }

    /**
     * A booking not yet checked in: who (a patient, or a prospect booked
     * without registering), when, with whom.
     *
     * @return array<string, mixed>
     */
    private function booking(Appointment $a, CarbonImmutable $now): array
    {
        $late = $a->scheduled_at && $a->scheduled_at->lessThan($now) ? (int) $a->scheduled_at->diffInMinutes($now) : null;

        return [
            'id' => $a->id,
            'patient' => $a->patient ? ['id' => $a->patient->id, 'name' => $a->patient->full_name, 'uhid' => $a->patient->uhid] : null,
            'prospect' => $a->patient ? null : ['name' => $a->prospect_name, 'phone' => $a->prospect_phone],
            'doctor' => $a->practitioner?->name,
            'doctor_id' => $a->practitioner_id,
            'type' => $a->appointment_type ? str($a->appointment_type)->replace('_', ' ')->ucfirst()->toString() : null,
            'scheduled_at' => $a->scheduled_at?->format(QueueBoard::DATETIME),
            'late_minutes' => $late,
            // A registered patient's booking is checked in from its row (Reception's check-in, by the booking);
            // a prospect is registered first.
            'links' => ['status' => route('clinic.appointments.status', $a)] + ($a->patient ? ['check_in' => route('clinic.reception.check-in')] : []),
        ];
    }

    /**
     * A queue row: identity, today's visit, doctor, times, safety flags.
     *
     * @param  array<int, list<array<string, mixed>>>  $safety
     * @return array<string, mixed>
     */
    private function row(Clinic $clinic, ClinicQueueItem $item, CarbonImmutable $now, array $safety, bool $canOpenPatient): array
    {
        $visit = $item->patientVisit;
        /** @var Patient|null $patient */
        $patient = $visit?->patient;
        $flags = $patient ? ($safety[$patient->id] ?? []) : [];
        $preConsultation = in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called], true) && $item->encounter_id === null;

        return [
            'id' => $item->id,
            'status' => $item->status->value,
            'priority' => ($item->priority ?? VisitPriority::Normal)->value,
            'patient' => $patient ? [
                'id' => $patient->id,
                'name' => $patient->full_name,
                'uhid' => $patient->uhid,
                'age' => $patient->age,
                'gender' => $patient->gender,
            ] : null,
            // Came in on a booking, or walked in.
            'source' => $item->appointment_id ? 'appointment' : 'walk_in',
            'case' => $visit?->caseNumber ? CaseNumberFormatter::shortLabel($visit->caseNumber->sequence) : null,
            'visit' => $this->visitLabels->for($clinic, $visit),
            'doctor' => $item->doctor ? ['id' => $item->doctor->id, 'name' => $item->doctor->name] : null,
            'checked_in_at' => $item->checked_in_at?->format(QueueBoard::DATETIME),
            'called_at' => $item->called_at?->format(QueueBoard::DATETIME),
            'started_at' => $item->started_at?->format(QueueBoard::DATETIME),
            'completed_at' => $item->completed_at?->format(QueueBoard::DATETIME),
            'wait_minutes' => $item->checked_in_at && $preConsultation ? (int) $item->checked_in_at->diffInMinutes($now) : null,
            'safety' => array_slice($flags, 0, 3),
            'safety_more' => max(0, count($flags) - 3),
            'can_correct' => $preConsultation,
            'links' => [
                'status' => route('clinic.reception.queue.status', $item),
                'priority' => route('clinic.reception.queue.priority', $item),
                'doctor' => route('clinic.reception.queue.doctor', $item),
                'visit' => route('clinic.reception.queue.visit', $item),
                'patient' => $canOpenPatient && $patient ? route('clinic.patients.show', $patient->id) : null,
                // A physiotherapist without a login: the desk opens and works the visit for them (`DeskPhysio`).
                ...$this->deskPhysioLinks($item),
            ],
        ];
    }

    /** @return array{desk_open?: string, desk_workspace?: string} */
    private function deskPhysioLinks(ClinicQueueItem $item): array
    {
        $user = auth()->user();
        if (! $user || ! in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called, QueueItemStatus::InConsultation, QueueItemStatus::Completed], true)
            || ! $item->doctor_id || ! ($this->deskRuns[$item->doctor_id] ??= app(DeskPhysio::class)->mayRunFor($user, $item->doctor))) {
            return [];
        }

        return $item->encounter_id
            ? ['desk_workspace' => route('clinic.desk-physio.show', $item->encounter_id)]
            : (in_array($item->status, [QueueItemStatus::Waiting, QueueItemStatus::Called], true) ? ['desk_open' => route('clinic.reception.physio-desk.open', $item)] : []);
    }

    /**
     * Today's queue items in some statuses, with only what a row shows.
     *
     * @param  list<int>  $queueIds
     * @param  list<QueueItemStatus>  $statuses
     */
    private function items(array $queueIds, array $statuses): Builder
    {
        return ClinicQueueItem::query()->withoutGlobalScopes()->whereIn('clinic_queue_id', $queueIds ?: [0])
            ->whereIn('status', array_map(fn (QueueItemStatus $s) => $s->value, $statuses))
            ->with([
                'patientVisit' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'patient_id', 'case_number_id', 'clinic_specialty_id', 'visit_type_key', 'visit_reason']),
                'patientVisit.patient' => fn ($q) => $q->withoutGlobalScopes()->select(self::PATIENT_COLUMNS),
                'patientVisit.caseNumber',
                // `user_id` too: whether the doctor signs in decides whether the desk may work for them.
                'doctor:id,name,user_id,clinic_id',
            ]);
    }

    /** Today's bookings at this branch that still stand (not cancelled). */
    private function appointmentsToday(Clinic $clinic, Branch $branch, CarbonImmutable $now): Builder
    {
        return Appointment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereOnDate('scheduled_at', $now)
            ->where('status', '!=', AppointmentStatus::Cancelled->value);
    }

    /** Bookings for today at this branch that have not been checked in. */
    private function bookedNotArrived(Clinic $clinic, Branch $branch, CarbonImmutable $now): Builder
    {
        return Appointment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereOnDate('scheduled_at', $now)
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value]);
    }

    /** @return list<int> */
    private function queueIds(Clinic $clinic, Branch $branch, CarbonImmutable $now): array
    {
        return ClinicQueue::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branch->id)
            ->whereOnDate('queue_date', $now)->pluck('id')->all();
    }
}
