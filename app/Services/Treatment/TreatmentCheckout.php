<?php

namespace App\Services\Treatment;

use App\BillingStatus;
use App\ClinicPermission;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientProcedure;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Patient\PatientBranchAccess;
use App\Services\Security\SecurityAudit;
use App\Services\Treatment\Targets\TreatmentTargets;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a patient's treatments still have to bill, and billing it — inside a
 * consultation's checkout or on its own (an upfront package sold at the desk,
 * a stage closed without a sitting).
 *
 * Every charge is the amount pinned when the treatment started: the version
 * price (upfront), the stage price (per stage), or the procedure's fixed
 * `charged_amount` (per procedure). Lookups are scoped to one patient's
 * treatments; nothing scans the clinic.
 */
class TreatmentCheckout
{
    public function __construct(
        protected BillingService $billing,
        protected PatientBranchAccess $branchAccess,
        protected SecurityAudit $audit,
        protected TreatmentTargets $targets,
    ) {}

    /**
     * Charges due for a patient. With an encounter: only the treatment and
     * stage charges arising in it (procedure charges are its own lines).
     * Without: every unbilled treatment, stage and per-procedure charge.
     *
     * @return list<array{key: string, description: string, unit_price: float, quantity: int, clinical_procedure_id: ?int, patient_treatment_id: int, patient_treatment_stage_id: ?int, price_version_id: ?int, source: PatientTreatment|PatientTreatmentStage|PatientProcedure}>
     */
    public function dueCharges(Patient $patient, ?ClinicalEncounter $encounter = null): array
    {
        $treatments = PatientTreatment::query()->withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('status', '!=', PatientTreatment::CANCELLED)
            ->whereIn('charging_model', ['upfront', 'per_stage', 'per_procedure'])
            ->when($this->branchAccess->branchIds() !== null, fn ($q) => $q->whereIn('branch_id', $this->branchAccess->branchIds() ?: [0]))
            ->get();
        if ($treatments->isEmpty()) {
            return [];
        }
        $billed = $this->billedSources($patient->clinic_id, $treatments->pluck('id'));
        $lines = [];

        foreach ($treatments->where('charging_model', 'upfront')->whereNotNull('agreed_amount') as $treatment) {
            if ($encounter && $treatment->encounter_id !== $encounter->id) {
                continue;
            }
            if (! isset($billed[$treatment->getMorphClass().':'.$treatment->id])) {
                $lines[] = $this->line("treatment:{$treatment->id}", $treatment->title, (float) $treatment->agreed_amount, 1, null, $treatment->id, null, $treatment->price_version_id, $treatment);
            }
        }

        $perStage = $treatments->where('charging_model', 'per_stage')->keyBy('id');
        if ($perStage->isNotEmpty()) {
            PatientTreatmentStage::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)
                ->whereIn('patient_treatment_id', $perStage->keys())->where('status', PatientTreatmentStage::COMPLETED)->whereNotNull('agreed_amount')
                ->when($encounter, fn ($q) => $q->where('encounter_id', $encounter->id))
                ->orderBy('patient_treatment_id')->orderBy('position')->get()
                ->each(function (PatientTreatmentStage $stage) use ($perStage, $billed, &$lines) {
                    if (! isset($billed[$stage->getMorphClass().':'.$stage->id])) {
                        $lines[] = $this->line("stage:{$stage->id}", "{$perStage[$stage->patient_treatment_id]->title} — {$stage->name}", (float) $stage->agreed_amount, 1, null, $stage->patient_treatment_id, $stage->id, $stage->price_version_id, $stage);
                    }
                });
        }

