<?php

namespace App\Services\Treatment;

use App\Models\ClinicalEncounter;
use App\Models\PatientProcedure;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PatientTreatmentTooth;
use App\Models\PhysioCarePlan;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\TreatmentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `patient_treatments` complete while older writers still exist.
 *
 * - A dental proposal line (`treatment_records`, in a `treatment_plans`
 *   bundle) is mirrored as a `legacy` patient treatment with one stage —
 *   the line itself — and its tooth, keyed by the original record id.
 * - A physio care plan is mirrored as a session-based `physio_plan` patient
 *   treatment, and its sessions and exercises are linked to it.
 *
 * Only what the source holds is copied: amounts, statuses and dates as
 * recorded, nothing clinical invented, no catalog entry fabricated. Links on
 * related rows (procedures, bill lines, tooth history, endo/implant/lab
 * records, sessions) are filled only where empty. Idempotent: the backfill
 * and the live writers both call it.
 */
class LegacyTreatmentMirror
{
    private const TREATMENT_STATUS = [
        'proposed' => PatientTreatment::PLANNED, 'planned' => PatientTreatment::PLANNED,
        'started' => PatientTreatment::IN_PROGRESS, 'resumed' => PatientTreatment::IN_PROGRESS,
        'paused' => PatientTreatment::PAUSED, 'completed' => PatientTreatment::COMPLETED, 'aborted' => PatientTreatment::CANCELLED,
    ];

    private const STAGE_STATUS = [
        'proposed' => PatientTreatmentStage::PENDING, 'planned' => PatientTreatmentStage::PENDING,
        'started' => PatientTreatmentStage::IN_PROGRESS, 'resumed' => PatientTreatmentStage::IN_PROGRESS, 'paused' => PatientTreatmentStage::IN_PROGRESS,
        'completed' => PatientTreatmentStage::COMPLETED, 'aborted' => PatientTreatmentStage::SKIPPED,
    ];

    public function mirrorPlan(TreatmentPlan $plan): void
    {
        TreatmentRecord::query()->withoutGlobalScopes()->where('treatment_plan_id', $plan->id)->orderBy('id')
            ->get()->each(fn (TreatmentRecord $record) => $this->mirrorRecord($record, $plan));
    }

