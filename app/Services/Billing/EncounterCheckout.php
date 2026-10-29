<?php

namespace App\Services\Billing;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientProcedure;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PatientVisit;
use App\Services\BillingService;
use App\Services\Physio\SessionPackages;
use App\Services\ProcedureCatalogService;
use App\Services\Treatment\Targets\TreatmentTargets;
use App\Services\Treatment\TreatmentCheckout;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checkout: the hand-off from a signed consultation to its bill.
 *
 * The lines are the consultation's procedures not yet on a live bill, priced
 * from the clinic's own price list (branch price, else clinic price), plus
 * the treating practitioner's consultation fee from their profile when one
 * is set. Nothing is priced from a hard-coded fee. The bill itself is
 * written by `BillingService`, the one bill writer; each procedure line keeps
 * its source so it can never be billed twice.
 */
class EncounterCheckout
{
    public function __construct(
        protected BillingService $billing,
        protected ProcedureCatalogService $catalogue,
        protected TreatmentCheckout $treatmentCheckout,
        protected TreatmentTargets $targets,
    ) {}

    /**
     * What would be billed for this consultation now.
     *
     * A procedure done for a treatment is billed at the amount fixed when it
     * was done (`charged_amount`), never today's price; one inside a treatment
     * charged upfront or per stage is not billed on its own — the treatment
     * or stage line is, at the amount pinned when the treatment started.
     *
     * @return list<array{key: string, description: string, clinical_procedure_id: ?int, quantity: int, unit_price: float, source_id: ?int, patient_treatment_id?: ?int, patient_treatment_stage_id?: ?int, price_version_id?: ?int}>
     */
    public function lines(ClinicalEncounter $encounter): array
    {
        $clinic = Clinic::query()->findOrFail($encounter->clinic_id);
        $branch = Branch::query()->withoutGlobalScopes()->find($encounter->branch_id);
        $billed = $this->billedProcedureIds($encounter);

        $treatmentModels = PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)
            ->whereIn('id', $encounter->procedures()->withoutGlobalScopes()->whereNotNull('patient_treatment_id')->pluck('patient_treatment_id'))
            ->pluck('charging_model', 'id');

        $lines = $encounter->procedures()->withoutGlobalScopes()->orderBy('id')->get()
            ->reject(fn (PatientProcedure $procedure) => in_array($procedure->id, $billed, true))
            ->reject(fn (PatientProcedure $procedure) => $procedure->patient_treatment_id && in_array($treatmentModels[$procedure->patient_treatment_id] ?? null, ['upfront', 'per_stage'], true))
            ->map(function (PatientProcedure $procedure) use ($clinic, $branch) {
                $service = $procedure->clinical_procedure_id ? ClinicalProcedure::query()->withoutGlobalScopes()->find($procedure->clinical_procedure_id) : null;

                return [
                    'key' => "procedure:{$procedure->id}",
                    // What it was aimed at, described by the specialty extension that declares it.
                    'description' => $this->targets->describeProcedure($procedure),
                    'clinical_procedure_id' => $service?->id,
                    'quantity' => max(1, (int) $procedure->quantity),
                    'unit_price' => $procedure->charged_amount !== null
                        ? round((float) $procedure->charged_amount / max(1, (int) $procedure->quantity), 2)
                        : ($service ? $this->catalogue->getEffectivePrice($clinic, $service, $branch) : 0.0),
                    'source_id' => $procedure->id,
                    'patient_treatment_id' => $procedure->patient_treatment_id,
                    'patient_treatment_stage_id' => $procedure->patient_treatment_stage_id,
                    'price_version_id' => $procedure->price_version_id,
                ];
            })->values()->all();

        $fee = (float) (ClinicEmployee::query()->withoutGlobalScopes()->find($encounter->doctor_id)?->practitionerProfile?->consultation_fee ?? 0);
        // A consultation a session package actually paid for (a completed session of the package's own treatment) is not charged again.
        $packageCovered = app(SessionPackages::class)->paidFor($encounter);
        if ($fee > 0 && ! $packageCovered && ! $this->consultationBilled($encounter)) {
            array_unshift($lines, [
                'key' => 'consultation',
                'description' => 'Consultation',
                'clinical_procedure_id' => null,
                'quantity' => 1,
                'unit_price' => $fee,
                'source_id' => null,
            ]);
        }

