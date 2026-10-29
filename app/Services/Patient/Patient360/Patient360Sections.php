<?php

namespace App\Services\Patient\Patient360;

use App\AppointmentStatus;
use App\BillingStatus;
use App\ClinicPermission;
use App\Models\Appointment;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\DeletionRequest;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Models\PhysioCarePlan;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\SecurityAuditLog;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Communication\ManualShare;
use App\Services\Governance\DeletionGovernance;
use App\Services\OwnerMode\OwnerMode;
use App\Services\PaymentService;
use App\Services\TemplateService;
use App\Services\Treatment\TreatmentReadModel;
use App\Support\ClinicClock;
use App\Support\ClinicFrontend;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Each Patient 360 workspace section, loaded when it is opened, one bounded
 * page at a time. Rows carry what the screen shows and the URLs of the
 * actions the user may take on them (the routes check again).
 */
class Patient360Sections
{
    private const PER_PAGE = 15;

    public function __construct(
        protected Patient360Access $access,
        protected Patient360Summary $summary,
        protected ClinicContext $clinicContext,
        protected Patient360SectionPolicy $policy,
        protected Patient360DentalWorkspace $dentalWorkspace,
        protected Patient360PhysioWorkspace $physioWorkspace,
        protected DeletionGovernance $governance,
        protected TreatmentReadModel $treatmentReadModel,
    ) {}

    /**
     * One feed of everything recorded about the patient, newest first.
     *
     * @return array<string, mixed>
     */
    public function timeline(Patient $patient, User $user, ?string $category, int $page): array
    {
        // An allow-list: each role sees the categories it may read, and only
        // event types this feed knows; nothing unknown slips through.
        $allowed = $this->timelineCategories($user, $patient);
        $categories = $category !== null && in_array($category, $allowed, true) ? [$category] : $allowed;
        $types = array_keys(array_filter(self::TIMELINE, fn (array $entry) => in_array($entry[0], $categories, true)));

        $events = PatientTimelineEvent::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereIn('event_type', $types)
            ->with('actor:id,name')
            ->latest('id')
            ->paginate(20, ['*'], 'page', $page);

        return $this->page($events, fn (PatientTimelineEvent $event) => [
            'id' => $event->id,
            'type' => $event->event_type,
            'category' => self::TIMELINE[$event->event_type][0] ?? 'record',
            'title' => self::TIMELINE[$event->event_type][1] ?? Str::headline($event->event_type),
            'description' => $this->describe($event),
            'at' => $event->created_at?->toIso8601String(),
            'actor' => $event->actor?->name,
            'open_url' => $this->sourceUrl($event, $patient, $user),
        ], ['categories' => array_values(array_intersect(array_unique(array_column(self::TIMELINE, 0)), $allowed))]);
    }

