<?php

namespace App\Services\Patient\Patient360;

use App\BillingStatus;
use App\ClinicPermission;
use App\Models\AttachedDocument;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicalProcedure;
use App\Models\EndodonticRecord;
use App\Models\ImplantRecord;
use App\Models\LabOrder;
use App\Models\OrthodonticCase;
use App\Models\OrthodonticVisit;
use App\Models\Patient;
use App\Models\PatientBillingItem;
use App\Models\PatientDentalChart;
use App\Models\PatientFollowUp;
use App\Models\PatientProcedure;
use App\Models\PatientToothStatusHistory;
use App\Models\PatientTreatmentTooth;
use App\Models\PeriodontalExam;
use App\Models\PeriodontalTooth;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\User;
use App\Services\Dental\DentalContextFilter;
use App\Services\Dental\DentalRecord;
use App\Services\Dental\DentalSpecialtyRecords;
use App\Services\Dental\PeriodontalRecord;
use App\Services\Governance\DeletionGovernance;
use App\Services\LaboratoryWorkflowService;
use App\Services\Treatment\TreatmentReadModel;
use App\TreatmentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patient 360's dental workspace: the dentist's view of the mouth.
 *
 * Built from the structured record only — the chart (current state per
 * tooth), tooth history, treatment plans and their items, procedures,
 * imaging, lab work, periodontal exams and recalls. Nothing here is typed
 * in or inferred beyond counting what is recorded (DMFT is a count of the
 * chart). Writes go through the consultation the user has open.
 */
class Patient360DentalWorkspace
{
    /** Treatment-record states that are still to be done. */
    private const TO_DO = ['proposed', 'planned', 'started', 'paused', 'resumed'];

    public function __construct(
        protected Patient360Access $access,
        protected Patient360Summary $summary,
        protected DentalRecord $dental,
        protected PeriodontalRecord $periodontal,
        protected DeletionGovernance $governance,
        protected DentalContextFilter $context,
        protected Patient360SectionPolicy $policy,
        protected TreatmentReadModel $treatments,
    ) {}

    /**
     * The dental view this user is allowed: the clinician's full workspace,
     * the front desk's operational summary, or billing's financial one. The
     * policy decides; each view is built from scratch, so a restricted view
     * never carries clinical fields it then hides.
     *
     * @return array<string, mixed>
     */
    public function build(Patient $patient, User $user): array
    {
        return match ($this->policy->lens($user, $patient)['dental_view']) {
            'clinical' => $this->clinical($patient, $user),
            'operational' => $this->operational($patient, $user),
            'financial' => $this->financial($patient, $user),
            default => abort(403),
        };
    }

    /**
     * The front desk: which teeth are under treatment and where each stands,
     * the next dental appointment, lab cases in flight and follow-ups. No
     * findings, diagnoses, notes or clinical reasoning.
     *
     * @return array<string, mixed>
     */
    private function operational(Patient $patient, User $user): array
    {
        $r = $this->context->record($patient);
        $desk = $user->hasClinicPermission(ClinicPermission::QueueManage);

        return [
            'view' => 'operational',
            'ongoing' => collect($this->context->ongoing($patient))->map(fn (array $row) => collect($row)->only(['key', 'tooth', 'procedure', 'status', 'state', 'source'])->all())->values(),
            'states' => array_intersect_key($this->context->states($patient), array_flip($this->context->contexts($patient)['ongoing'])),
            'state_styles' => config('clinic_dental.states'),
            'next_appointment' => $this->summary->state($patient, $user)['next_appointment'],
            'lab' => $r['lab']->reject(fn (LabOrder $o) => $this->context->labStatus($o) === 'cancelled')->take(8)->map(fn (LabOrder $o) => [
                'id' => $o->id, 'number' => $o->lab_order_number, 'restoration' => $o->restoration, 'teeth' => array_map('intval', (array) $o->requested_teeth),
                'status' => $this->context->labStatus($o), 'status_label' => DentalContextFilter::labLabel($this->context->labStatus($o)),
                'due' => $o->delivery_due_date?->toDateString(), 'laboratory' => $o->laboratory_name,
                'steps' => $desk ? $this->labSteps($patient, $o, LaboratoryWorkflowService::DESK_STEPS) : [],
            ])->values(),
            'follow_ups' => PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->orderBy('due_on')->limit(5)->get()
                ->map(fn (PatientFollowUp $f) => ['id' => $f->id, 'due_on' => $f->due_on->toDateString(), 'status' => $f->status])->values(),
        ];
    }