        $patient = Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id);
        foreach ($this->treatmentCheckout->dueCharges($patient, $encounter) as $charge) {
            $lines[] = [
                'key' => $charge['key'], 'description' => $charge['description'], 'clinical_procedure_id' => null, 'quantity' => 1,
                'unit_price' => $charge['unit_price'], 'source_id' => null, 'patient_treatment_id' => $charge['patient_treatment_id'],
                'patient_treatment_stage_id' => $charge['patient_treatment_stage_id'], 'price_version_id' => $charge['price_version_id'],
            ];
        }

        return $lines;
    }

    /**
     * Raise the bill for the chosen lines. Prices may be adjusted at the desk
     * (a line's price is the clinic's to set); discounts go through the bill
     * discount, which needs its own permission at the route.
     *
     * @param  array<string, float|int|string|null>  $prices  line key => unit price; a key left out is not billed
     */
    public function bill(ClinicalEncounter $encounter, array $prices, ClinicEmployee $by, float $discount = 0): PatientBilling
    {
        return DB::transaction(function () use ($encounter, $prices, $by, $discount) {
            /** @var ClinicalEncounter $encounter */
            $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            // The patient's treatments too: a treatment charge billed here cannot be billed at the desk at the same moment.
            PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->where('patient_id', $encounter->patient_id)->lockForUpdate()->get(['id']);
            if (! $encounter->isFinalized()) {
                throw new DomainException('Bill a consultation once the doctor has signed it.');
            }

            $chosen = collect($this->lines($encounter))->filter(fn (array $line) => array_key_exists($line['key'], $prices));
            if ($chosen->isEmpty()) {
                throw new DomainException('There is nothing left to bill for this consultation.');
            }

            $procedures = PatientProcedure::query()->withoutGlobalScopes()->whereIn('id', $chosen->pluck('source_id')->filter())->get()->keyBy('id');

            $items = $chosen->map(fn (array $line) => [
                'item_type' => $line['key'] === 'consultation' ? PatientBillingItem::TYPE_CONSULTATION : PatientBillingItem::TYPE_SERVICE,
                'description' => $line['description'],
                'clinical_procedure_id' => $line['clinical_procedure_id'],
                'quantity' => $line['quantity'],
                'unit_price' => max(0, (float) ($prices[$line['key']] ?? $line['unit_price'])),
                'source' => match (true) {
                    (bool) $line['source_id'] => $procedures->get($line['source_id']),
                    str_starts_with($line['key'], 'treatment:') => PatientTreatment::query()->withoutGlobalScopes()->find($line['patient_treatment_id']),
                    str_starts_with($line['key'], 'stage:') => PatientTreatmentStage::query()->withoutGlobalScopes()->find($line['patient_treatment_stage_id']),
                    default => $encounter,
                },
                'patient_treatment_id' => $line['patient_treatment_id'] ?? null,
                'patient_treatment_stage_id' => $line['patient_treatment_stage_id'] ?? null,
                'price_version_id' => $line['price_version_id'] ?? null,
            ])->values()->all();

            return $this->billing->createBill(
                Clinic::query()->findOrFail($encounter->clinic_id),
                Patient::query()->withoutGlobalScopes()->findOrFail($encounter->patient_id),
                Branch::query()->withoutGlobalScopes()->findOrFail($encounter->branch_id),
                $items,
                $encounter,
                $discount,
                'flat',
                ClinicEmployee::query()->withoutGlobalScopes()->find($encounter->doctor_id),
                $by,
            );
        });
    }

    /**
     * Signed consultations at a branch that still have something to bill.
     *
     * @return Collection<int, ClinicalEncounter>
     */
    public function readyToBill(Clinic $clinic, ?Branch $branch, int $days = 7, int $limit = 100): Collection
    {
        return ClinicalEncounter::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->whereNotNull('finalized_at')
            ->where('finalized_at', '>=', now()->subDays($days))
            ->with(['patient' => fn ($query) => $query->withoutGlobalScopes(), 'doctor:id,name'])
            ->latest('finalized_at')->limit($limit)->get()
            ->filter(fn (ClinicalEncounter $encounter) => $this->lines($encounter) !== [])
            ->values();
    }

    /**
     * @return list<int>
     */
    private function billedProcedureIds(ClinicalEncounter $encounter): array
    {
        return PatientBillingItem::query()->withoutGlobalScopes()
            ->where('source_type', (new PatientProcedure)->getMorphClass())
            ->whereIn('source_id', $encounter->procedures()->withoutGlobalScopes()->pluck('id'))
            ->whereHas('billing', fn ($bill) => $bill->withoutGlobalScopes()->where('status', '!=', 'cancelled'))
            ->pluck('source_id')->map(fn ($id) => (int) $id)->all();
    }

    private function consultationBilled(ClinicalEncounter $encounter): bool
    {
        // Charged at checkout (sourced from the encounter) or already at check-in
        // (sourced from the encounter's visit, `CheckInCharges`): one charge either way.
        return PatientBillingItem::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('source_type', $encounter->getMorphClass())->where('source_id', $encounter->id))
                ->when($encounter->patient_visit_id, fn ($q) => $q->orWhere(fn ($q) => $q->where('source_type', (new PatientVisit)->getMorphClass())->where('source_id', $encounter->patient_visit_id))))
            ->whereHas('billing', fn ($bill) => $bill->withoutGlobalScopes()->where('status', '!=', 'cancelled'))
            ->exists();
    }
}
