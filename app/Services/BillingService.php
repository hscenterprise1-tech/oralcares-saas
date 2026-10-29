<?php

namespace App\Services;

use App\BillingStatus;
use App\ClinicDocument;
use App\Events\BillingCreated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Services\Numbering\ClinicDocumentNumbers;
use App\Services\Patient\PatientCareGuard;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one writer of clinic bills (ADR-001 §G): `patient_billings` and its
 * lines. Payments and refunds are `PaymentService`.
 *
 * Money is computed in paise (integers) and stored to two decimals. A bill is
 * issued when it is created — numbered from its branch's financial-year
 * series — and is never edited afterwards: a wrong bill is cancelled and a
 * new one issued. The legacy `invoices` tables are read-only.
 *
 * Tax follows the clinic's billing configuration (onboarding): `none`,
 * `inclusive` (the price already contains it) or `exclusive` (added on top),
 * at the clinic's rate unless a line names its own.
 */
class BillingService
{
    public function __construct(protected ClinicDocumentNumbers $numbers) {}

    /**
     * Issue a bill.
     *
     * @param  list<array{item_type?: string, description?: ?string, clinical_procedure_id?: ?int, quantity?: int, unit_price: float|int|string, discount_amount?: float|int|string, tax_rate?: float|int|string|null, source?: ?Model, patient_treatment_id?: ?int, patient_treatment_stage_id?: ?int, price_version_id?: ?int}>  $items
     */
    public function createBill(
        Clinic $clinic,
        Patient $patient,
        Branch $branch,
        array $items,
        ?ClinicalEncounter $encounter = null,
        float $billDiscount = 0,
        string $discountType = 'flat',
        ?ClinicEmployee $doctor = null,
        ?ClinicEmployee $creator = null,
        ?string $notes = null,
    ): PatientBilling {
        $this->assertOwnership($clinic, $patient, $branch, $encounter, $doctor);
        // After a recorded death, only care given on or before that date is billed.
        app(PatientCareGuard::class)->assertCanBeCharged($patient, $encounter);

        if ($items === []) {
            throw new DomainException('A bill needs at least one line.');
        }

        $lines = $this->priceLines($clinic, $items);
        $treatmentIds = array_values(array_unique(array_filter(array_column($lines, 'patient_treatment_id'))));
        if ($treatmentIds !== [] && PatientTreatment::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)->whereIn('id', $treatmentIds)->count() !== count($treatmentIds)) {
            throw new InvalidArgumentException('A bill line can only charge for this patient\'s own treatment.');
        }
        $this->allocateBillDiscount($lines, $billDiscount, $discountType);
        $this->applyTax($clinic, $lines);

        $gross = array_sum(array_column($lines, 'gross'));
        $discount = array_sum(array_column($lines, 'discount'));
        $tax = array_sum(array_column($lines, 'tax'));
        $final = array_sum(array_column($lines, 'final'));

        return DB::transaction(function () use ($clinic, $patient, $branch, $encounter, $doctor, $creator, $notes, $lines, $gross, $discount, $tax, $final) {
            $bill = PatientBilling::query()->create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'encounter_id' => $encounter?->id,
                'doctor_id' => $doctor?->id ?? $encounter?->doctor_id,
                'billing_number' => $this->numbers->next(ClinicDocument::Invoice, $clinic, $branch),
                'financial_year' => $this->numbers->financialYearLabel(),
                'status' => $final > 0 ? BillingStatus::Pending : BillingStatus::Paid,
                'total_amount' => $this->money($gross),
                'discount_amount' => $this->money($discount),
                'tax_amount' => $this->money($tax),
                'final_amount' => $this->money($final),
                'paid_amount' => 0,
                'refunded_amount' => 0,
                'outstanding_balance' => $this->money($final),
                'notes' => $notes,
                'issued_at' => now(),
                'created_by' => $creator?->id,
            ]);