    /**
     * Billing: each treatment by tooth and procedure with its estimate and
     * whether it is billed, and each plan's estimate. No findings or notes.
     *
     * @return array<string, mixed>
     */
    private function financial(Patient $patient, User $user): array
    {
        $r = $this->context->record($patient);
        $billed = $this->billedProcedures($r['plans']->flatMap->records->pluck('patient_procedure_id')->filter()->all());
        $plans = $r['plans']->reject(fn (TreatmentPlan $plan) => in_array($plan->status, [TreatmentPlan::DECLINED, TreatmentPlan::CANCELLED], true));

        return [
            'view' => 'financial',
            'ongoing' => collect($this->context->ongoing($patient, ['plan']))
                ->map(fn (array $row) => collect($row)->only(['key', 'tooth', 'procedure', 'status', 'state', 'price'])->all())->values(),
            'states' => array_intersect_key($this->context->states($patient), array_flip($this->context->contexts($patient)['ongoing'])),
            'state_styles' => config('clinic_dental.states'),
            'plans' => $plans->map(function (TreatmentPlan $plan) use ($billed) {
                $live = $plan->records->reject(fn (TreatmentRecord $item) => $item->status === TreatmentStatus::Aborted);
                $done = $live->filter(fn (TreatmentRecord $item) => $item->status === TreatmentStatus::Completed);

                return [
                    'id' => $plan->id, 'title' => $plan->title, 'status' => $plan->status,
                    'estimate' => ['total' => round((float) $live->sum(fn ($i) => (float) $i->price), 2), 'done' => round((float) $done->sum(fn ($i) => (float) $i->price), 2), 'priced' => $live->every(fn ($i) => $i->price !== null)],
                    'items' => $live->map(fn (TreatmentRecord $item) => [
                        'id' => $item->id, 'tooth' => $item->tooth_number ? (int) $item->tooth_number : null, 'procedure' => preg_replace('/ — .*/', '', (string) $item->description),
                        'status' => $item->status?->value, 'price' => $item->price !== null ? (float) $item->price : null,
                        'billed' => $item->patient_procedure_id !== null && in_array((int) $item->patient_procedure_id, $billed, true),
                    ])->values(),
                ];
            })->values(),
            'outstanding' => $this->summary->state($patient, $user)['outstanding'],
        ];
    }

    /**
     * The dentist's full workspace.
     *
     * @return array<string, mixed>
     */
    private function clinical(Patient $patient, User $user): array
    {
        $open = $this->access->openConsultation($user, $patient);
        $canChart = $open && $user->can('writeExtension', [$open, 'odontogram']);
        // One read of the structured record, shared with the context filter.
        $chart = $this->context->record($patient)['chart'];
        $plans = $this->context->record($patient)['plans'];
        $billed = $this->billedProcedures($plans->flatMap->records->pluck('patient_procedure_id')->filter()->all());

        $planned = $plans->reject(fn (TreatmentPlan $plan) => in_array($plan->status, TreatmentPlan::CLOSED, true))
            ->flatMap->records->filter(fn (TreatmentRecord $item) => $item->tooth_number && in_array($item->status?->value, self::TO_DO, true))
            ->pluck('tooth_number')->map(fn ($tooth) => (int) $tooth)->unique()->all();
        $completed = PatientToothStatusHistory::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', PatientToothStatusHistory::TREATMENT)->whereNull('voided_at')->distinct()->pluck('tooth_number')->map(fn ($tooth) => (int) $tooth)->all();
        $newInOpen = $open ? PatientToothStatusHistory::query()->where('clinic_id', $patient->clinic_id)->where('encounter_id', $open->id)
            ->whereNull('voided_at')->distinct()->pluck('tooth_number')->map(fn ($tooth) => (int) $tooth)->all() : [];

        $exams = $this->periodontal->history($patient, 8);
        $latest = $exams->first()?->load('teeth');

        $r = $this->context->record($patient);
        $states = $this->context->states($patient);
        $ongoing = $this->context->ongoing($patient);
        // The last consultation with dental work in it: a chart entry or a tooth-level procedure.
        $lastVisit = ClinicalEncounter::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->whereNull('voided_at')
            ->where(fn ($q) => $q->whereIn('id', PatientToothStatusHistory::query()->select('encounter_id')->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id))
                ->orWhereIn('id', PatientProcedure::query()->select('encounter_id')->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->whereNotNull('details->tooth_number')))
            ->latest('started_at')->value('started_at');
        $state = $this->summary->state($patient, $user);