    public function mirrorRecord(TreatmentRecord $record, ?TreatmentPlan $plan = null): PatientTreatment
    {
        return DB::transaction(function () use ($record, $plan) {
            $plan ??= $record->treatment_plan_id ? TreatmentPlan::query()->withoutGlobalScopes()->find($record->treatment_plan_id) : null;
            $planEncounter = $plan?->encounter_id ? ClinicalEncounter::query()->withoutGlobalScopes()->find($plan->encounter_id) : null;
            $doneIn = $record->encounter_id ? ClinicalEncounter::query()->withoutGlobalScopes()->find($record->encounter_id) : null;
            $recordStatus = $record->status instanceof TreatmentStatus ? $record->status->value : (string) $record->status;

            // A whole bundle declined, cancelled or paused takes its open lines with it.
            $status = self::TREATMENT_STATUS[$recordStatus] ?? PatientTreatment::PLANNED;
            if (! in_array($status, PatientTreatment::CLOSED, true) && $plan) {
                $status = match ($plan->status) {
                    TreatmentPlan::DECLINED, TreatmentPlan::CANCELLED => PatientTreatment::CANCELLED,
                    TreatmentPlan::PAUSED => PatientTreatment::PAUSED,
                    default => $status,
                };
            }

            $practitionerId = $plan?->created_by && DB::table('clinic_employees')->where('id', $plan->created_by)->where('clinic_id', $record->clinic_id)->exists() ? $plan->created_by : null;

            $treatment = PatientTreatment::query()->withoutGlobalScopes()->firstOrNew(['legacy_treatment_record_id' => $record->id]);
            if (! $treatment->exists) {
                $treatment->created_at = $record->created_at;
            }
            $treatment->fill([
                'clinic_id' => $record->clinic_id,
                'branch_id' => $planEncounter?->branch_id ?? $doneIn?->branch_id,
                'patient_id' => $record->patient_id,
                'specialty_key' => $planEncounter?->specialty ?? $this->soleSpecialty($record->clinic_id),
                'source' => PatientTreatment::SOURCE_LEGACY,
                'treatment_plan_id' => $plan?->id,
                'encounter_id' => $planEncounter?->id,
                'practitioner_id' => $practitionerId,
                'title' => (string) $record->description,
                'code' => $record->code,
                'treatment_type' => 'single',
                'charging_model' => 'per_procedure',
                'status' => $status,
                'agreed_amount' => $record->price,
                'stages_total' => 1,
                'stages_completed' => in_array($recordStatus, ['completed', 'aborted'], true) ? 1 : 0,
                'started_at' => $plan?->accepted_at,
                'completed_at' => $recordStatus === 'completed' ? $record->completed_at : null,
                'cancelled_at' => $status === PatientTreatment::CANCELLED ? ($plan?->cancelled_at ?? $plan?->declined_at) : null,
                'status_reason' => $status === PatientTreatment::CANCELLED || $status === PatientTreatment::PAUSED ? $plan?->status_reason : null,
            ])->save();

            $stage = PatientTreatmentStage::query()->withoutGlobalScopes()->firstOrNew(['patient_treatment_id' => $treatment->id, 'position' => 1]);
            $stage->fill([
                'clinic_id' => $record->clinic_id,
                'name' => (string) $record->description,
                'status' => self::STAGE_STATUS[$recordStatus] ?? PatientTreatmentStage::PENDING,
                'agreed_amount' => $record->price,
                'encounter_id' => $record->encounter_id,
                'completed_at' => in_array($recordStatus, ['completed', 'aborted'], true) ? ($record->completed_at ?? $record->updated_at) : null,
            ])->save();

            if ($record->tooth_number !== null) {
                PatientTreatmentTooth::query()->withoutGlobalScopes()->firstOrCreate(
                    ['patient_treatment_id' => $treatment->id, 'tooth_number' => (int) $record->tooth_number],
                    ['clinic_id' => $record->clinic_id, 'surfaces' => $record->surfaces ?: null],
                );
            }

            $this->linkRecordRows($record, $treatment, $stage);

            return $treatment;
        });
    }

    public function mirrorPhysioCarePlan(PhysioCarePlan $plan): PatientTreatment
    {
        // A plan made under a canonical treatment (ad-hoc or catalogue) is that
        // treatment's plan; only a plan from before then drives a mirrored one.
        if ($plan->patient_treatment_id !== null) {
            $owner = PatientTreatment::query()->withoutGlobalScopes()->find($plan->patient_treatment_id);
            if ($owner && $owner->source !== PatientTreatment::SOURCE_PHYSIO_PLAN) {
                return $owner;
            }
        }

        return DB::transaction(function () use ($plan) {
            $encounter = $plan->encounter_id ? ClinicalEncounter::query()->withoutGlobalScopes()->find($plan->encounter_id) : null;
            $sessions = DB::table('physio_sessions')->where('clinic_id', $plan->clinic_id)->where('care_plan_id', $plan->id)->count();
            $status = match ($plan->status) {
                PhysioCarePlan::ACTIVE => PatientTreatment::IN_PROGRESS,
                PhysioCarePlan::COMPLETED => PatientTreatment::COMPLETED,
                default => PatientTreatment::CANCELLED,
            };
            $practitionerId = $plan->created_by && DB::table('clinic_employees')->where('id', $plan->created_by)->where('clinic_id', $plan->clinic_id)->exists() ? $plan->created_by : null;

            $treatment = PatientTreatment::query()->withoutGlobalScopes()->firstOrNew(['legacy_physio_care_plan_id' => $plan->id]);
            if (! $treatment->exists) {
                $treatment->created_at = $plan->created_at;
            }
            $treatment->fill([
                'clinic_id' => $plan->clinic_id,
                'branch_id' => $encounter?->branch_id,
                'patient_id' => $plan->patient_id,
                'specialty_key' => 'physiotherapy',
                'source' => PatientTreatment::SOURCE_PHYSIO_PLAN,
                'encounter_id' => $plan->encounter_id,
                'practitioner_id' => $practitionerId,
                'title' => (string) $plan->title,
                'treatment_type' => 'session_based',
                'status' => $status,
                'sessions_planned' => $plan->planned_sessions,
                'sessions_completed' => $sessions,
                'started_at' => $plan->starts_on ?? $plan->created_at,
                'completed_at' => $status === PatientTreatment::COMPLETED ? $plan->closed_at : null,
                'cancelled_at' => $status === PatientTreatment::CANCELLED ? $plan->closed_at : null,
                'status_reason' => $plan->closing_note,
            ])->save();

            DB::table('physio_care_plans')->where('id', $plan->id)->whereNull('patient_treatment_id')->update(['patient_treatment_id' => $treatment->id]);
            DB::table('physio_sessions')->where('clinic_id', $plan->clinic_id)->where('care_plan_id', $plan->id)->whereNull('patient_treatment_id')->update(['patient_treatment_id' => $treatment->id]);
            DB::table('physio_exercises')->where('clinic_id', $plan->clinic_id)->where('care_plan_id', $plan->id)->whereNull('patient_treatment_id')->update(['patient_treatment_id' => $treatment->id]);

            // A package whose sessions all belong to this plan belongs to its treatment.
            $packages = DB::table('physio_sessions')->where('clinic_id', $plan->clinic_id)->where('care_plan_id', $plan->id)->whereNotNull('package_id')->distinct()->pluck('package_id');
            foreach ($packages as $packageId) {
                $plans = DB::table('physio_sessions')->where('package_id', $packageId)->distinct()->pluck('care_plan_id');
                if ($plans->count() === 1) {
                    DB::table('patient_session_packages')->where('id', $packageId)->whereNull('patient_treatment_id')->update(['patient_treatment_id' => $treatment->id]);
                }
            }

            return $treatment;
        });
    }