            foreach ($lines as $line) {
                $bill->items()->create([
                    'clinic_id' => $clinic->id,
                    'item_type' => $line['item_type'],
                    'description' => $line['description'],
                    'clinical_procedure_id' => $line['clinical_procedure_id'],
                    'source_type' => $line['source']?->getMorphClass(),
                    'source_id' => $line['source']?->getKey(),
                    // Which treatment/stage and which price version this line charges for.
                    'patient_treatment_id' => $line['patient_treatment_id'],
                    'patient_treatment_stage_id' => $line['patient_treatment_stage_id'],
                    'price_version_id' => $line['price_version_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $this->money($line['unit']),
                    'discount_amount' => $this->money($line['discount']),
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $this->money($line['tax']),
                    'final_amount' => $this->money($line['final']),
                ]);
            }

            PatientTimelineEvent::create([
                'organization_id' => $clinic->organization_id,
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'actor_id' => auth()->id(),
                'event_type' => 'bill_issued',
                'context' => ['billing_id' => $bill->id, 'billing_number' => $bill->billing_number, 'final_amount' => $bill->final_amount],
            ]);

            event(new BillingCreated($bill));

            return $bill->load('items');
        });
    }

    /**
     * Cancel a bill that has no money against it. A bill that has been paid
     * is refunded instead (`PaymentService::recordRefund`).
     */
    public function cancelBill(PatientBilling $bill, ?ClinicEmployee $by = null, ?string $reason = null): PatientBilling
    {
        return DB::transaction(function () use ($bill, $by, $reason) {
            /** @var PatientBilling $locked */
            $locked = PatientBilling::query()->withoutGlobalScopes()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                return $locked;
            }

            if ((float) $locked->paid_amount > (float) $locked->refunded_amount) {
                throw new DomainException('This bill has payments against it. Refund them before cancelling.');
            }

            $locked->update([
                'status' => BillingStatus::Cancelled,
                'outstanding_balance' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $by?->id,
                'cancellation_reason' => $reason,
                'updated_by' => $by?->id,
            ]);

            PatientTimelineEvent::create([
                'organization_id' => $locked->clinic()->withoutGlobalScopes()->value('organization_id'),
                'clinic_id' => $locked->clinic_id,
                'patient_id' => $locked->patient_id,
                'actor_id' => auth()->id(),
                'event_type' => 'bill_cancelled',
                'context' => ['billing_id' => $locked->id, 'billing_number' => $locked->billing_number, 'reason' => $reason],
            ]);

            return $locked;
        });
    }

    /**
     * Every party to a bill must belong to its clinic.
     */
    private function assertOwnership(Clinic $clinic, Patient $patient, Branch $branch, ?ClinicalEncounter $encounter, ?ClinicEmployee $doctor): void
    {
        if ($patient->clinic_id !== $clinic->id || $branch->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('The patient and branch must belong to the billing clinic.');
        }

        if ($encounter && ($encounter->clinic_id !== $clinic->id || $encounter->patient_id !== $patient->id)) {
            throw new InvalidArgumentException('The encounter must belong to this patient in this clinic.');
        }

        if ($doctor && $doctor->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('The doctor must belong to the billing clinic.');
        }
    }

    /**
     * Lines in paise, with descriptions resolved from the clinic catalogue.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{item_type: string, description: string, clinical_procedure_id: ?int, source: ?Model, quantity: int, unit: int, gross: int, discount: int, tax_rate: float|null, tax: int, final: int}>
     */
    private function priceLines(Clinic $clinic, array $items): array
    {
        $procedureIds = array_values(array_filter(array_column($items, 'clinical_procedure_id')));
        $procedures = ClinicalProcedure::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)->withinPlan($clinic)->whereIn('id', $procedureIds)->pluck('name', 'id');

        return array_map(function (array $item) use ($procedures) {
            $procedureId = isset($item['clinical_procedure_id']) ? (int) $item['clinical_procedure_id'] : null;

            if ($procedureId !== null && ! $procedures->has($procedureId)) {
                throw new InvalidArgumentException('A billed service is not in this clinic\'s catalogue.');
            }

            $description = trim((string) ($item['description'] ?? '')) ?: ($procedureId ? $procedures[$procedureId] : '');
            if ($description === '') {
                throw new InvalidArgumentException('Every bill line needs a description or a catalogue service.');
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unit = $this->paise($item['unit_price'] ?? 0);
            $gross = $unit * $quantity;
            $discount = min($gross, $this->paise($item['discount_amount'] ?? 0));

            if ($unit < 0 || $discount < 0) {
                throw new InvalidArgumentException('Prices and discounts cannot be negative.');
            }

            return [
                'item_type' => (string) ($item['item_type'] ?? ($procedureId ? PatientBillingItem::TYPE_SERVICE : PatientBillingItem::TYPE_OTHER)),
                'description' => $description,
                'clinical_procedure_id' => $procedureId,
                'source' => $item['source'] ?? null,
                'patient_treatment_id' => isset($item['patient_treatment_id']) ? (int) $item['patient_treatment_id'] : null,
                'patient_treatment_stage_id' => isset($item['patient_treatment_stage_id']) ? (int) $item['patient_treatment_stage_id'] : null,
                'price_version_id' => isset($item['price_version_id']) ? (int) $item['price_version_id'] : null,
                'quantity' => $quantity,
                'unit' => $unit,
                'gross' => $gross,
                'discount' => $discount,
                'tax_rate' => isset($item['tax_rate']) ? (float) $item['tax_rate'] : null,
                'tax' => 0,
                'final' => $gross - $discount,
            ];
        }, $items);
    }

    /**
     * Spread a bill-level discount over the lines in proportion to what each
     * still owes, so line totals always add up to the bill.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function allocateBillDiscount(array &$lines, float $billDiscount, string $discountType): void
    {
        if ($billDiscount <= 0) {
            return;
        }

        $gross = array_sum(array_column($lines, 'gross'));
        $remaining = array_sum(array_column($lines, 'final'));
        $amount = $discountType === 'percentage'
            ? (int) round($gross * min($billDiscount, 100) / 100)
            : $this->paise($billDiscount);
        $amount = min($amount, $remaining);

        if ($amount <= 0 || $remaining <= 0) {
            return;
        }

        $allocated = 0;
        $last = array_key_last($lines);
        foreach ($lines as $index => &$line) {
            $share = $index === $last ? $amount - $allocated : (int) floor($amount * $line['final'] / $remaining);
            $share = min($share, $line['final']);
            $line['discount'] += $share;
            $line['final'] -= $share;
            $allocated += $share;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function applyTax(Clinic $clinic, array &$lines): void
    {
        $config = (array) ($clinic->billing_config ?? []);
        $mode = $config['tax_mode'] ?? 'none';
        $clinicRate = (float) ($config['tax_percent'] ?? 0);

        foreach ($lines as &$line) {
            // A line that names its own rate is taxed at it even where the
            // clinic charges no tax by default (added on top, unless the
            // clinic's prices include tax).
            $rate = $line['tax_rate'] ?? ($mode === 'none' ? 0.0 : $clinicRate);
            $line['tax_rate'] = $rate;

            if ($rate <= 0) {
                continue;
            }

            if ($mode === 'inclusive') {
                $line['tax'] = (int) round($line['final'] - $line['final'] / (1 + $rate / 100));
            } else {
                $line['tax'] = (int) round($line['final'] * $rate / 100);
                $line['final'] += $line['tax'];
            }
        }
    }

    private function paise(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function money(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }
}
