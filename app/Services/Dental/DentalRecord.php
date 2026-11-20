<?php

namespace App\Services\Dental;

use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientDentalChart;
use App\Models\PatientTimelineEvent;
use App\Models\PatientToothStatusHistory;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\DentalChartService;
use App\Services\Patient\PatientAutomation;
use App\Services\Patient\PatientCareGuard;
use App\Services\ProcedureCatalogService;
use App\Services\Treatment\LegacyTreatmentMirror;
use App\TreatmentStatus;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The dental extension's writer (registry extension `odontogram`), on
 * Patient → Encounter:
 *
 * - the chart: each tooth's current condition and surfaces, changed only by
 *   charting a finding in an open consultation (every change is kept in the
 *   tooth's history);
 * - treatment plans: proposed in a consultation, accepted, then worked
 *   through. Completing an item records the procedure in the consultation
 *   where it was done (which billing reads) and writes the tooth's history.
 *
 * Authorisation is the caller's; this class enforces ownership, state and
 * the FDI vocabulary in `config/clinic_dental.php`.
 */
class DentalRecord
{
    public function __construct(
        protected DentalChartService $fdi,
        protected EncounterWorkspace $workspace,
        protected LegacyTreatmentMirror $mirror,
        protected ProcedureCatalogService $catalogue,
    ) {}

    /**
     * The patient's chart, keyed by tooth.
     *
     * @return Collection<int, PatientDentalChart>
     */
    public function chart(Patient $patient): Collection
    {
        return PatientDentalChart::query()->withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->orderBy('tooth_number')->get()->keyBy('tooth_number');
    }

    /**
     * @return Collection<int, PatientToothStatusHistory>
     */
    public function toothHistory(Patient $patient, int $tooth): Collection
    {
        return PatientToothStatusHistory::query()->withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('tooth_number', $tooth)
            ->with('doctor:id,name')->latest('id')->get();
    }

    /**
     * Chart a finding on a tooth in this consultation.
     *
     * @param  list<string>  $surfaces
     */
    public function recordFinding(ClinicalEncounter $encounter, int $tooth, string $condition, array $surfaces, ?string $note, ClinicEmployee $doctor): PatientToothStatusHistory
    {
        $surfaces = $this->validate($tooth, $condition, $surfaces);

        return DB::transaction(function () use ($encounter, $tooth, $condition, $surfaces, $note, $doctor) {
            $encounter = $this->lockOpen($encounter);

            $history = $this->chartTooth($encounter, $tooth, $condition, $surfaces, $note, $doctor, PatientToothStatusHistory::FINDING);
            $this->chartTimeline($encounter, $tooth);

            return $history;
        });
    }

    /**
     * One timeline entry per consultation's charting, listing the teeth
     * charted, rather than one entry per tooth.
     */
    private function chartTimeline(ClinicalEncounter $encounter, int $tooth): void
    {
        $event = PatientTimelineEvent::query()->withoutGlobalScopes()
            ->where('clinic_id', $encounter->clinic_id)->where('patient_id', $encounter->patient_id)
            ->where('event_type', 'dental_chart_updated')->where('context->encounter_id', $encounter->id)
            ->lockForUpdate()->first();

        if ($event) {
            $context = $event->context;
            $context['teeth'] = array_values(array_unique([...($context['teeth'] ?? []), $tooth]));
            sort($context['teeth']);
            $event->forceFill(['context' => $context])->save();

            return;
        }

        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($encounter->clinic_id)->value('organization_id'),
            'clinic_id' => $encounter->clinic_id,
            'patient_id' => $encounter->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => 'dental_chart_updated',
            'context' => ['encounter_id' => $encounter->id, 'encounter_number' => $encounter->encounter_number, 'teeth' => [$tooth]],
        ]);
    }

    /**
     * @param  list<array{tooth_number?: ?int, surfaces?: list<string>, clinical_procedure_id: int, notes?: ?string}>  $items
     */
    public function proposePlan(ClinicalEncounter $encounter, string $title, array $items, ?string $notes, ClinicEmployee $doctor): TreatmentPlan
    {
        if ($items === []) {
            throw new InvalidArgumentException('Add at least one treatment to the plan.');
        }
        app(PatientCareGuard::class)->assertEncounterCanTakeNewCare($encounter);

        return DB::transaction(function () use ($encounter, $title, $items, $notes, $doctor) {
            $encounter = $this->lockOpen($encounter);

            $plan = TreatmentPlan::query()->create([
                'clinic_id' => $encounter->clinic_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->id,
                'title' => trim($title) === '' ? 'Treatment plan' : trim($title),
                'notes' => $notes,
                'status' => TreatmentPlan::PROPOSED,
                'created_by' => $doctor->id,
            ]);

            foreach (array_values($items) as $index => $item) {
                $this->addItem($plan, $item, $index + 1);
            }

            $this->timeline($plan, 'treatment_plan_proposed', ['item_count' => count($items)]);

            return $this->mirrored($plan);
        });
    }

    public function acceptPlan(TreatmentPlan $plan): TreatmentPlan
    {
        return DB::transaction(function () use ($plan) {
            $plan = $this->lockPlan($plan);
            if ($plan->status !== TreatmentPlan::PROPOSED) {
                throw new DomainException('Only a proposed plan can be accepted.');
            }

            $plan->update(['status' => TreatmentPlan::ACCEPTED, 'accepted_at' => now()]);
            $plan->records()->where('status', TreatmentStatus::Proposed->value)->update(['status' => TreatmentStatus::Planned->value]);
            $this->timeline($plan, 'treatment_plan_accepted', []);

            return $this->mirrored($plan);
        });
    }

    /**
     * Do a planned item in this consultation: the procedure is recorded here
     * (and billed from here), and the tooth takes its new condition.
     *
     * @param  list<string>  $surfaces
     */
    public function completeItem(TreatmentPlan $plan, TreatmentRecord $item, ClinicalEncounter $encounter, ClinicEmployee $doctor, ?string $resultingCondition = null, ?string $note = null): TreatmentRecord
    {
        return DB::transaction(function () use ($plan, $item, $encounter, $doctor, $resultingCondition, $note) {
            $plan = $this->lockPlan($plan);
            $encounter = $this->lockOpen($encounter);

            if ($item->treatment_plan_id !== $plan->id) {
                throw new InvalidArgumentException('That treatment belongs to a different plan.');
            }
            if ($encounter->patient_id !== $plan->patient_id) {
                throw new InvalidArgumentException('The consultation is for a different patient.');
            }
            if (! in_array($plan->status, TreatmentPlan::WORKABLE, true)) {
                throw new DomainException($plan->status === TreatmentPlan::PAUSED ? 'This plan is paused. Resume it first.' : 'Treatment is done from an accepted plan.');
            }
            if (in_array($item->status, [TreatmentStatus::Completed, TreatmentStatus::Aborted], true)) {
                throw new DomainException('This treatment is already closed.');
            }

            $procedure = $this->workspace->addProcedure($encounter, [
                'clinical_procedure_id' => $item->clinical_procedure_id,
                'notes' => trim(($item->tooth_number ? "Tooth {$item->tooth_number}".($item->surfaces ? ' ('.implode('', $item->surfaces).')' : '').'. ' : '').(string) $note) ?: null,
                'details' => ['tooth_number' => $item->tooth_number !== null ? (int) $item->tooth_number : null, 'surfaces' => $item->surfaces, 'treatment_record_id' => $item->id],
            ]);

            $item->update([
                'status' => TreatmentStatus::Completed,
                'encounter_id' => $encounter->id,
                'completed_at' => now(),
                'patient_procedure_id' => $procedure->id,
            ]);

            if ($item->tooth_number) {
                $condition = $resultingCondition ?? $this->chart(Patient::query()->withoutGlobalScopes()->findOrFail($plan->patient_id))->get($item->tooth_number)?->status ?? 'sound';
                $this->validate((int) $item->tooth_number, $condition, $item->surfaces ?? []);
                $history = $this->chartTooth($encounter, (int) $item->tooth_number, $condition, $item->surfaces ?? [], "Done: {$item->description}", $doctor, PatientToothStatusHistory::TREATMENT);
                $history->forceFill(['treatment_record_id' => $item->id, 'patient_procedure_id' => $procedure->id])->saveQuietly();
            }

            if ($plan->status === TreatmentPlan::ACCEPTED) {
                $plan->update(['status' => TreatmentPlan::ACTIVE]);
            }
            $this->closeIfDone($plan);
            $this->mirrored($plan);

            return $item;
        });
    }

    /**
     * Pause an accepted or active plan (the patient travelling, a medical
     * hold). Nothing is done from a paused plan until it is resumed.
     */
    public function pausePlan(TreatmentPlan $plan, string $reason): TreatmentPlan
    {
        return DB::transaction(fn () => $this->mirrored($this->transition($plan, TreatmentPlan::WORKABLE, TreatmentPlan::PAUSED, 'treatment_plan_paused', ['paused_at' => now(), 'status_reason' => $this->reason($reason)])));
    }

    public function resumePlan(TreatmentPlan $plan): TreatmentPlan
    {
        return DB::transaction(function () use ($plan) {
            $locked = $this->lockPlan($plan);
            $started = $locked->records()->where('status', TreatmentStatus::Completed->value)->exists();

            return $this->mirrored($this->transition($locked, [TreatmentPlan::PAUSED], $started ? TreatmentPlan::ACTIVE : TreatmentPlan::ACCEPTED, 'treatment_plan_resumed', ['paused_at' => null, 'status_reason' => null]));
        });
    }

    /** The patient declining a proposed plan. */
    public function declinePlan(TreatmentPlan $plan, string $reason): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $reason) {
            $locked = $this->transition($plan, [TreatmentPlan::DRAFT, TreatmentPlan::PROPOSED], TreatmentPlan::DECLINED, 'treatment_plan_declined', ['declined_at' => now(), 'status_reason' => $this->reason($reason)]);
            $locked->records()->whereNotIn('status', [TreatmentStatus::Completed->value, TreatmentStatus::Aborted->value])->update(['status' => TreatmentStatus::Aborted->value]);

            return $this->mirrored($locked);
        });
    }

    /**
     * Stop a plan that will not be finished. Treatment already done stays
     * recorded; what was still to do is aborted.
     */
    public function cancelPlan(TreatmentPlan $plan, string $reason): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $reason) {
            $locked = $this->transition($plan, [TreatmentPlan::DRAFT, TreatmentPlan::PROPOSED, ...TreatmentPlan::WORKABLE, TreatmentPlan::PAUSED], TreatmentPlan::CANCELLED, 'treatment_plan_cancelled', ['cancelled_at' => now(), 'status_reason' => $this->reason($reason)]);
            $locked->records()->whereNotIn('status', [TreatmentStatus::Completed->value, TreatmentStatus::Aborted->value])->update(['status' => TreatmentStatus::Aborted->value]);

            return $this->mirrored($locked);
        });
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(TreatmentPlan $plan, array $from, string $to, string $event, array $changes): TreatmentPlan
    {
        return DB::transaction(function () use ($plan, $from, $to, $event, $changes) {
            $locked = $this->lockPlan($plan);
            if (! in_array($locked->status, $from, true)) {
                throw new DomainException("A {$locked->status} plan cannot be moved to {$to}.");
            }

            $locked->update(['status' => $to, ...$changes]);
            $this->timeline($locked, $event, array_filter(['reason' => $changes['status_reason'] ?? null]));

            return $locked;
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('Give a reason.');
        }

        return mb_substr($reason, 0, 500);
    }

    public function cancelItem(TreatmentPlan $plan, TreatmentRecord $item): TreatmentRecord
    {
        return DB::transaction(function () use ($plan, $item) {
            $plan = $this->lockPlan($plan);
            if ($item->treatment_plan_id !== $plan->id) {
                throw new InvalidArgumentException('That treatment belongs to a different plan.');
            }
            if ($item->status === TreatmentStatus::Completed) {
                throw new DomainException('A completed treatment cannot be cancelled.');
            }

            $item->update(['status' => TreatmentStatus::Aborted]);
            $this->closeIfDone($plan);
            $this->mirrored($plan);

            return $item;
        });
    }

    /**
     * @param  array{tooth_number?: ?int, surfaces?: list<string>, clinical_procedure_id: int, notes?: ?string}  $item
     */
    private function addItem(TreatmentPlan $plan, array $item, int $order): TreatmentRecord
    {
        $service = ClinicalProcedure::query()->withoutGlobalScopes()
            ->where('clinic_id', $plan->clinic_id)->whereKey($item['clinical_procedure_id'] ?? 0)->first()
            ?? throw new InvalidArgumentException('Each treatment must be a service from the clinic\'s list.');

        $tooth = $item['tooth_number'] ?? null;
        $surfaces = [];
        if ($tooth !== null) {
            $tooth = (int) $tooth;
            if (! $this->fdi->isValidFdiToothNumber($tooth)) {
                throw new InvalidArgumentException("Tooth {$tooth} is not an FDI tooth number.");
            }
            $surfaces = $this->surfaces($item['surfaces'] ?? []);
        }

        // The estimate is the clinic's own price for the service (the branch's
        // when it has one), from the canonical price versions. An unpriced
        // service stays unpriced, never zero.
        $branchId = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($plan->encounter_id)->value('branch_id');
        $price = $this->catalogue->effectivePrice($plan->clinic_id, $service, $branchId);

        return $plan->records()->create([
            'clinic_id' => $plan->clinic_id,
            'patient_id' => $plan->patient_id,
            'price' => $price,
            'clinical_procedure_id' => $service->id,
            'code' => $service->code,
            'description' => trim($service->name.(! empty($item['notes']) ? ' — '.trim($item['notes']) : '')),
            'tooth_number' => $tooth,
            'surfaces' => $surfaces ?: null,
            'status' => TreatmentStatus::Proposed,
            'sort_order' => $order,
        ]);
    }

    /**
     * @param  list<string>  $surfaces
     */
    private function chartTooth(ClinicalEncounter $encounter, int $tooth, string $condition, array $surfaces, ?string $note, ClinicEmployee $doctor, string $kind): PatientToothStatusHistory
    {
        $current = PatientDentalChart::query()->withoutGlobalScopes()
            ->where('clinic_id', $encounter->clinic_id)->where('patient_id', $encounter->patient_id)->where('tooth_number', $tooth)
            ->lockForUpdate()->first();

        $history = PatientToothStatusHistory::query()->create([
            'clinic_id' => $encounter->clinic_id,
            'patient_id' => $encounter->patient_id,
            'encounter_id' => $encounter->id,
            'tooth_number' => $tooth,
            'kind' => $kind,
            'status' => $condition,
            'previous_status' => $current?->status,
            'surfaces' => $surfaces ?: null,
            'notes' => ($note = trim((string) $note)) === '' ? null : $note,
            'updated_by_doctor_id' => $doctor->id,
        ]);

        $state = ['status' => $condition, 'surfaces' => $surfaces ?: null, 'notes' => $history->notes, 'encounter_id' => $encounter->id, 'updated_by_doctor_id' => $doctor->id];
        $current
            ? $current->forceFill($state)->save()
            : PatientDentalChart::query()->create(['clinic_id' => $encounter->clinic_id, 'patient_id' => $encounter->patient_id, 'tooth_number' => $tooth, ...$state]);

        return $history;
    }

    /**
     * @param  list<string>  $surfaces
     * @return list<string>
     */
    private function validate(int $tooth, string $condition, array $surfaces): array
    {
        if (! $this->fdi->isValidFdiToothNumber($tooth)) {
            throw new InvalidArgumentException("Tooth {$tooth} is not an FDI tooth number.");
        }
        $definition = config("clinic_dental.conditions.{$condition}") ?? throw new InvalidArgumentException('Unknown tooth condition.');

        $surfaces = $this->surfaces($surfaces);
        if ($surfaces !== [] && ! $definition['surface']) {
            throw new InvalidArgumentException("{$definition['label']} applies to the whole tooth, not to surfaces.");
        }

        return $surfaces;
    }

    /**
     * @param  list<string>  $surfaces
     * @return list<string>
     */
    private function surfaces(array $surfaces): array
    {
        $allowed = array_keys(config('clinic_dental.surfaces'));
        $surfaces = array_values(array_unique(array_map('strtoupper', $surfaces)));
        if (array_diff($surfaces, $allowed) !== []) {
            throw new InvalidArgumentException('Surfaces are M, O, D, B and L.');
        }

        return array_values(array_intersect($allowed, $surfaces));
    }

    /**
     * Every change to a proposal bundle is mirrored into `patient_treatments`,
     * the canonical execution record, in the same transaction.
     */
    private function mirrored(TreatmentPlan $plan): TreatmentPlan
    {
        $this->mirror->mirrorPlan($plan);

        return $plan;
    }

    private function closeIfDone(TreatmentPlan $plan): void
    {
        $open = $plan->records()->whereNotIn('status', [TreatmentStatus::Completed->value, TreatmentStatus::Aborted->value])->exists();
        if (! $open) {
            $plan->update(['status' => TreatmentPlan::COMPLETED, 'completed_at' => now()]);
            $this->timeline($plan, 'treatment_plan_completed', []);
            // The routine check-up after the work is done (`PatientAutomation`), once this is saved.
            DB::afterCommit(fn () => app(PatientAutomation::class)->scheduleRecall($plan));
        }
    }

    private function lockOpen(ClinicalEncounter $encounter): ClinicalEncounter
    {
        /** @var ClinicalEncounter $locked */
        $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isEditable()) {
            throw new DomainException('This consultation is signed. Chart in an open consultation.');
        }

        return $locked;
    }

    private function lockPlan(TreatmentPlan $plan): TreatmentPlan
    {
        return TreatmentPlan::query()->withoutGlobalScopes()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(TreatmentPlan $plan, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($plan->clinic_id)->value('organization_id'),
            'clinic_id' => $plan->clinic_id,
            'patient_id' => $plan->patient_id,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => ['treatment_plan_id' => $plan->id, 'title' => $plan->title, ...$context],
        ]);
    }
}