    private function linkRecordRows(TreatmentRecord $record, PatientTreatment $treatment, PatientTreatmentStage $stage): void
    {
        $link = ['patient_treatment_id' => $treatment->id];

        if ($record->patient_procedure_id) {
            $procedure = DB::table('patient_procedures')->where('id', $record->patient_procedure_id)->where('clinic_id', $record->clinic_id)->first();
            if ($procedure) {
                // The amount charged is what was actually billed for it, if it was billed.
                $billed = DB::table('patient_billing_items as i')->join('patient_billings as b', 'b.id', '=', 'i.patient_billing_id')
                    ->where('i.source_type', (new PatientProcedure)->getMorphClass())->where('i.source_id', $procedure->id)
                    ->where('b.status', '!=', 'cancelled')->selectRaw('sum(i.unit_price * i.quantity) as amount, count(*) as n')->first();
                DB::table('patient_procedures')->where('id', $procedure->id)->whereNull('patient_treatment_id')
                    ->update([...$link, 'patient_treatment_stage_id' => $stage->id, 'charged_amount' => $procedure->charged_amount ?? ($billed && $billed->n > 0 ? $billed->amount : null)]);
                DB::table('patient_billing_items')->where('source_type', (new PatientProcedure)->getMorphClass())->where('source_id', $procedure->id)
                    ->whereNull('patient_treatment_id')->update([...$link, 'patient_treatment_stage_id' => $stage->id]);
                DB::table('patient_tooth_status_histories')->where('patient_procedure_id', $procedure->id)->whereNull('patient_treatment_id')->update($link);
            }
        }

        DB::table('patient_billing_items')->where('treatment_record_id', $record->id)->whereNull('patient_treatment_id')->update([...$link, 'patient_treatment_stage_id' => $stage->id]);
        foreach (['patient_tooth_status_histories', 'endodontic_records', 'implant_records', 'lab_orders'] as $table) {
            DB::table($table)->where('treatment_record_id', $record->id)->where('clinic_id', $record->clinic_id)->whereNull('patient_treatment_id')->update($link);
        }
    }

    private function soleSpecialty(int $clinicId): ?string
    {
        $keys = DB::table('clinic_specialties')->where('clinic_id', $clinicId)->where('status', 'active')->pluck('specialty_key');

        return $keys->count() === 1 ? $keys->first() : null;
    }
}
