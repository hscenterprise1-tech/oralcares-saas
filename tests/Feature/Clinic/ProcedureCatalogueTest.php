<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\PatientBillingItem;
use App\Models\PatientProcedure;
use App\Models\TreatmentRecord;
use App\Services\ClinicContext;
use App\Services\ProcedureCatalogService;
use Illuminate\Database\QueryException;

/**
 * `ClinicalProcedure` is the clinic's catalogue (`clinical_procedures`);
 * `PatientProcedure` is a procedure recorded for a patient
 * (`patient_procedures`). Billing lines and treatment records point at the
 * catalogue.
 */
beforeEach(function () {
    $this->clinicA = Clinic::factory()->create();
    $this->clinicB = Clinic::factory()->create();
    $this->catalogue = app(ProcedureCatalogService::class);
    $this->entry = fn (Clinic $clinic, string $code = 'D0120') => $this->catalogue->createProcedure([
        'clinic_id' => $clinic->id, 'code' => $code, 'name' => "Procedure {$code}", 'category' => 'diagnostic',
        'default_duration_minutes' => 30, 'clinical_complexity' => 'low',
    ]);
});

it('stores catalogue entries in clinical_procedures and performed procedures in patient_procedures', function () {
    expect((new ClinicalProcedure)->getTable())->toBe('clinical_procedures')
        ->and((new PatientProcedure)->getTable())->toBe('patient_procedures');

    $entry = ($this->entry)($this->clinicA);

    $this->assertDatabaseHas('clinical_procedures', ['id' => $entry->id, 'clinic_id' => $this->clinicA->id, 'code' => 'D0120']);
    $this->assertDatabaseMissing('patient_procedures', ['code' => 'D0120']);
});

it('lets two clinics use the same procedure code but not one clinic twice', function () {
    ($this->entry)($this->clinicA);
    ($this->entry)($this->clinicB);

    expect(fn () => ($this->entry)($this->clinicA))->toThrow(QueryException::class);
});

it('shows a clinic only its own catalogue', function () {
    ($this->entry)($this->clinicA, 'A-1');
    ($this->entry)($this->clinicB, 'B-1');

    $seenByA = app(ClinicContext::class)->runForClinic($this->clinicA, null, fn () => ClinicalProcedure::pluck('code')->all());

    expect($seenByA)->toBe(['A-1']);
});

it('prices a procedure clinic-wide and lets a branch override it', function () {
    $entry = ($this->entry)($this->clinicA);
    $main = Branch::factory()->create(['clinic_id' => $this->clinicA->id]);
    $east = Branch::factory()->create(['clinic_id' => $this->clinicA->id]);

    $this->catalogue->setClinicPricing($this->clinicA, $entry, 500.00);
    $this->catalogue->setClinicPricing($this->clinicA, $entry, 650.00, branch: $east);

    expect($this->catalogue->getEffectivePrice($this->clinicA, $entry))->toBe(500.0)
        ->and($this->catalogue->getEffectivePrice($this->clinicA, $entry, $main))->toBe(500.0)
        ->and($this->catalogue->getEffectivePrice($this->clinicA, $entry, $east))->toBe(650.0);

    $this->catalogue->setClinicPricing($this->clinicA, $entry, 550.00);
    expect($entry->prices()->withoutGlobalScopes()->whereNull('branch_id')->count())->toBe(1);
});

it('refuses to price another clinic\'s procedure or at another clinic\'s branch', function () {
    $entryB = ($this->entry)($this->clinicB);
    $entryA = ($this->entry)($this->clinicA, 'A-2');
    $branchB = Branch::factory()->create(['clinic_id' => $this->clinicB->id]);

    expect(fn () => $this->catalogue->setClinicPricing($this->clinicA, $entryB, 100.00))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->catalogue->setClinicPricing($this->clinicA, $entryA, 100.00, branch: $branchB))->toThrow(InvalidArgumentException::class);
});

it('resolves billing lines and treatment records to the catalogue entry', function () {
    $entry = ($this->entry)($this->clinicA);

    $treatment = new TreatmentRecord(['clinical_procedure_id' => $entry->id]);
    $billingLine = new PatientBillingItem(['clinical_procedure_id' => $entry->id]);

    expect($treatment->procedure()->withoutGlobalScopes()->first()?->code)->toBe('D0120')
        ->and($billingLine->procedure()->withoutGlobalScopes()->first()?->is($entry))->toBeTrue();
});
