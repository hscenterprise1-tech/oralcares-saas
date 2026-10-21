<?php

use App\Models\CatalogPriceVersion;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\ClinicEmployee;
use App\Models\EndodonticRecord;
use App\Models\Patient;
use App\Models\PatientBillingItem;
use App\Models\PatientProcedure;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PhysioCarePlan;
use App\Models\PhysioExercise;
use App\Models\PhysioSession;
use App\Models\TreatmentRecord;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Dental\DentalRecord;
use App\Services\Physio\PhysioRecord;
use App\Services\ProcedureCatalogService;
use App\Services\Treatment\LegacyTreatmentMirror;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TreatmentFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->patient = $this->f->patient('Asha', 'Rao');
    $this->owner = $this->t->actAsOwner();
    $this->backfill = fn (array $options = []) => $this->artisan('treatments:backfill-engine', ['--clinic' => [$this->f->clinic->id], ...$options]);
});

test('a dental proposal is mirrored into patient treatments as it moves, keeping its amounts, teeth and links', function () {
    $filling = $this->t->procedures['FIL'];
    app(ProcedureCatalogService::class)->setClinicPricing($this->f->clinic, $filling, 1800);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    $dental = app(DentalRecord::class);

    $plan = $dental->proposePlan($encounter, 'Restore', [['clinical_procedure_id' => $filling->id, 'tooth_number' => 36, 'surfaces' => ['M', 'O']]], null, $this->f->doctorA);
    $record = TreatmentRecord::sole();
    $mirrored = PatientTreatment::sole();
    expect($mirrored)->legacy_treatment_record_id->toBe($record->id)->source->toBe('legacy')->status->toBe('planned')
        ->agreed_amount->toBe('1800.00')->treatment_plan_id->toBe($plan->id)->practitioner_id->toBe($this->f->doctorA->id)
        ->and($mirrored->teeth()->sole())->tooth_number->toBe(36)->surfaces->toBe(['M', 'O']);

    $dental->acceptPlan($plan);
    $dental->completeItem($plan->fresh(), $record->fresh(), $encounter, $this->f->doctorA, 'filled');
    $procedure = PatientProcedure::sole();

    expect($mirrored->fresh())->status->toBe('completed')->stages_completed->toBe(1)
        ->and($procedure->patient_treatment_id)->toBe($mirrored->id)
        ->and(PatientTreatmentStage::sole()->status)->toBe('completed');

    // The procedure is still billed from the consultation, and the bill line knows its treatment.
    $lines = app(EncounterCheckout::class)->lines($encounter);
    expect($lines[0])->unit_price->toBe(1800.0)->patient_treatment_id->toBe($mirrored->id);
});

test('the backfill migrates legacy records with their ids, amounts, dates and statuses, and totals reconcile', function () {
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    $plan = DB::table('treatment_plans')->insertGetId(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'encounter_id' => $encounter->id, 'title' => 'Old plan', 'status' => 'active', 'accepted_at' => now()->subMonth(), 'created_by' => $this->f->doctorA->id, 'created_at' => now()->subMonths(2), 'updated_at' => now()->subMonth()]);
    $done = DB::table('treatment_records')->insertGetId(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'treatment_plan_id' => $plan, 'encounter_id' => $encounter->id, 'code' => 'FIL', 'description' => 'Composite filling', 'tooth_number' => 36, 'status' => 'completed', 'price' => 1500, 'completed_at' => now()->subWeeks(3), 'created_at' => now()->subMonths(2), 'updated_at' => now()->subWeeks(3)]);
    $open = DB::table('treatment_records')->insertGetId(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'treatment_plan_id' => $plan, 'code' => 'CRN', 'description' => 'Crown', 'tooth_number' => 26, 'status' => 'planned', 'price' => null, 'created_at' => now()->subMonths(2), 'updated_at' => now()->subMonths(2)]);
    $endo = EndodonticRecord::query()->withoutGlobalScopes()->forceCreate(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'encounter_id' => $encounter->id, 'treatment_record_id' => $done, 'tooth_number' => 36, 'status' => 'completed', 'stage' => 'obturation', 'created_by' => $this->f->doctorA->id]);
    $legacyPrice = ClinicalProcedurePrice::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'clinical_procedure_id' => $this->t->procedures['OBT']->id, 'price' => 2500]);

    ($this->backfill)(['--dry-run' => true])->assertSuccessful();
    expect(PatientTreatment::count())->toBe(0);

    ($this->backfill)()->assertSuccessful()->expectsOutputToContain('counts and totals verified');
    ($this->backfill)()->assertSuccessful();

    $treatments = PatientTreatment::query()->orderBy('legacy_treatment_record_id')->get();
    expect($treatments)->toHaveCount(2)
        ->and($treatments[0])->legacy_treatment_record_id->toBe($done)->status->toBe('completed')->agreed_amount->toBe('1500.00')
        ->completed_at->toDateString()->toBe(now()->subWeeks(3)->toDateString())->started_at->toDateString()->toBe(now()->subMonth()->toDateString())
        ->created_at->toDateString()->toBe(now()->subMonths(2)->toDateString())
        // An unpriced record stays unpriced.
        ->and($treatments[1])->status->toBe('planned')->agreed_amount->toBeNull()
        ->and($endo->fresh()->patient_treatment_id)->toBe($treatments[0]->id)
        // The legacy rows are untouched and still readable.
        ->and(TreatmentRecord::query()->whereKey($done)->value('price'))->toEqual('1500.00')
        ->and(CatalogPriceVersion::where('legacy_price_id', $legacyPrice->id)->sole()->amount)->toBe('2500.00')
        ->and(app(ProcedureCatalogService::class)->effectivePrice($this->f->clinic->id, $this->t->procedures['OBT']))->toBe(2500.0);
});