        // Outside a consultation, per-procedure charges from signed sittings are billable here too.
        $perProcedure = $treatments->where('charging_model', 'per_procedure')->keyBy('id');
        if (! $encounter && $perProcedure->isNotEmpty()) {
            PatientProcedure::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)
                ->whereIn('patient_treatment_id', $perProcedure->keys())->whereNotNull('charged_amount')
                ->whereHas('encounter', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('finalized_at'))
                ->orderBy('id')->get()
                ->each(function (PatientProcedure $procedure) use ($billed, &$lines) {
                    if (! isset($billed[$procedure->getMorphClass().':'.$procedure->id])) {
                        $quantity = max(1, (int) $procedure->quantity);
                        $lines[] = $this->line("procedure:{$procedure->id}", $this->targets->describeProcedure($procedure), round((float) $procedure->charged_amount / $quantity, 2), $quantity, $procedure->clinical_procedure_id, $procedure->patient_treatment_id, $procedure->patient_treatment_stage_id, $procedure->price_version_id, $procedure);
                    }
                });
        }

        return $lines;
    }

    /**
     * Bill chosen treatment charges for a patient at a branch, outside any
     * consultation. A charge already billed (by anyone, at the same moment)
     * cannot be billed again: the patient's treatments are locked first.
     *
     * @param  list<string>  $keys  charge keys from dueCharges()
     */
    public function bill(Patient $patient, Branch $branch, array $keys, User $by, ?ClinicEmployee $creator = null, float $discount = 0): PatientBilling
    {
        $this->assertCanBill($by, $patient, $branch);
        if ($discount > 0 && ! $by->hasClinicPermission(ClinicPermission::DiscountApply)) {
            throw new AuthorizationException('You cannot give a discount.');
        }
        if ($keys === []) {
            throw new DomainException('Choose what to bill.');
        }

        return DB::transaction(function () use ($patient, $branch, $keys, $creator, $discount) {
            PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->lockForUpdate()->get(['id']);

            $chosen = collect($this->dueCharges($patient))->whereIn('key', $keys)->values();
            if ($chosen->count() !== count(array_unique($keys))) {
                throw new DomainException('Some of these charges are already billed or no longer due. Reload and try again.');
            }

            $bill = $this->billing->createBill(
                Clinic::query()->findOrFail($patient->clinic_id),
                $patient,
                $branch,
                $chosen->map(fn (array $line) => [
                    'item_type' => PatientBillingItem::TYPE_SERVICE,
                    'description' => $line['description'],
                    'clinical_procedure_id' => $line['clinical_procedure_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'source' => $line['source'],
                    'patient_treatment_id' => $line['patient_treatment_id'],
                    'patient_treatment_stage_id' => $line['patient_treatment_stage_id'],
                    'price_version_id' => $line['price_version_id'],
                ])->all(),
                null,
                $discount,
                'flat',
                null,
                $creator,
            );
            $this->audit->record('treatment.charge.billed', $bill, ['patient_id' => $patient->id, 'charges' => $chosen->pluck('key')->all(), 'branch_id' => $branch->id], ['clinic_id' => $patient->clinic_id]);

            return $bill;
        });
    }

    public function assertCanBill(User $user, Patient $patient, Branch $branch): void
    {
        if (! $user->hasClinicPermission(ClinicPermission::BillingCreate)) {
            throw new AuthorizationException('You cannot bill.');
        }
        if ($branch->clinic_id !== $patient->clinic_id || ! $user->can('view', $patient)) {
            throw new AuthorizationException('You cannot bill this patient here.');
        }
        $allowed = $this->branchAccess->branchIds();
        if ($allowed !== null && ! in_array($branch->id, $allowed, true)) {
            throw new AuthorizationException('You do not work at this branch.');
        }
    }

    /**
     * Sources (`type:id`) already on a live bill, among these treatments' lines.
     *
     * @param  Collection<int, int>  $treatmentIds
     * @return array<string, true>
     */
    private function billedSources(int $clinicId, Collection $treatmentIds): array
    {
        return PatientBillingItem::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_treatment_id', $treatmentIds)
            ->whereHas('billing', fn ($bill) => $bill->withoutGlobalScopes()->where('status', '!=', BillingStatus::Cancelled->value))
            ->get(['source_type', 'source_id'])
            ->mapWithKeys(fn (PatientBillingItem $item) => ["{$item->source_type}:{$item->source_id}" => true])->all();
    }

    /**
     * @return array{key: string, description: string, unit_price: float, quantity: int, clinical_procedure_id: ?int, patient_treatment_id: int, patient_treatment_stage_id: ?int, price_version_id: ?int, source: PatientTreatment|PatientTreatmentStage|PatientProcedure}
     */
    private function line(string $key, string $description, float $unit, int $quantity, ?int $procedureId, int $treatmentId, ?int $stageId, ?int $priceVersionId, PatientTreatment|PatientTreatmentStage|PatientProcedure $source): array
    {
        return ['key' => $key, 'description' => $description, 'unit_price' => $unit, 'quantity' => $quantity, 'clinical_procedure_id' => $procedureId,
            'patient_treatment_id' => $treatmentId, 'patient_treatment_stage_id' => $stageId, 'price_version_id' => $priceVersionId, 'source' => $source];
    }
}