        return [
            'view' => 'clinical',
            'focus' => $this->policy->lens($user, $patient)['dental_focus'],
            'states' => $states,
            'state_styles' => config('clinic_dental.states'),
            'ongoing' => $ongoing,
            'contexts' => $this->context->contexts($patient),
            'overview' => [
                'active' => collect($ongoing)->where('status', 'in_progress')->count(),
                'planned' => collect($ongoing)->where('status', 'planned')->count(),
                'completed' => collect($ongoing)->where('status', 'completed')->count(),
                'next_appointment' => $state['next_appointment'],
                'last_dental_visit' => $lastVisit ? Carbon::parse($lastVisit)->toIso8601String() : null,
                'primary_dentist' => $patient->currentPractitioner?->name,
                'outstanding' => $state['outstanding'],
                'alerts' => array_values(array_filter([
                    ...collect($states)->filter(fn ($s) => $s === 'urgent')->keys()->map(fn ($t) => "Urgent finding on tooth {$t}")->all(),
                    ...$r['lab']->filter(fn (LabOrder $o) => $o->delivery_due_date && $o->delivery_due_date->isPast() && in_array($this->context->labStatus($o), ['requested', 'accepted', 'in_production', 'quality_check', 'ready_for_delivery'], true))
                        ->map(fn (LabOrder $o) => "Lab case {$o->lab_order_number} is overdue")->all(),
                ])),
            ],
            'endodontic' => $r['endo']->map(fn (EndodonticRecord $e) => $this->endo($e, $canChart ? $open : null, $user))->values(),
            'implants' => $r['implants']->map(fn (ImplantRecord $i) => $this->implant($i, $canChart ? $open : null, $user))->values(),
            'orthodontic' => $r['ortho'] ? $this->ortho($r['ortho'], $canChart ? $open : null, $user) : null,
            'specialty_links' => $canChart ? [
                'endodontic' => route('clinic.consultations.dental.endodontic.store', $open),
                'implant' => route('clinic.consultations.dental.implants.store', $open),
                'orthodontic' => $r['ortho']?->status === 'active' ? null : route('clinic.consultations.dental.orthodontic.store', $open),
                'lab' => route('clinic.consultations.dental.lab.store', $open),
            ] : null,
            'vocabulary' => [
                'pulp' => DentalSpecialtyRecords::PULP, 'periapical' => DentalSpecialtyRecords::PERIAPICAL,
                'endo_stages' => EndodonticRecord::STAGES, 'endo_statuses' => EndodonticRecord::STATUSES,
                'implant_statuses' => ImplantRecord::STATUSES, 'integration' => ImplantRecord::INTEGRATION, 'crown' => ImplantRecord::CROWN,
                'appliances' => OrthodonticCase::APPLIANCES, 'phases' => OrthodonticCase::PHASES,
            ],
            'images' => AttachedDocument::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->where(fn ($q) => $q->whereNotNull('tooth_numbers')->orWhere('document_type', 'xray'))
                ->latest('id')->limit(20)->get()->map(fn (AttachedDocument $d) => [
                    'id' => $d->id, 'name' => $d->file_name, 'teeth' => array_map('intval', $d->tooth_numbers ?? []), 'date' => $d->created_at?->toIso8601String(),
                    'preview_url' => $d->isCleared() ? route('clinic.patients.documents.download', [$patient->id, $d->id, 'inline' => 1]) : null,
                ])->values(),
            'summary' => $this->summaryOf($chart, $plans, $latest),
            'teeth' => $chart->map(fn (PatientDentalChart $tooth) => [
                'tooth' => $tooth->tooth_number,
                'status' => $tooth->status,
                'category' => config("clinic_dental.conditions.{$tooth->status}.category"),
                'surfaces' => $tooth->surfaces ?? [],
                'notes' => $tooth->notes,
                'updated_at' => $tooth->updated_at?->toIso8601String(),
                'planned' => in_array($tooth->tooth_number, $planned, true),
                'completed' => in_array($tooth->tooth_number, $completed, true),
                'new' => in_array($tooth->tooth_number, $newInOpen, true),
            ])->values(),
            // Teeth with planned or completed treatment but nothing charted.
            'overlays' => ['planned' => array_values($planned), 'completed' => array_values($completed), 'new' => array_values($newInOpen)],
            'conditions' => config('clinic_dental.conditions'),
            'categories' => config('clinic_dental.categories'),
            'overlay_styles' => config('clinic_dental.overlays'),
            'surfaces' => config('clinic_dental.surfaces'),
            'plans' => $plans->map(fn (TreatmentPlan $plan) => $this->plan($patient, $plan, $user, $canChart ? $open : null, $billed))->values(),
            'procedures' => $this->procedures($patient),
            'imaging' => $this->imaging($patient),
            'lab' => $r['lab']->map(fn (LabOrder $order) => [
                'id' => $order->id, 'number' => $order->lab_order_number, 'lab' => $order->laboratory_name,
                'status' => $this->context->labStatus($order), 'status_label' => DentalContextFilter::labLabel($this->context->labStatus($order)),
                'teeth' => array_map('intval', (array) $order->requested_teeth), 'restoration' => $order->restoration, 'shade' => $order->shade, 'material' => $order->material,
                'due' => $order->delivery_due_date?->toDateString(), 'notes' => $order->notes, 'date' => $order->created_at?->toIso8601String(),
                'sent_at' => $order->sent_at?->toIso8601String(), 'received_at' => $order->received_at?->toIso8601String(), 'fitted_at' => $order->fitted_at?->toIso8601String(),
                'cancellation_reason' => $order->cancellation_reason,
                'steps' => $this->labSteps($patient, $order, array_keys(LaboratoryWorkflowService::STEPS)),
            ])->values(),
            'periodontal' => [
                'latest' => $latest ? $this->exam($latest, true) : null,
                'history' => $exams->map(fn (PeriodontalExam $exam) => $this->exam($exam, false))->values(),
                'record_url' => $canChart ? route('clinic.consultations.dental.periodontal', $open) : null,
                'vocabulary' => config('clinic_dental.periodontal'),
            ],
            'recall' => [
                'open' => PatientFollowUp::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                    ->whereIn('status', [PatientFollowUp::DUE, PatientFollowUp::BOOKED])->with('practitioner:id,name')->orderBy('due_on')->limit(5)->get()
                    ->map(fn (PatientFollowUp $followUp) => ['id' => $followUp->id, 'due_on' => $followUp->due_on->toDateString(), 'reason' => $followUp->reason, 'practitioner' => $followUp->practitioner?->name, 'status' => $followUp->status])->values(),
                'add_url' => $this->access->canManageFollowUps($user) ? route('clinic.patients.follow-ups.store', $patient->id) : null,
            ],
            'open_consultation' => $open ? ['id' => $open->id, 'number' => $open->encounter_number, 'url' => route('clinic.consultations.show', $open)] : null,
            'links' => [
                'tooth' => route('clinic.patients.360.dental.tooth', [$patient->id, 0]),
                'chart' => $canChart ? route('clinic.consultations.dental.chart', $open) : null,
                'plans' => $canChart ? route('clinic.consultations.dental.plans.store', $open) : null,
            ],
            'services' => $canChart ? ClinicalProcedure::query()->where('clinic_id', $patient->clinic_id)->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name', 'category'])->values() : [],
            'governance' => ['tooth_entry' => $this->governance->modeFor($user, 'tooth_entry.void')],
        ];
    }

    /**
     * One tooth in full: its state, every entry (void ones marked), what is
     * planned and done for it, imaging that names it, and its latest
     * periodontal measurements.
     *
     * @return array<string, mixed>
     */
    public function tooth(Patient $patient, User $user, int $tooth): array
    {
        $current = $this->dental->chart($patient)->get($tooth);
        $mode = $this->governance->modeFor($user, 'tooth_entry.void');
        $planned = TreatmentRecord::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('tooth_number', $tooth)
            ->whereHas('plan', fn ($plan) => $plan->whereNotIn('status', TreatmentPlan::CLOSED))
            ->with('plan:id,title,status')->orderBy('id')->get();
        $perio = PeriodontalTooth::query()->where('tooth_number', $tooth)
            ->whereHas('exam', fn ($exam) => $exam->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id))
            ->with('exam:id,created_at')->latest('id')->first();

        return [
            'tooth' => $tooth,
            'current' => $current ? [
                'status' => $current->status, 'category' => config("clinic_dental.conditions.{$current->status}.category"),
                'surfaces' => $current->surfaces ?? [], 'notes' => $current->notes, 'updated_at' => $current->updated_at?->toIso8601String(),
            ] : null,
            'history' => $this->dental->toothHistory($patient, $tooth)->map(fn (PatientToothStatusHistory $entry) => [
                'id' => $entry->id,
                'kind' => $entry->kind,
                'status' => $entry->status,
                'category' => config("clinic_dental.conditions.{$entry->status}.category"),
                'previous_status' => $entry->previous_status,
                'surfaces' => $entry->surfaces ?? [],
                'notes' => $entry->notes,
                'doctor' => $entry->doctor?->name,
                'at' => $entry->created_at?->toIso8601String(),
                'voided' => $entry->voided_at !== null,
                'void_reason' => $entry->void_reason,
                'void_mode' => $entry->voided_at === null && $entry->kind === PatientToothStatusHistory::FINDING ? $mode : null,
            ])->values(),
            // Treatments (treatment engine) naming this tooth; `planned` below is the legacy proposal lines, read-only.
            'treatments' => $this->treatments->forPatient($patient->clinic_id, $patient->id, $this->access->branchIds(), false, $this->access->canViewBilling($user), 50,
                PatientTreatmentTooth::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('tooth_number', $tooth)
                    ->whereIn('patient_treatment_id', fn ($q) => $q->select('id')->from('patient_treatments')->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id))
                    ->pluck('patient_treatment_id')->map(fn ($id) => (int) $id)->unique()->values()->all()),
            'planned' => $planned->filter(fn (TreatmentRecord $item) => in_array($item->status?->value, self::TO_DO, true))->map(fn (TreatmentRecord $item) => [
                'id' => $item->id, 'description' => $item->description, 'surfaces' => $item->surfaces ?? [], 'status' => $item->status?->value,
                'plan' => $item->plan?->title, 'plan_status' => $item->plan?->status, 'price' => $item->price !== null ? (float) $item->price : null,
            ])->values(),
            'completed' => PatientProcedure::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->where('details->tooth_number', $tooth)
                ->whereHas('encounter', fn ($query) => $this->summary->inBranches($query))
                ->latest('performed_at')->limit(20)->get()->map(fn (PatientProcedure $procedure) => [
                    'name' => $procedure->name, 'surfaces' => $procedure->details['surfaces'] ?? [],
                    'date' => ($procedure->performed_at ?? $procedure->created_at)?->toIso8601String(),
                ])->values(),
            'imaging' => $this->imaging($patient)->filter(fn (array $study) => in_array($tooth, $study['teeth'], true))->values(),
            'images' => AttachedDocument::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
                ->whereJsonContains('tooth_numbers', $tooth)->latest('id')->limit(12)->get()
                ->map(fn (AttachedDocument $d) => ['id' => $d->id, 'name' => $d->file_name, 'date' => $d->created_at?->toIso8601String(), 'preview_url' => $d->isCleared() ? route('clinic.patients.documents.download', [$patient->id, $d->id, 'inline' => 1]) : null])->values(),
            'specialty' => [
                'endodontic' => $this->context->record($patient)['endo']->where('tooth_number', $tooth)->map(fn (EndodonticRecord $e) => ['id' => $e->id, 'status' => $e->status, 'stage' => $e->stage, 'diagnosis' => $e->diagnosis, 'started_at' => $e->created_at?->toIso8601String()])->values(),
                'implant' => $this->context->record($patient)['implants']->where('tooth_number', $tooth)->map(fn (ImplantRecord $i) => ['id' => $i->id, 'status' => $i->status, 'system' => $i->system, 'integration_status' => $i->integration_status, 'crown_status' => $i->crown_status])->values(),
            ],
            'state' => $this->context->states($patient)[$tooth] ?? 'healthy',
            'periodontal' => $perio ? [
                'date' => $perio->exam?->created_at?->toIso8601String(),
                'missing' => $perio->missing,
                'probing_depths' => $perio->probing_depths, 'gingival_margins' => $perio->gingival_margins,
                'bleeding' => $perio->bleeding, 'plaque' => $perio->plaque,
                'mobility' => $perio->mobility, 'furcation' => $perio->furcation,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endo(EndodonticRecord $e, ?ClinicalEncounter $open, User $user): array
    {
        return [
            ...$e->only(['id', 'tooth_number', 'status', 'stage', 'pulp_status', 'periapical_status', 'diagnosis', 'instrumentation_notes', 'obturation_technique', 'obturation_material', 'temporary_restoration', 'permanent_restoration', 'notes']),
            'canals' => $e->canals ?? [],
            'obturated_on' => $e->obturated_on?->toDateString(),
            'review_on' => $e->review_on?->toDateString(),
            'by' => $e->author?->name,
            'started_at' => $e->created_at?->toIso8601String(),
            'updated_at' => $e->updated_at?->toIso8601String(),
            'update_url' => $open ? route('clinic.consultations.dental.endodontic.update', [$open, $e->id]) : null,
            'void_mode' => $this->governance->modeFor($user, 'endodontic.void'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function implant(ImplantRecord $i, ?ClinicalEncounter $open, User $user): array
    {
        return [
            ...$i->only(['id', 'tooth_number', 'status', 'system', 'diameter_mm', 'length_mm', 'lot_number', 'insertion_torque_ncm', 'integration_status', 'abutment', 'crown_status', 'complications', 'notes']),
            'placed_on' => $i->placed_on?->toDateString(),
            'abutment_on' => $i->abutment_on?->toDateString(),
            'crown_on' => $i->crown_on?->toDateString(),
            'review_on' => $i->review_on?->toDateString(),
            'by' => $i->author?->name,
            'started_at' => $i->created_at?->toIso8601String(),
            'update_url' => $open ? route('clinic.consultations.dental.implants.update', [$open, $i->id]) : null,
            'void_mode' => $this->governance->modeFor($user, 'implant.void'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ortho(OrthodonticCase $c, ?ClinicalEncounter $open, User $user): array
    {
        return [
            ...$c->only(['id', 'status', 'arches', 'appliance', 'bracket_system', 'phase', 'diagnosis', 'estimated_months', 'notes']),
            'appliance_label' => OrthodonticCase::APPLIANCES[$c->appliance] ?? $c->appliance,
            'bracketed_teeth' => $c->bracketed_teeth ?? [],
            'started_on' => $c->started_on?->toDateString(),
            'months_in' => $c->started_on ? (int) $c->started_on->diffInMonths(now()) : null,
            'by' => $c->author?->name,
            'visits' => $c->visits->map(fn (OrthodonticVisit $v) => [
                ...$v->only(['id', 'phase', 'upper_wire', 'lower_wire', 'adjustments', 'elastics', 'notes', 'next_visit_weeks']),
                'date' => $v->created_at?->toIso8601String(), 'by' => $v->recorder?->name,
            ])->values(),
            'update_url' => $open ? route('clinic.consultations.dental.orthodontic.update', [$open, $c->id]) : null,
            'visit_url' => $open && $c->status === 'active' ? route('clinic.consultations.dental.orthodontic.visit', [$open, $c->id]) : null,
            'void_mode' => $this->governance->modeFor($user, 'orthodontic.void'),
        ];
    }

    /**
     * Lab steps this user may take on this case now.
     *
     * @param  list<string>  $allowedSteps
     * @return list<array{step: string, url: string}>
     */
    private function labSteps(Patient $patient, LabOrder $order, array $allowedSteps): array
    {
        $status = $this->context->labStatus($order);

        return collect(LaboratoryWorkflowService::STEPS)
            ->filter(fn (array $rule, string $step) => in_array($step, $allowedSteps, true) && in_array($status, $rule['from'], true))
            ->map(fn (array $rule, string $step) => ['step' => $step, 'url' => route('clinic.patients.dental.lab.step', [$patient->id, $order->id, $step])])
            ->values()->all();
    }

    /**
     * Counts from the chart. DMFT is for permanent teeth: decayed (caries),
     * missing, filled (restored or crowned).
     *
     * @param  Collection<int, PatientDentalChart>  $chart
     * @param  Collection<int, TreatmentPlan>  $plans
     * @return array<string, mixed>
     */
    private function summaryOf(Collection $chart, Collection $plans, ?PeriodontalExam $latest): array
    {
        $category = fn (PatientDentalChart $tooth) => config("clinic_dental.conditions.{$tooth->status}.category");
        $permanent = $chart->filter(fn (PatientDentalChart $tooth) => $tooth->tooth_number < 50);
        $count = fn (Collection $teeth, array $categories) => $teeth->filter(fn (PatientDentalChart $tooth) => in_array($category($tooth), $categories, true))->count();
        $open = $plans->reject(fn (TreatmentPlan $plan) => in_array($plan->status, TreatmentPlan::CLOSED, true));
        $toDo = $open->flatMap->records->filter(fn (TreatmentRecord $item) => in_array($item->status?->value, self::TO_DO, true));

        $decayed = $count($permanent, ['pathology']);
        $missing = $count($permanent, ['missing']);
        $filled = $count($permanent, ['restoration', 'prosthetic']);

        return [
            'charted' => $chart->filter(fn (PatientDentalChart $tooth) => $tooth->status !== 'sound')->count(),
            'counts' => collect(array_keys(config('clinic_dental.categories')))->mapWithKeys(fn (string $key) => [$key => $count($chart, [$key])])->all(),
            'dmft' => $chart->isEmpty() ? null : ['decayed' => $decayed, 'missing' => $missing, 'filled' => $filled, 'total' => $decayed + $missing + $filled],
            'open_plans' => $open->count(),
            'items_to_do' => $toDo->count(),
            'estimate_to_do' => round((float) $toDo->sum(fn (TreatmentRecord $item) => (float) $item->price), 2),
            'periodontal' => $latest ? ['bop_percent' => $latest->bop_percent, 'sites_4mm_plus' => $latest->sites_4mm_plus, 'stage' => $latest->stage, 'grade' => $latest->grade, 'date' => $latest->created_at?->toIso8601String()] : null,
        ];
    }

    /**
     * @param  list<int>  $billed
     * @return array<string, mixed>
     */
    private function plan(Patient $patient, TreatmentPlan $plan, User $user, ?ClinicalEncounter $open, array $billed): array
    {
        $live = $plan->records->reject(fn (TreatmentRecord $item) => $item->status === TreatmentStatus::Aborted);
        $write = $user->hasClinicPermission(ClinicPermission::ClinicalEdit) && $user->can('writeClinicalData', $patient);
        $transition = fn (string $name, array $from) => $write && in_array($plan->status, $from, true)
            ? route('clinic.patients.dental.plans.transition', [$patient->id, $plan->id, $name]) : null;

        return [
            'id' => $plan->id,
            'title' => $plan->title,
            'status' => $plan->status,
            'status_reason' => $plan->status_reason,
            'author' => $plan->author?->name,
            'created_at' => $plan->created_at?->toIso8601String(),
            'accepted_at' => $plan->accepted_at?->toIso8601String(),
            'done' => $live->filter(fn (TreatmentRecord $item) => $item->status === TreatmentStatus::Completed)->count(),
            'total' => $live->count(),
            'estimate' => [
                'total' => round((float) $live->sum(fn (TreatmentRecord $item) => (float) $item->price), 2),
                'done' => round((float) $live->filter(fn (TreatmentRecord $item) => $item->status === TreatmentStatus::Completed)->sum(fn (TreatmentRecord $item) => (float) $item->price), 2),
                'priced' => $live->every(fn (TreatmentRecord $item) => $item->price !== null),
            ],
            'actions' => [
                // The estimate the patient takes home: for whoever reads the plan (the server decides again).
                'estimate_url' => route('clinic.patients.dental.plans.estimate', [$patient->id, $plan->id]),
                'accept_url' => $transition('accept', [TreatmentPlan::PROPOSED]),
                'pause_url' => $transition('pause', TreatmentPlan::WORKABLE),
                'resume_url' => $transition('resume', [TreatmentPlan::PAUSED]),
                'decline_url' => $transition('decline', [TreatmentPlan::DRAFT, TreatmentPlan::PROPOSED]),
                'cancel_url' => $transition('cancel', [TreatmentPlan::DRAFT, TreatmentPlan::PROPOSED, ...TreatmentPlan::WORKABLE, TreatmentPlan::PAUSED]),
            ],
            'items' => $plan->records->map(fn (TreatmentRecord $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'tooth' => $item->tooth_number,
                'surfaces' => $item->surfaces ?? [],
                'status' => $item->status?->value,
                'price' => $item->price !== null ? (float) $item->price : null,
                'completed_at' => $item->completed_at?->toIso8601String(),
                'billed' => $item->patient_procedure_id !== null && in_array((int) $item->patient_procedure_id, $billed, true),
                'complete_url' => $open && in_array($plan->status, TreatmentPlan::WORKABLE, true) && in_array($item->status?->value, ['proposed', 'planned'], true)
                    ? route('clinic.consultations.dental.plans.items.complete', [$open, $plan, $item]) : null,
            ])->values(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function procedures(Patient $patient): Collection
    {
        $procedures = PatientProcedure::query()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->whereNotNull('details->tooth_number')
            ->whereHas('encounter', fn ($query) => $this->summary->inBranches($query))
            ->with('encounter:id,encounter_number')->latest('performed_at')->limit(30)->get();
        $billed = $this->billedProcedures($procedures->pluck('id')->all());

        return $procedures->map(fn (PatientProcedure $procedure) => [
            'name' => $procedure->name, 'tooth' => $procedure->details['tooth_number'] ?? null,
            'surfaces' => $procedure->details['surfaces'] ?? [], 'date' => ($procedure->performed_at ?? $procedure->created_at)?->toIso8601String(),
            'consultation' => $procedure->encounter?->encounter_number, 'billed' => in_array($procedure->id, $billed, true),
        ])->values();
    }

    /**
     * Imaging studies, with the teeth their site names (e.g. "IOPA 36, 37").
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function imaging(Patient $patient): Collection
    {
        return $this->summary->orderItems($patient)->where('category', 'imaging')->with('result:id,clinical_order_item_id,summary,is_abnormal')
            ->latest('id')->limit(20)->get()->map(function (ClinicalOrderItem $item) {
                preg_match_all('/\b([1-8][1-8])\b/', (string) $item->body_site.' '.$item->item_name, $matches);

                return [
                    'name' => $item->item_name, 'site' => $item->body_site, 'status' => $item->status,
                    'summary' => $item->result?->summary, 'is_abnormal' => (bool) $item->result?->is_abnormal,
                    'date' => $item->created_at?->toIso8601String(), 'url' => route('clinic.orders.show', $item->clinical_order_id),
                    'teeth' => array_values(array_unique(array_map('intval', $matches[1]))),
                ];
            })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function exam(PeriodontalExam $exam, bool $withTeeth): array
    {
        return [
            'id' => $exam->id,
            'date' => $exam->created_at?->toIso8601String(),
            'examiner' => $exam->examiner?->name,
            'consultation' => $exam->encounter?->encounter_number,
            'teeth_examined' => $exam->teeth_examined,
            'bop_percent' => $exam->bop_percent,
            'plaque_percent' => $exam->plaque_percent,
            'mean_probing_depth' => $exam->mean_probing_depth,
            'sites_4mm_plus' => $exam->sites_4mm_plus,
            'sites_6mm_plus' => $exam->sites_6mm_plus,
            'stage' => $exam->stage,
            'grade' => $exam->grade,
            'extent' => $exam->extent,
            'diagnosis' => $exam->diagnosis,
            'notes' => $withTeeth ? $exam->notes : null,
            'teeth' => $withTeeth ? $exam->teeth->map(fn (PeriodontalTooth $tooth) => [
                'tooth' => $tooth->tooth_number, 'missing' => $tooth->missing,
                'probing_depths' => $tooth->probing_depths, 'gingival_margins' => $tooth->gingival_margins,
                'bleeding' => $tooth->bleeding, 'plaque' => $tooth->plaque, 'mobility' => $tooth->mobility, 'furcation' => $tooth->furcation,
            ])->values() : [],
        ];
    }

    /**
     * Which of these procedures appear on a live bill.
     *
     * @param  array<int, int|string>  $procedureIds
     * @return list<int>
     */
    private function billedProcedures(array $procedureIds): array
    {
        if ($procedureIds === []) {
            return [];
        }

        return PatientBillingItem::query()->where('source_type', (new PatientProcedure)->getMorphClass())
            ->whereIn('source_id', $procedureIds)
            ->whereHas('billing', fn ($bill) => $bill->where('status', '!=', BillingStatus::Cancelled->value))
            ->pluck('source_id')->map(fn ($id) => (int) $id)->all();
    }
}