test('a legacy physiotherapy plan of care (from before treatments were the care context) becomes a session-based treatment, with its sessions and exercises', function () {
    $therapist = $this->t->physio;
    $physio = app(PhysioRecord::class);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $therapist, null, 'physiotherapy');

    // Stored the way the old writer stored it: no treatment on the plan, session or exercise.
    $plan = legacyCarePlan($this->patient, $encounter, $therapist, 'Lower back', 8);
    PhysioExercise::query()->withoutGlobalScopes()->create(['care_plan_id' => $plan->id, 'name' => 'Bridging', 'sets' => 3, 'reps' => 10, 'is_home' => true, 'is_active' => true, 'sort_order' => 1]);
    PhysioSession::query()->withoutGlobalScopes()->create(['clinic_id' => $plan->clinic_id, 'patient_id' => $plan->patient_id, 'encounter_id' => $encounter->id, 'care_plan_id' => $plan->id, 'session_number' => 1, 'subjective' => 'Better', 'therapist_id' => $therapist->id, 'status' => PhysioSession::COMPLETED]);
    app(LegacyTreatmentMirror::class)->mirrorPhysioCarePlan($plan);

    $treatment = PatientTreatment::sole();
    expect($treatment)->source->toBe('physio_plan')->treatment_type->toBe('session_based')->specialty_key->toBe('physiotherapy')
        ->sessions_planned->toBe(8)->sessions_completed->toBe(1)->legacy_physio_care_plan_id->toBe($plan->id)
        ->and($plan->fresh()->patient_treatment_id)->toBe($treatment->id)
        ->and(PhysioSession::sole()->patient_treatment_id)->toBe($treatment->id)
        ->and(PhysioExercise::sole()->patient_treatment_id)->toBe($treatment->id);

    $physio->closeCarePlan($plan->fresh(), 'completed', 'Goals met');
    expect($treatment->fresh())->status->toBe('completed')->status_reason->toBe('Goals met');
});

test('pricing a procedure writes a new price version and keeps the legacy row in step', function () {
    $catalogue = app(ProcedureCatalogService::class);
    $catalogue->setClinicPricing($this->f->clinic, $this->t->procedures['FIL'], 1500);
    $this->travel(1)->hour();
    $catalogue->setClinicPricing($this->f->clinic, $this->t->procedures['FIL'], 1800);

    expect(CatalogPriceVersion::where('clinical_procedure_id', $this->t->procedures['FIL']->id)->orderBy('id')->pluck('amount')->all())->toBe(['1500.00', '1800.00'])
        ->and(ClinicalProcedurePrice::sole()->price)->toEqual('1800.00')
        ->and($catalogue->getEffectivePrice($this->f->clinic, $this->t->procedures['FIL']))->toBe(1800.0)
        ->and(PatientBillingItem::count())->toBe(0);
});

test('legacy dental proposals and physio plans stay readable in Patient 360 beside the treatment engine', function () {
    $filling = $this->t->procedures['FIL'];
    app(ProcedureCatalogService::class)->setClinicPricing($this->f->clinic, $filling, 1800);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->f->doctorA);
    $plan = app(DentalRecord::class)->proposePlan($encounter, 'Restore', [['clinical_procedure_id' => $filling->id, 'tooth_number' => 36]], null, $this->f->doctorA);
    $physioVisit = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->t->physio, null, 'physiotherapy');
    $carePlan = legacyCarePlan($this->patient, $physioVisit, $this->t->physio, 'Lower back', 8);
    app(LegacyTreatmentMirror::class)->mirrorPhysioCarePlan($carePlan);

    $this->actingAs($this->f->owner)->withSession($this->f->session)->getJson("/clinic/patients/{$this->patient->id}/360/treatment")->assertOk()
        ->assertJsonPath('plans.0.id', $plan->id)
        ->assertJsonPath('care_plans.0.id', $carePlan->id)
        ->assertJsonCount(2, 'treatments')
        ->assertJsonFragment(['source' => 'legacy'])
        ->assertJsonFragment(['source' => 'physio_plan']);
});

test('the retired requires_tooth flag cannot be written through the models; targets replace it', function () {
    $procedure = ClinicalProcedure::query()->withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'code' => 'CRN', 'name' => 'Crown', 'category' => 'Prosthodontic', 'requires_tooth' => true,
    ]);
    $version = $this->t->rct();
    $fresh = CatalogTreatmentVersion::query()->withoutGlobalScopes()->whereKey($version->id)->first();

    expect(DB::table('clinical_procedures')->where('id', $procedure->id)->value('requires_tooth'))->toBeFalsy()
        ->and((new CatalogTreatmentVersion)->fill(['requires_tooth' => true])->getAttributes())->not->toHaveKey('requires_tooth')
        ->and(DB::table('catalog_treatment_versions')->where('id', $version->id)->value('requires_tooth'))->toBeFalsy()
        ->and($fresh->targets())->toBe(['tooth']);
});

/** A plan of care as the pre-4E.2 writer stored it: the patient's, with no treatment yet. */
function legacyCarePlan(Patient $patient, ClinicalEncounter $encounter, ClinicEmployee $therapist, string $title, int $sessions): PhysioCarePlan
{
    return PhysioCarePlan::query()->withoutGlobalScopes()->create([
        'clinic_id' => $patient->clinic_id, 'patient_id' => $patient->id, 'encounter_id' => $encounter->id,
        'title' => $title, 'planned_sessions' => $sessions, 'starts_on' => now()->toDateString(),
        'status' => PhysioCarePlan::ACTIVE, 'created_by' => $therapist->id,
    ]);
}