    /**
     * @return list<string>
     */
    private function timelineCategories(User $user, Patient $patient): array
    {
        $field = fn (string $name) => $this->policy->field($user, $patient, $name);

        return array_values(array_filter([
            'registration', 'record', 'appointment', 'check_in', 'follow_up', 'governance',
            $field('communication') ? 'communication' : null,
            $field('admin_documents') || $field('clinical_documents') ? 'document' : null,
            $field('clinical') ? 'consultation' : null,
            $field('clinical') ? 'prescription' : null,
            $field('clinical') ? 'investigation' : null,
            $field('clinical') ? 'treatment' : null,
            $field('dental') ? 'dental' : null,
            $field('dental') || $field('dental_operational') ? 'lab' : null,
            $field('physio') ? 'physio' : null,
            $field('financial') ? 'billing' : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function encounters(Patient $patient, User $user, int $page): array
    {
        $encounters = $this->summary->encounters($patient)
            ->with(['doctor:id,name', 'branch:id,name', 'diagnoses' => fn ($query) => $query->orderByDesc('is_primary')])
            ->withCount(['procedures', 'prescriptions', 'orders'])
            ->latest('started_at')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $voidMode = $this->governance->modeFor($user, 'encounter.void');

        return $this->page($encounters, fn (ClinicalEncounter $encounter) => [
            'id' => $encounter->id,
            'number' => $encounter->encounter_number,
            'date' => $encounter->started_at?->toIso8601String(),
            'practitioner' => $encounter->doctor?->name,
            'branch' => $encounter->branch?->name,
            'status' => $encounter->isVoided() ? 'voided' : ($encounter->isFinalized() ? 'signed' : ($encounter->isEditable() ? 'draft' : 'closed')),
            'void_reason' => $encounter->void_reason,
            'void_mode' => $encounter->isFinalized() && ! $encounter->isVoided() ? $voidMode : null,
            'complaint' => $encounter->chief_complaint,
            'history' => $encounter->history ? Str::limit($encounter->history, 220) : null,
            'examination' => $encounter->examination ? Str::limit($encounter->examination, 220) : null,
            'diagnoses' => $encounter->diagnoses->map(fn ($d) => ['description' => $d->description, 'code' => $d->code, 'primary' => $d->is_primary])->values(),
            'counts' => ['procedures' => $encounter->procedures_count, 'prescriptions' => $encounter->prescriptions_count, 'orders' => $encounter->orders_count],
            'follow_up_on' => $encounter->follow_up_on?->toDateString(),
            'url' => $user->can('view', $encounter) ? route('clinic.consultations.show', $encounter) : null,
        ]);
    }

    /** Visit statuses that mean the patient came (`patient_visits.visit_status`). */
    public const ATTENDED_VISIT = ['checked_in', 'in_queue', 'waiting', 'with_doctor', 'in_progress', 'completed'];

    /**
     * Visits & History: every visit the patient made, and every booking they
     * never came for, newest day first, a page at a time.
     *
     * - A visit is a `patient_visits` row (check-in writes it). A visit only
     *   registered, never checked in, or cancelled, is listed as such and is
     *   not counted as attended.
     * - A booking is listed only when no visit came of it (no queue item
     *   carries it): missed, cancelled, or still open on a day already past.
     *   A booking that was checked in is part of its visit, not a second row.
     * - Both are limited to the member's branches. The consultation behind a
     *   visit is named only to clinical roles.
     *
     * @return array<string, mixed>
     */
    public function visits(Patient $patient, User $user, int $page): array
    {
        $today = ClinicClock::today()->toDateString();
        $branchIds = $this->access->branchIds();
        $inBranches = fn ($query, string $column) => $branchIds === null ? $query : $query->whereIn($column, $branchIds ?: [0]);
        $open = [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Arrived->value, AppointmentStatus::CheckedIn->value];

        $visits = DB::table('patient_visits')->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn ($query) => $inBranches($query, 'branch_id'))
            ->selectRaw("'visit' as kind, id, DATE(visit_date) as day, 0 as kind_order");
        $bookings = DB::table('appointments')->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn ($query) => $inBranches($query, 'branch_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('clinic_queue_items')->whereColumn('clinic_queue_items.appointment_id', 'appointments.id'))
            ->where(fn ($query) => $query->whereIn('status', [AppointmentStatus::NoShow->value, AppointmentStatus::Cancelled->value])
                ->orWhere(fn ($past) => $past->whereIn('status', $open)->where('scheduled_at', '<', $today))
                // Marked done without a visit (older records): listed, never counted as a visit.
                ->orWhere(fn ($done) => $done->whereIn('status', [AppointmentStatus::InConsultation->value, AppointmentStatus::Completed->value])->where('scheduled_at', '<', ClinicClock::now())))
            ->selectRaw("'booking' as kind, id, DATE(scheduled_at) as day, 1 as kind_order");

        $rows = DB::query()->fromSub($visits->unionAll($bookings), 'history')
            ->orderByDesc('day')->orderBy('kind_order')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $ids = collect($rows->items())->groupBy('kind')->map(fn ($items) => $items->pluck('id')->all());
        $clinical = $this->access->canViewClinical($user);
        $branch = $this->summary->clinicBranch($patient);
        $visitModels = PatientVisit::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->whereIn('id', $ids['visit'] ?? [])
            ->with(['doctor' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name']), 'branch' => $branch, 'caseNumber:id,case_number',
                'queueItem' => fn ($q) => $q->withoutGlobalScopes()->with(['appointment' => fn ($a) => $a->withoutGlobalScopes()->select(['id', 'scheduled_at'])])])
            ->when($clinical, fn ($q) => $q->with(['encounter' => fn ($e) => $e->withoutGlobalScopes()->select(['id', 'patient_visit_id', 'encounter_number', 'status', 'finalized_at', 'voided_at'])]))
            ->get()->keyBy('id');
        $bookingModels = Appointment::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->whereIn('id', $ids['booking'] ?? [])
            ->with(['practitioner' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'name']), 'branch' => $branch])
            ->get()->keyBy('id');

        // The day in Appointments is the one real page a row can open (for members who may see appointments).
        $calendar = ClinicFrontend::available('clinic.appointments.index') && $this->access->canViewAppointments($user)
            ? fn (string $day) => route('clinic.appointments.index', ['date' => $day]) : fn () => null;

        $counts = [
            'visits' => DB::table('patient_visits')->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->tap(fn ($query) => $inBranches($query, 'branch_id'))->whereIn('visit_status', self::ATTENDED_VISIT)->count(),
            'not_attended' => DB::query()->fromSub($bookings, 'b')->count(),
        ];

        return $this->page($rows, fn ($row) => $row->kind === 'visit'
            ? $this->visitRow($visitModels[$row->id], $clinical, $calendar)
            : $this->bookingRow($bookingModels[$row->id], $calendar), ['counts' => $counts]);
    }

    /** @return array<string, mixed> */
    private function visitRow(PatientVisit $visit, bool $clinical, \Closure $calendar): array
    {
        $status = (string) ($visit->visit_status ?? 'registered');
        $queue = $visit->queueItem;
        // Check-in time is clinic wall-clock digits; a visit with no queue entry shows when it was written.
        $at = $queue?->checked_in_at ?? ($visit->created_at ? ClinicClock::fromUtc($visit->created_at) : null);
        $encounter = $clinical ? $visit->encounter : null;

        return [
            'kind' => 'visit',
            'id' => $visit->id,
            'date' => $visit->visit_date?->toDateString(),
            'time_label' => $at?->format('h:i A'),
            'practitioner' => $visit->doctor?->name,
            'branch' => $visit->branch?->name,
            'status' => $status,
            'attended' => in_array($status, self::ATTENDED_VISIT, true),
            'case_number' => $visit->caseNumber?->case_number,
            'registration_type' => $visit->registration_type?->value,
            'visit_type' => $visit->visit_type_key ? Str::headline($visit->visit_type_key) : null,
            'reason' => $visit->visit_reason,
            'booked_for' => $queue?->appointment?->scheduled_at?->format('h:i A'),
            'consultation' => $encounter ? [
                'number' => $encounter->encounter_number,
                'status' => $encounter->voided_at ? 'voided' : ($encounter->finalized_at ? 'signed' : 'open'),
                // The consultation screen is not built yet (`ClinicFrontend`).
                'url' => null,
            ] : null,
            'calendar_url' => $queue?->appointment ? $calendar($visit->visit_date?->toDateString() ?? '') : null,
        ];
    }

    /** @return array<string, mixed> */
    private function bookingRow(Appointment $appointment, \Closure $calendar): array
    {
        $status = $appointment->status?->value;

        return [
            'kind' => 'booking',
            'id' => $appointment->id,
            'date' => $appointment->scheduled_at->format('Y-m-d'),
            'time_label' => $appointment->scheduled_at->format('h:i A'),
            'practitioner' => $appointment->practitioner?->name,
            'branch' => $appointment->branch?->name,
            // Why it is not a visit: missed, cancelled, never checked in, or marked done with no visit recorded.
            'status' => match ($status) {
                AppointmentStatus::NoShow->value => 'missed',
                AppointmentStatus::Cancelled->value => 'cancelled',
                AppointmentStatus::InConsultation->value, AppointmentStatus::Completed->value => 'no_visit_recorded',
                default => 'not_attended',
            },
            'attended' => false,
            'type' => $appointment->appointment_type,
            'calendar_url' => $calendar($appointment->scheduled_at->format('Y-m-d')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function appointments(Patient $patient, User $user, string $scope, int $page): array
    {
        $today = ClinicClock::today();
        $working = $this->clinicContext->workingBranch();
        $open = [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Arrived->value, AppointmentStatus::CheckedIn->value];

        $query = Appointment::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->tap(fn (Builder $q) => $this->summary->inBranches($q))
            ->with(['practitioner:id,name', 'branch:id,name']);

        $scope === 'upcoming'
            ? $query->where('scheduled_at', '>=', $today)->whereIn('status', $open)->orderBy('scheduled_at')
            : $query->where(fn (Builder $q) => $q->where('scheduled_at', '<', $today)->orWhereNotIn('status', $open))->orderByDesc('scheduled_at');

        $manage = $user->hasClinicPermission(ClinicPermission::AppointmentManage);
        $checkIn = $user->hasClinicPermission(ClinicPermission::QueueManage);

        return $this->page($query->paginate(self::PER_PAGE, ['*'], 'page', $page), function (Appointment $appointment) use ($manage, $checkIn, $today, $working) {
            $upcoming = $appointment->scheduled_at->gte($today) && in_array($appointment->status, [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed], true);

            return [
                'id' => $appointment->id,
                'date_label' => $appointment->scheduled_at->format('D, d M Y'),
                'time_label' => $appointment->scheduled_at->format('h:i A'),
                'date' => $appointment->scheduled_at->format('Y-m-d'),
                'duration' => $appointment->duration_minutes,
                'practitioner' => $appointment->practitioner?->name,
                'practitioner_id' => $appointment->practitioner_id,
                'branch' => $appointment->branch?->name,
                'type' => $appointment->appointment_type,
                'status' => $appointment->status?->value,
                'status_label' => $appointment->status?->label(),
                'notes' => $appointment->notes,
                'actions' => [
                    'reschedule_url' => $manage && $upcoming ? route('clinic.appointments.reschedule', $appointment) : null,
                    'cancel_url' => $manage && $upcoming ? route('clinic.appointments.cancel', $appointment) : null,
                    'check_in_url' => $checkIn && $upcoming && $appointment->scheduled_at->isSameDay($today) && $working?->id === $appointment->branch_id
                        ? route('clinic.reception.check-in') : null,
                    'view_url' => route('clinic.appointments.index', ['date' => $appointment->scheduled_at->format('Y-m-d')]),
                ],
            ];
        });
    }

    /**
     * Plans and treatment done, across specialties: dental plans, the plan of
     * care, and every procedure performed with whether it is billed.
     *
     * @return array<string, mixed>
     */
    public function treatment(Patient $patient, User $user, int $page): array
    {
        $procedures = PatientProcedure::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereHas('encounter', fn (Builder $query) => $this->summary->inBranches($query))
            ->with(['encounter:id,encounter_number,doctor_id', 'encounter.doctor:id,name'])
            ->latest('performed_at')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $billed = PatientBillingItem::query()->where('source_type', (new PatientProcedure)->getMorphClass())
            ->whereIn('source_id', $procedures->getCollection()->pluck('id'))
            ->whereHas('billing', fn (Builder $bill) => $bill->where('status', '!=', BillingStatus::Cancelled->value))
            ->pluck('source_id')->map(fn ($id) => (int) $id)->all();

        return [
            ...$this->page($procedures, fn (PatientProcedure $procedure) => [
                'id' => $procedure->id,
                'name' => $procedure->name,
                'quantity' => $procedure->quantity,
                'tooth' => $procedure->details['tooth_number'] ?? null,
                'notes' => $procedure->description,
                'date' => ($procedure->performed_at ?? $procedure->created_at)?->toIso8601String(),
                'practitioner' => $procedure->encounter?->doctor?->name,
                'consultation' => $procedure->encounter?->encounter_number,
                'consultation_url' => $procedure->encounter ? route('clinic.consultations.show', $procedure->encounter_id) : null,
                'billed' => in_array($procedure->id, $billed, true),
            ]),
            // The treatment engine: every treatment (legacy dental lines and physio
            // plans are mirrored into it). `plans` / `care_plans` below are the legacy
            // reads the current screen still uses.
            'treatments' => $this->treatmentReadModel->forPatient($patient->clinic_id, $patient->id, $this->access->branchIds(), false, $this->access->canViewBilling($user)),
            'plans' => TreatmentPlan::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->with(['records' => fn ($query) => $query->orderBy('sort_order'), 'author:id,name'])->latest('id')->limit(10)->get()
                ->map(fn (TreatmentPlan $plan) => $this->dentalPlan($plan))->values(),
            'care_plans' => PhysioCarePlan::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->withCount('sessions')->latest('id')->limit(10)->get()
                ->map(fn (PhysioCarePlan $plan) => [
                    'id' => $plan->id, 'title' => $plan->title, 'status' => $plan->status, 'goals' => $plan->goals,
                    'done' => $plan->sessions_count, 'planned' => $plan->planned_sessions, 'frequency' => $plan->frequency,
                    'starts_on' => $plan->starts_on?->toDateString(),
                ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prescriptions(Patient $patient, User $user, int $page): array
    {
        $rxs = $this->summary->prescriptions($patient)->where('status', '!=', Prescription::DRAFT)
            ->with(['items', 'prescribingDoctor:id,name', 'encounter:id,encounter_number'])
            ->latest('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return $this->page($rxs, fn (Prescription $rx) => [
            'id' => $rx->id,
            'number' => $rx->prescription_number,
            'status' => $rx->status,
            'date' => ($rx->finalized_at ?? $rx->created_at)?->toIso8601String(),
            'practitioner' => $rx->prescribingDoctor?->name,
            'notes' => $rx->notes,
            'cancellation_reason' => $rx->cancellation_reason,
            'consultation' => $rx->encounter?->encounter_number,
            'items' => $rx->items->map(fn (PrescriptionItem $line) => [
                'name' => $line->drug_name,
                'strength' => $line->strength,
                'form' => $line->form,
                'dosage' => $line->dosage,
                'frequency' => config("clinic_prescriptions.frequencies.{$line->frequency}") ?? $line->frequency,
                'duration' => $line->duration,
                'timing' => $line->timing ? (config("clinic_prescriptions.timings.{$line->timing}") ?? $line->timing) : null,
                'instructions' => $line->instructions,
            ])->values(),
            'view_url' => $rx->encounter_id && ClinicFrontend::available('clinic.consultations.show') ? route('clinic.consultations.show', $rx->encounter_id) : null,
            'print_url' => $user->can('print', $rx) ? route('clinic.prescriptions.print', $rx) : null,
            'pdf_url' => $user->can('print', $rx) ? route('clinic.prescriptions.print', [$rx, 'download' => 1]) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function investigations(Patient $patient, User $user, ?string $status, int $page): array
    {
        $base = fn () => $this->summary->orderItems($patient);

        // The front desk tracks where an investigation is (ordered, sample
        // collected, result in) to guide the patient; it never receives the
        // result, its values, attached reports or who is reviewing it.
        if (! $this->policy->field($user, $patient, 'investigation_results')) {
            $items = $base()
                ->when($status === 'pending', fn (Builder $query) => $query->whereIn('status', [ClinicalOrderItem::ORDERED, ClinicalOrderItem::COLLECTED]))
                ->when($status && $status !== 'pending', fn (Builder $query) => $query->where('status', $status))
                ->with('order:id,order_number,priority,created_at')
                ->latest('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);

            return [
                ...$this->page($items, fn (ClinicalOrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->item_name,
                    'category' => ClinicalOrderItem::CATEGORIES[$item->category]['label'] ?? $item->category,
                    'status' => in_array($item->status, [ClinicalOrderItem::RESULTED, ClinicalOrderItem::REVIEWED], true) ? 'result_in' : $item->status,
                    'priority' => $item->order?->priority,
                    'order_number' => $item->order?->order_number,
                    'date' => $item->created_at?->toIso8601String(),
                ]),
                'operational' => true,
                'counts' => ['pending' => $base()->whereIn('status', [ClinicalOrderItem::ORDERED, ClinicalOrderItem::COLLECTED])->count()],
            ];
        }

        $items = $base()
            ->when($status === 'pending', fn (Builder $query) => $query->whereIn('status', [ClinicalOrderItem::ORDERED, ClinicalOrderItem::COLLECTED]))
            ->when($status && $status !== 'pending', fn (Builder $query) => $query->where('status', $status))
            ->with(['order:id,order_number,priority,orderer_id,created_at', 'order.orderingDoctor:id,name', 'result', 'documents'])
            ->latest('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            ...$this->page($items, fn (ClinicalOrderItem $item) => [
                'id' => $item->id,
                'name' => $item->item_name,
                'category' => ClinicalOrderItem::CATEGORIES[$item->category]['label'] ?? $item->category,
                'body_site' => $item->body_site,
                'status' => $item->status,
                'priority' => $item->order?->priority,
                'order_number' => $item->order?->order_number,
                'date' => $item->created_at?->toIso8601String(),
                'practitioner' => $item->order?->orderingDoctor?->name,
                'result' => $item->result ? [
                    'summary' => $item->result->summary,
                    'values' => $item->result->content['values'] ?? [],
                    'is_abnormal' => $item->result->is_abnormal,
                    'final' => $item->result->status === 'final',
                    'review_note' => $item->result->review_note,
                ] : null,
                'attachments' => $item->documents->filter->isCleared()->map(fn (AttachedDocument $doc) => [
                    'name' => $doc->file_name, 'url' => route('clinic.patients.documents.download', [$patient->id, $doc->id]),
                ])->values(),
                // Linked once the orders page is built (`ClinicFrontend`).
                'url' => ClinicFrontend::available('clinic.orders.show') ? route('clinic.orders.show', $item->clinical_order_id) : null,
            ]),
            'counts' => [
                'pending' => $base()->whereIn('status', [ClinicalOrderItem::ORDERED, ClinicalOrderItem::COLLECTED])->count(),
                'resulted' => $base()->where('status', ClinicalOrderItem::RESULTED)->count(),
                'abnormal' => $base()->whereHas('result', fn (Builder $query) => $query->where('is_abnormal', true))->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billing(Patient $patient, User $user, int $page): array
    {
        $live = fn () => $this->summary->bills($patient)->where('status', '!=', BillingStatus::Cancelled->value);
        $bills = $this->summary->bills($patient)->latest('issued_at')->latest('id')->paginate(10, ['*'], 'page', $page);
        $billIds = fn () => $this->summary->bills($patient)->select('id');

        return [
            'summary' => [
                'billed' => (float) $live()->sum('final_amount'),
                'paid' => (float) $live()->sum('paid_amount'),
                'refunded' => (float) $live()->sum('refunded_amount'),
                'outstanding' => (float) $live()->sum('outstanding_balance'),
            ],
            ...$this->page($bills, fn (PatientBilling $bill) => $this->billRow($bill)),
            'payments' => PatientBillingPayment::query()->whereIn('patient_billing_id', $billIds())->with('billing:id,billing_number')
                ->latest('id')->limit(8)->get()->map(fn (PatientBillingPayment $payment) => [
                    'receipt' => $payment->receipt_number, 'amount' => (float) $payment->amount, 'method' => $payment->payment_method?->label(),
                    'date' => ($payment->paid_at ?? $payment->created_at)?->toIso8601String(), 'bill' => $payment->billing?->billing_number,
                ])->values(),
            'refunds' => PatientBillingRefund::query()->whereIn('patient_billing_id', $billIds())->with('billing:id,billing_number')
                ->latest('id')->limit(8)->get()->map(fn (PatientBillingRefund $refund) => [
                    'number' => $refund->refund_number, 'amount' => (float) $refund->amount, 'reason' => $refund->reason,
                    'date' => $refund->created_at?->toIso8601String(), 'bill' => $refund->billing?->billing_number,
                ])->values(),
        ];
    }

    /**
     * One bill in full.
     *
     * @return array<string, mixed>
     */
    public function bill(PatientBilling $bill, User $user): array
    {
        $bill->load(['items', 'payments', 'refunds', 'doctor:id,name']);
        // Offered only as the server will allow them: the permission held at the
        // bill's own branch, and a refund by the owner only in Owner Mode.
        $branch = Branch::query()->withoutGlobalScopes()->where('clinic_id', $bill->clinic_id)->find($bill->branch_id);
        $ownerMode = app(OwnerMode::class);
        $collect = $branch !== null && $user->hasClinicPermissionAt(ClinicPermission::PaymentCollect, $branch);
        // The owner refunds and adjusts only in Owner Mode (T1); outside it the
        // actions are withheld and the page says why (`owner_mode_needed`).
        $ownerLocked = $ownerMode->isOwner() && ! $ownerMode->authorized();
        $refund = $branch !== null && $user->hasClinicPermissionAt(ClinicPermission::RefundProcess, $branch) && ! $ownerLocked;
        $adjust = $branch !== null && $user->hasClinicPermissionAt(ClinicPermission::DiscountApply, $branch) && ! $ownerLocked;
        $live = $bill->status !== BillingStatus::Cancelled;

        return [
            ...$this->billRow($bill),
            'doctor' => $bill->doctor?->name,
            'subtotal' => (float) $bill->total_amount,
            'discount' => (float) $bill->discount_amount,
            'tax' => (float) $bill->tax_amount,
            'items' => $bill->items->map(fn (PatientBillingItem $item) => [
                'description' => $item->description, 'quantity' => $item->quantity, 'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount_amount, 'tax' => (float) $item->tax_amount, 'amount' => (float) $item->final_amount,
            ])->values(),
            'payments' => $bill->payments->map(fn (PatientBillingPayment $payment) => [
                'id' => $payment->id, 'receipt' => $payment->receipt_number, 'amount' => (float) $payment->amount,
                'method' => $payment->payment_method?->label(), 'reference' => $payment->transaction_reference ?? null,
                'date' => ($payment->paid_at ?? $payment->created_at)?->toIso8601String(),
            ])->values(),
            'refunds' => $bill->refunds->map(fn (PatientBillingRefund $refund) => [
                'number' => $refund->refund_number, 'amount' => (float) $refund->amount, 'reason' => $refund->reason, 'date' => $refund->created_at?->toIso8601String(),
            ])->values(),
            // Only what this clinic accepts: the server refuses anything else.
            'payment_methods' => app(PaymentService::class)->methodOptions($this->clinicContext->clinic()),
            'actions' => [
                'pay_url' => $collect && $live && (float) $bill->outstanding_balance > 0 ? route('clinic.billing.payments.store', $bill) : null,
                'refund_url' => $refund && $live && (float) $bill->paid_amount - (float) $bill->refunded_amount > 0 ? route('clinic.billing.refunds.store', $bill) : null,
                // A concession or write-off on what is still due (`PaymentService::recordAdjustment`).
                'adjust_url' => $adjust && $live && (float) $bill->outstanding_balance > 0 ? route('clinic.billing.adjustments.store', $bill) : null,
                'owner_mode_needed' => $ownerLocked && $live,
                'print_url' => route('clinic.billing.bills.print', $bill),
                'checkout_url' => $bill->encounter_id ? route('clinic.billing.checkout', $bill->encounter_id) : null,
                // Voiding a bill is governed: the owner acts, anyone else asks.
                'void_mode' => $live && (float) $bill->paid_amount <= (float) $bill->refunded_amount ? $this->governance->modeFor($user, 'bill.void') : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documents(Patient $patient, User $user, int $page): array
    {
        $clinicalDocs = $this->policy->field($user, $patient, 'clinical_documents');
        $adminDocs = $this->policy->field($user, $patient, 'admin_documents');
        $clinicalTypes = Patient360SectionPolicy::CLINICAL_DOCUMENT_TYPES;
        $removeMode = $this->governance->modeFor($user, 'document.remove');

        // Clinical documents (filed against a consultation or a result, or of
        // a clinical type) reach clinical roles only; the rest are administrative.
        $documents = AttachedDocument::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->when(! $clinicalDocs, fn (Builder $query) => $query->whereNull('encounter_id')->whereNull('clinical_order_item_id')->whereNotIn('document_type', $clinicalTypes))
            ->when(! $adminDocs, fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNotNull('encounter_id')->orWhereNotNull('clinical_order_item_id')->orWhereIn('document_type', $clinicalTypes)))
            ->with(['uploader:id,name', 'clinicalOrderItem:id,item_name', 'encounter:id,encounter_number'])
            ->latest('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return $this->page($documents, function (AttachedDocument $doc) use ($patient, $removeMode) {
            $extension = strtolower(pathinfo((string) $doc->file_path, PATHINFO_EXTENSION));

            return [
                'id' => $doc->id,
                'name' => $doc->file_name,
                'category' => self::DOCUMENT_TYPES[$doc->document_type] ?? Str::headline((string) $doc->document_type),
                'date' => $doc->created_at?->toIso8601String(),
                'uploaded_by' => $doc->uploader?->name,
                'source' => $doc->clinicalOrderItem ? "Result: {$doc->clinicalOrderItem->item_name}" : ($doc->encounter ? "Consultation {$doc->encounter->encounter_number}" : 'Patient record'),
                'file_type' => strtoupper($extension),
                'size' => $doc->file_size_bytes,
                'status' => $doc->isCleared() ? 'clean' : (string) $doc->scan_status,
                'preview_url' => $doc->isCleared() && in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)
                    ? route('clinic.patients.documents.download', [$patient->id, $doc->id, 'inline' => 1]) : null,
                'download_url' => $doc->isCleared() ? route('clinic.patients.documents.download', [$patient->id, $doc->id]) : null,
                'remove_mode' => $removeMode,
            ];
        }, [
            // Uploading needs documents.manage (the route checks it again). Intraoral photos only where Dental is part of the plan.
            'upload' => $user->hasClinicPermission(ClinicPermission::DocumentsManage) ? [
                'url' => route('clinic.patients.documents.store', $patient->id),
                'types' => collect(self::DOCUMENT_TYPES)->except($this->policy->field($user, $patient, 'dental') ? [] : ['intraoral_photo'])
                    ->except($clinicalDocs ? [] : $clinicalTypes)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values()->all(),
                'max_mb' => 10,
                'accept' => '.pdf,.jpg,.jpeg,.png,.webp',
            ] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function followUps(Patient $patient, User $user): array
    {
        $manage = $this->access->canManageFollowUps($user);
        $today = ClinicClock::today()->toDateString();

        $rows = PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->with(['practitioner:id,name', 'encounter:id,encounter_number', 'appointment:id,scheduled_at'])
            ->orderByRaw("case when status in ('due', 'booked') then 0 else 1 end")->orderBy('due_on')->limit(50)->get();

        return [
            'data' => $rows->map(fn (PatientFollowUp $followUp) => [
                'id' => $followUp->id,
                'due_on' => $followUp->due_on->toDateString(),
                'reason' => $followUp->reason,
                'practitioner' => $followUp->practitioner?->name,
                'status' => $followUp->status,
                'overdue' => $followUp->isOpen() && $followUp->due_on->toDateString() < $today,
                'source' => $followUp->encounter?->encounter_number,
                'source_url' => $followUp->encounter_id && ClinicFrontend::available('clinic.consultations.show') && $user->can('view', $followUp->encounter) ? route('clinic.consultations.show', $followUp->encounter_id) : null,
                'booked_for' => $followUp->appointment?->scheduled_at?->format('D, d M Y h:i A'),
                'actions' => $manage && $followUp->isOpen() ? [
                    'reschedule_url' => route('clinic.patients.follow-ups.update', [$patient->id, $followUp->id]),
                    'complete_url' => route('clinic.patients.follow-ups.complete', [$patient->id, $followUp->id]),
                    'cancel_url' => route('clinic.patients.follow-ups.cancel', [$patient->id, $followUp->id]),
                ] : null,
            ])->values(),
            'add_url' => $manage ? route('clinic.patients.follow-ups.store', $patient->id) : null,
        ];
    }

    /**
     * Messages to the patient. A message is "Sent" only through a real
     * provider; with none connected, a processed message is "Simulated".
     *
     * @return array<string, mixed>
     */
    public function communications(Patient $patient, User $user, int $page): array
    {
        $provider = config('clinic_messages.provider');
        $now = ClinicClock::now();
        $templates = app(TemplateService::class);

        $messages = Notification::query()->where('clinic_id', $patient->clinic_id)
            ->where('recipient_type', 'patient')->where('recipient_id', $patient->id)
            ->with('template')->latest('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            ...$this->page($messages, function (Notification $message) use ($provider, $now, $templates) {
                $status = match ($message->status) {
                    'pending' => $message->scheduled_at && $message->scheduled_at->gt($now) ? 'scheduled' : 'queued',
                    'processing' => 'sending',
                    // WhatsApp goes out for real on the clinic's resolved number.
                    'delivered' => $provider || $message->channel === 'whatsapp' ? 'sent' : 'simulated',
                    'failed' => 'failed',
                    'cancelled' => 'withdrawn',
                    default => (string) $message->status,
                };
                $rendered = $message->template ? $templates->renderTemplate($message->template, $message->metadata['placeholders'] ?? []) : null;

                return [
                    'id' => $message->id,
                    'kind' => $message->template?->name ?? Str::headline((string) ($message->template?->code ?? 'Message')),
                    'channel' => $message->channel,
                    'status' => $status,
                    'scheduled_for' => $message->scheduled_at?->format('d M Y, h:i A'),
                    'sent_at' => $message->sent_at?->toIso8601String(),
                    'created_at' => $message->created_at?->toIso8601String(),
                    'failed_reason' => $message->failed_reason,
                    'text' => $rendered['content'] ?? null,
                ];
            }),
            'provider_connected' => (bool) $provider,
            // What this member may send by hand now, with the text and any reason WhatsApp cannot (`ManualShare`).
            'share' => [
                'url' => route('clinic.patients.messages.share', $patient->id),
                'options' => app(ManualShare::class)->options($patient, $user),
            ],
        ];
    }

    /**
     * The security trail for this patient. Owners and administrators only.
     *
     * @return array<string, mixed>
     */
    public function audit(Patient $patient, int $page): array
    {
        $entries = SecurityAuditLog::query()->where('clinic_id', $patient->clinic_id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('subject_type', 'Patient')->where('subject_id', $patient->id))
                ->orWhere('context->patient_id', $patient->id))
            ->with('actor:id,name')->latest('id')->paginate(20, ['*'], 'page', $page);

        return $this->page($entries, fn (SecurityAuditLog $entry) => [
            'id' => $entry->id,
            'event' => $entry->event,
            'label' => Str::headline(str_replace('.', ' ', $entry->event)),
            'actor' => $entry->actor?->name,
            'subject' => $entry->subject_type ? "{$entry->subject_type} #{$entry->subject_id}" : null,
            'ip' => $entry->ip_address,
            'at' => $entry->occurred_at?->toIso8601String(),
        ]);
    }

    /**
     * The dentist's workspace (see `Patient360DentalWorkspace`).
     *
     * @return array<string, mixed>
     */
    public function dental(Patient $patient, User $user): array
    {
        return $this->dentalWorkspace->build($patient, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function tooth(Patient $patient, User $user, int $tooth): array
    {
        return $this->dentalWorkspace->tooth($patient, $user, $tooth);
    }

    /**
     * The physiotherapist's workspace (see `Patient360PhysioWorkspace`).
     *
     * @return array<string, mixed>
     */
    public function physio(Patient $patient, User $user): array
    {
        return $this->physioWorkspace->build($patient, $user);
    }

    /**
     * Destructive-action requests about this patient: all of them for the
     * owner, the user's own for anyone else.
     *
     * @return array<string, mixed>
     */
    public function requests(Patient $patient, User $user, int $page): array
    {
        $owner = $this->policy->lens($user, $patient)['owner'];
        $requests = DeletionRequest::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->when(! $owner, fn (Builder $query) => $query->where('requester_user_id', $user->id))
            ->with(['requester:id,name', 'approver:id,name'])
            ->latest('id')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            ...$this->page($requests, fn (DeletionRequest $request) => [
                'id' => $request->id,
                'action' => $this->governance->labelFor($request->action_type),
                'target' => $request->target_label,
                'reason' => $request->reason,
                'status' => $request->isExpired() ? DeletionRequest::EXPIRED : $request->status,
                'direct' => $request->direct,
                'requester' => $request->requester?->name,
                'approver' => $request->approver?->name,
                'created_at' => $request->created_at?->toIso8601String(),
                'decided_at' => ($request->executed_at ?? $request->rejected_at ?? $request->cancelled_at)?->toIso8601String(),
                'last_note' => collect($request->thread ?? [])->last()['note'] ?? null,
            ]),
            'queue_url' => route('clinic.approvals.index'),
            'owner' => $owner,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dentalPlan(TreatmentPlan $plan, ?ClinicalEncounter $open = null): array
    {
        $live = $plan->records->reject(fn (TreatmentRecord $r) => $r->status?->value === 'aborted');

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'status' => $plan->status,
            'author' => $plan->author?->name,
            'created_at' => $plan->created_at?->toIso8601String(),
            'done' => $live->filter(fn (TreatmentRecord $r) => $r->status?->value === 'completed')->count(),
            'total' => $live->count(),
            'accept_url' => $open && $plan->status === TreatmentPlan::PROPOSED ? route('clinic.consultations.dental.plans.accept', [$open, $plan]) : null,
            'items' => $plan->records->map(fn (TreatmentRecord $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'tooth' => $item->tooth_number,
                'surfaces' => $item->surfaces ?? [],
                'status' => $item->status?->value,
                'completed_at' => $item->completed_at?->toIso8601String(),
                'complete_url' => $open && in_array($plan->status, TreatmentPlan::WORKABLE, true) && in_array($item->status?->value, ['proposed', 'planned'], true)
                    ? route('clinic.consultations.dental.plans.items.complete', [$open, $plan, $item]) : null,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billRow(PatientBilling $bill): array
    {
        return [
            'id' => $bill->id,
            'number' => $bill->billing_number,
            'status' => $bill->status->value,
            'date' => ($bill->issued_at ?? $bill->created_at)?->toIso8601String(),
            'total' => (float) $bill->final_amount,
            'paid' => (float) $bill->paid_amount - (float) $bill->refunded_amount,
            'outstanding' => (float) $bill->outstanding_balance,
            'detail_url' => route('clinic.patients.360.bill', [$bill->patient_id, $bill->id]),
        ];
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $map
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function page(LengthAwarePaginator $paginator, callable $map, array $extra = []): array
    {
        return [
            'data' => $paginator->getCollection()->map($map)->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            ...$extra,
        ];
    }

    private function describe(PatientTimelineEvent $event): string
    {
        $c = $event->context ?? [];
        $money = fn ($v) => '₹'.number_format((float) $v, 2);

        return match ($event->event_type) {
            'registered' => 'Registered at the front desk.',
            'appointment_scheduled' => 'Booked'.(! empty($c['practitioner']) ? " with {$c['practitioner']}" : '').(! empty($c['scheduled_at']) ? " for {$c['scheduled_at']}" : '').'.',
            'appointment_rescheduled' => 'Moved'.(! empty($c['scheduled_at']) ? " to {$c['scheduled_at']}" : '').'.',
            'appointment_cancelled' => 'Cancelled'.(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'appointment_checked_in', 'walk_in_registered' => 'Checked in'.(! empty($c['queue_position']) ? ", queue #{$c['queue_position']}" : '').'.',
            'encounter_started' => 'Consultation '.($c['encounter_number'] ?? '').' opened'.(! empty($c['doctor']) ? " by {$c['doctor']}" : '').'.',
            'encounter_finalized' => 'Consultation '.($c['encounter_number'] ?? '').' signed.',
            'encounter_addendum' => 'Addendum to '.($c['encounter_number'] ?? 'a consultation').'.',
            'encounter_amendment' => ($c['encounter_number'] ?? 'Consultation').' amended'.(! empty($c['section']) ? ' ('.str_replace('_', ' ', $c['section']).')' : '').'.',
            'prescription_issued' => ($c['prescription_number'] ?? 'Prescription').' with '.($c['item_count'] ?? 0).' medicine(s).',
            'prescription_cancelled' => ($c['prescription_number'] ?? 'Prescription').' cancelled'.(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'clinical_order_placed' => ($c['order_number'] ?? 'Order').': '.implode(', ', (array) ($c['items'] ?? [])),
            'investigation_resulted' => ($c['item_name'] ?? 'Investigation').' result entered'.(! empty($c['is_abnormal']) ? ' — abnormal' : '').'.',
            'investigation_reviewed' => ($c['item_name'] ?? 'Investigation').' reviewed'.(! empty($c['is_abnormal']) ? ' — abnormal' : '').'.',
            'clinical_order_completed' => 'All investigations on '.($c['order_number'] ?? 'the order').' complete.',
            'treatment_plan_proposed', 'treatment_plan_accepted', 'treatment_plan_completed', 'physio_plan_started', 'physio_plan_completed', 'physio_plan_discontinued' => (string) ($c['title'] ?? 'Plan'),
            'session_package_sold' => ($c['name'] ?? 'Package').' ('.($c['sessions'] ?? 0).' sessions).',
            'bill_issued' => 'Bill '.($c['billing_number'] ?? '').' for '.$money($c['final_amount'] ?? 0).'.',
            'bill_cancelled' => 'Bill '.($c['billing_number'] ?? '').' cancelled'.(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'payment_recorded' => $money($c['amount'] ?? 0).' received on '.($c['billing_number'] ?? 'a bill').(! empty($c['receipt_number']) ? " (receipt {$c['receipt_number']})" : '').'.',
            'refund_recorded' => $money($c['amount'] ?? 0).' refunded on '.($c['billing_number'] ?? 'a bill').'.',
            'document_uploaded' => (string) ($c['file_name'] ?? 'Document').' uploaded.',
            'demographics_updated' => 'Details updated'.(! empty($c['updated_fields']) ? ': '.implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), (array) $c['updated_fields'])) : '').'.',
            'doctor_transferred' => 'Assigned to '.($c['to_practitioner'] ?? 'another doctor').(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'treatment_plan_paused', 'treatment_plan_declined', 'treatment_plan_cancelled' => ($c['title'] ?? 'Plan').(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'treatment_plan_resumed' => ($c['title'] ?? 'Plan').' resumed.',
            'dental_chart_updated' => 'Teeth '.implode(', ', (array) ($c['teeth'] ?? [])).' charted in '.($c['encounter_number'] ?? 'a consultation').'.',
            'periodontal_exam_recorded' => 'Recorded in '.($c['encounter_number'] ?? 'a consultation').(isset($c['bop_percent']) ? ' · BOP '.$c['bop_percent'].'%' : '').(isset($c['sites_4mm_plus']) ? ' · '.$c['sites_4mm_plus'].' sites ≥4 mm' : '').'.',
            'physio_assessment_recorded' => (($c['kind'] ?? 'initial') === 'reassessment' ? 'Reassessment' : 'Initial assessment').' in '.($c['encounter_number'] ?? 'a session').(isset($c['pain_on_activity']) ? " · pain on activity {$c['pain_on_activity']}/10" : '').'.',
            'physio_session_recorded' => (isset($c['session_number']) ? "Session {$c['session_number']}" : 'Session').' in '.($c['encounter_number'] ?? 'a consultation').'.',
            'follow_up_added', 'follow_up_rescheduled' => 'Due '.($c['due_on'] ?? '').(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'follow_up_completed', 'follow_up_cancelled' => 'Follow-up due '.($c['due_on'] ?? '').(str_ends_with($event->event_type, 'completed') ? ' completed.' : ' cancelled.'),
            'message_queued' => (string) ($c['kind'] ?? 'Message').(! empty($c['channel']) ? " ({$c['channel']})" : '').'.',
            'endodontic_started', 'endodontic_status_changed', 'implant_started', 'implant_status_changed', 'orthodontic_started', 'orthodontic_status_changed' => ($c['label'] ?? 'Dental record').(! empty($c['status']) ? ' — '.str_replace('_', ' ', $c['status']) : '').(! empty($c['encounter_number']) ? " ({$c['encounter_number']})" : '').'.',
            'orthodontic_visit_recorded' => 'Adjustment in '.($c['encounter_number'] ?? 'a consultation').(! empty($c['phase']) ? " · {$c['phase']} phase" : '').'.',
            'lab_order_created', 'lab_case_send', 'lab_case_start', 'lab_case_receive', 'lab_case_remake', 'lab_case_fit', 'lab_case_complete', 'lab_case_cancel' => ($c['lab_order_number'] ?? 'Lab case').(! empty($c['restoration']) ? " · {$c['restoration']}" : '').(! empty($c['teeth']) ? ' · teeth '.implode(', ', (array) $c['teeth']) : '').(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'patient_archive_cleared' => 'Patient archiving was removed from OralCares; this record is a current patient again.',
            'patient_death_recorded' => 'Date of death '.($c['date_of_death'] ?? '').(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'patient_death_corrected' => 'The recorded death'.(! empty($c['previous_date_of_death']) ? " ({$c['previous_date_of_death']})" : '').' was corrected'.(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            'patient_archived', 'document_removed', 'encounter_voided', 'tooth_entry_voided', 'dental_record_voided' => ($c['label'] ?? 'Record').(! empty($c['reason']) ? " — {$c['reason']}" : '').'.',
            default => Str::headline($event->event_type).'.',
        };
    }

    private function sourceUrl(PatientTimelineEvent $event, Patient $patient, User $user): ?string
    {
        $c = $event->context ?? [];

        if (! empty($c['encounter_id']) && str_starts_with($event->event_type, 'encounter')) {
            $encounter = ClinicalEncounter::query()->find($c['encounter_id']);

            return $encounter && $user->can('view', $encounter) ? route('clinic.consultations.show', $encounter) : null;
        }
        if (! empty($c['order_id']) && $this->access->canViewClinical($user)) {
            return route('clinic.orders.show', $c['order_id']);
        }
        if (! empty($c['prescription_id'])) {
            $rx = Prescription::query()->find($c['prescription_id']);

            return $rx && $user->can('print', $rx) ? route('clinic.prescriptions.print', $rx) : null;
        }

        return null;
    }

    /** Event type → [category, title]. */
    private const TIMELINE = [
        'registered' => ['registration', 'Registered'],
        'demographics_updated' => ['record', 'Details updated'],
        'doctor_transferred' => ['record', 'Doctor reassigned'],
        'appointment_scheduled' => ['appointment', 'Appointment booked'],
        'appointment_confirmed' => ['appointment', 'Appointment confirmed'],
        'appointment_rescheduled' => ['appointment', 'Appointment moved'],
        'appointment_cancelled' => ['appointment', 'Appointment cancelled'],
        'appointment_checked_in' => ['check_in', 'Checked in'],
        'walk_in_registered' => ['check_in', 'Walk-in checked in'],
        'encounter_started' => ['consultation', 'Consultation started'],
        'encounter_finalized' => ['consultation', 'Consultation signed'],
        'encounter_addendum' => ['consultation', 'Addendum'],
        'encounter_amendment' => ['consultation', 'Consultation amended'],
        'prescription_issued' => ['prescription', 'Prescription issued'],
        'prescription_cancelled' => ['prescription', 'Prescription cancelled'],
        'clinical_order_placed' => ['investigation', 'Investigations ordered'],
        'investigation_resulted' => ['investigation', 'Result received'],
        'investigation_reviewed' => ['investigation', 'Result reviewed'],
        'clinical_order_completed' => ['investigation', 'Investigations complete'],
        'treatment_plan_proposed' => ['treatment', 'Treatment plan proposed'],
        'treatment_plan_accepted' => ['treatment', 'Treatment plan accepted'],
        'treatment_plan_completed' => ['treatment', 'Treatment plan completed'],
        'treatment_plan_paused' => ['treatment', 'Treatment plan paused'],
        'treatment_plan_resumed' => ['treatment', 'Treatment plan resumed'],
        'treatment_plan_declined' => ['treatment', 'Treatment plan declined'],
        'treatment_plan_cancelled' => ['treatment', 'Treatment plan cancelled'],
        'procedure_recorded' => ['treatment', 'Procedure recorded'],
        'procedure_status_updated' => ['treatment', 'Procedure updated'],
        'diagnosis_added' => ['consultation', 'Diagnosis added'],
        'diagnosis_status_updated' => ['consultation', 'Diagnosis updated'],
        'note_signed' => ['consultation', 'Clinical note signed'],
        'encounter_completed' => ['consultation', 'Consultation completed'],
        'visit_started' => ['check_in', 'Visit started'],
        'dental_chart_updated' => ['dental', 'Dental chart updated'],
        'periodontal_exam_recorded' => ['dental', 'Periodontal chart recorded'],
        'endodontic_started' => ['dental', 'Root canal treatment started'],
        'endodontic_status_changed' => ['dental', 'Root canal treatment updated'],
        'implant_started' => ['dental', 'Implant recorded'],
        'implant_status_changed' => ['dental', 'Implant updated'],
        'orthodontic_started' => ['dental', 'Orthodontic case opened'],
        'orthodontic_status_changed' => ['dental', 'Orthodontic case updated'],
        'orthodontic_visit_recorded' => ['dental', 'Orthodontic adjustment'],
        'dental_record_voided' => ['dental', 'Dental record voided'],
        'lab_order_created' => ['lab', 'Lab work ordered'],
        'lab_case_send' => ['lab', 'Sent to the lab'],
        'lab_case_start' => ['lab', 'In progress at the lab'],
        'lab_case_receive' => ['lab', 'Received from the lab'],
        'lab_case_remake' => ['lab', 'Sent back for remake'],
        'lab_case_fit' => ['lab', 'Lab work fitted'],
        'lab_case_complete' => ['lab', 'Lab case completed'],
        'lab_case_cancel' => ['lab', 'Lab case cancelled'],
        'physio_plan_started' => ['physio', 'Plan of care started'],
        'physio_plan_completed' => ['physio', 'Discharged — plan completed'],
        'physio_plan_discontinued' => ['physio', 'Plan of care discontinued'],
        'physio_assessment_recorded' => ['physio', 'Physio assessment'],
        'physio_session_recorded' => ['physio', 'Physio session'],
        'session_package_sold' => ['billing', 'Session package sold'],
        'bill_issued' => ['billing', 'Bill issued'],
        'bill_cancelled' => ['billing', 'Bill cancelled'],
        'payment_recorded' => ['billing', 'Payment received'],
        'refund_recorded' => ['billing', 'Refund issued'],
        'document_uploaded' => ['document', 'Document uploaded'],
        'follow_up_added' => ['follow_up', 'Follow-up added'],
        'follow_up_rescheduled' => ['follow_up', 'Follow-up moved'],
        'follow_up_completed' => ['follow_up', 'Follow-up completed'],
        'follow_up_cancelled' => ['follow_up', 'Follow-up cancelled'],
        'message_queued' => ['communication', 'Message to the patient'],
        // History from before patient archiving was removed (2026-10-25); kept so old timelines still read.
        'patient_archived' => ['governance', 'Patient archived'],
        'patient_archive_cleared' => ['governance', 'Returned to current patients'],
        'patient_death_recorded' => ['governance', 'Death recorded'],
        'patient_death_corrected' => ['governance', 'Death record corrected'],
        'document_removed' => ['governance', 'Document removed'],
        'encounter_voided' => ['consultation', 'Consultation voided'],
        'tooth_entry_voided' => ['dental', 'Chart entry voided'],
    ];

    private const DOCUMENT_TYPES = [
        'medical_report' => 'Medical report',
        'id_proof' => 'ID proof',
        'xray' => 'X-ray / imaging',
        'intraoral_photo' => 'Intraoral photo',
        'lab_report' => 'Lab report',
        'other' => 'Other',
    ];
}
