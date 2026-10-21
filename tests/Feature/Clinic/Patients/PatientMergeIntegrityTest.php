<?php

use App\Models\AttachedDocument;
use App\Models\CaseNumber;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientFollowUp;
use App\Models\PatientMerge;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentStage;
use App\Models\PatientVisit;
use App\PatientStatus;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Patient\ConsentService;
use App\Services\Patient\PatientMergeService;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TreatmentFixture;

/**
 * Merging with a realistic record on both sides and a bystander patient:
 * nothing is lost, nothing crosses to another patient, and no moved row
 * still points back at the duplicate.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->version = $this->t->rct('per_procedure');
    $this->sequence = 0;

    /** A full record: visit under a case number, signed consultation, treatment with stages and a procedure, appointment, follow-up, bill, document, consent, alert and the legacy rows. */
    $this->seedRecord = function (Patient $patient, int $tooth) {
        $owner = $this->t->actAsOwner();
        $clinic = $this->f->clinic;
        $case = CaseNumber::create([
            'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'year' => 2026, 'period_key' => '2026', 'counter_key' => 'case:2026',
            'sequence' => ++$this->sequence, 'case_number' => "C-2026-{$this->sequence}", 'search_key' => "c2026{$this->sequence}",
        ]);
        $visit = PatientVisit::create(['clinic_id' => $clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'case_number_id' => $case->id, 'doctor_id' => $this->f->doctorA->id, 'visit_date' => today()]);
        $workspace = app(EncounterWorkspace::class);
        $encounter = $workspace->startForPatient($patient, $this->f->branch, $this->f->doctorA, $visit);
        $treatment = app(PatientTreatments::class)->start($patient, $this->version, $this->f->doctorA, $this->f->branch, $owner, ['encounter' => $encounter, 'targets' => ['tooth' => [['tooth_number' => $tooth]]]]);
        app(PatientTreatments::class)->recordProcedure($treatment->stages->first(), $this->t->procedures['ACC'], $encounter, $this->f->doctorA, $owner);
        $workspace->autosave($encounter, ['chief_complaint' => "Pain in {$tooth}"], $encounter->fresh()->lock_version);
        $workspace->finalize($encounter, $this->f->doctorA);

        $this->f->appointment($patient, $this->f->doctorA, now()->addWeek());
        PatientFollowUp::create(['clinic_id' => $clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'patient_treatment_id' => $treatment->id, 'practitioner_id' => $this->f->doctorA->id, 'due_on' => today()->addWeeks(2), 'reason' => 'Review', 'status' => 'pending', 'created_by' => $owner->id]);
        app(BillingService::class)->createBill($clinic, $patient, $this->f->branch, [['clinical_procedure_id' => $this->t->procedures['FIL']->id, 'unit_price' => 500]]);
        AttachedDocument::create(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'uploaded_by' => $owner->id, 'file_name' => "xray-{$tooth}.png", 'file_path' => "x/{$tooth}.png", 'file_size_bytes' => 10, 'document_type' => 'other', 'scan_status' => AttachedDocument::SCAN_CLEAN]);
        app(ConsentService::class)->capture($patient, ['type' => 'treatment', 'method' => 'written', 'text' => "RCT on {$tooth}"], $owner);
        PatientAlert::create(['clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'type' => 'allergy', 'description' => 'Penicillin', 'severity' => 'high']);
        $invoiceId = DB::table('invoices')->insertGetId(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id, 'patient_id' => $patient->id, 'invoice_number' => "INV-{$patient->id}", 'created_at' => now(), 'updated_at' => now()]);
        DB::table('queue_entries')->insert(['organization_id' => $clinic->organization_id, 'patient_id' => $patient->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('billing_references')->insert(['patient_id' => $patient->id, 'billing_reference_number' => "BR-{$patient->id}", 'created_at' => now(), 'updated_at' => now()]);

        return compact('case', 'visit', 'encounter', 'treatment', 'invoiceId');
    };

    $this->keep = $this->f->patient('Asha', 'Rao');
    $this->duplicate = $this->f->patient('Asha', 'Rao');
    $this->bystander = $this->f->patient('Meena', 'Shah');
    $this->kept = ($this->seedRecord)($this->keep, 16);
    $this->dup = ($this->seedRecord)($this->duplicate, 26);
    $this->other = ($this->seedRecord)($this->bystander, 36);

    $this->tables = [...PatientMergeService::MOVED, ...PatientMergeService::MOVED_BY_PATIENT];
    $this->countFor = fn (int $patientId) => collect($this->tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('patient_id', $patientId)->count()])->all();
    $this->merge = fn () => $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => [
        'user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id, 'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID],
        'method' => 'password', 'activated_at' => now()->getTimestamp(), 'last_seen_at' => now()->getTimestamp(),
    ]])->post('/clinic/approvals/direct', ['action' => 'patient.merge', 'target_id' => $this->duplicate->id, 'reason' => 'Registered twice at the front desk', 'payload' => ['survivor_patient_id' => $this->keep->id]]);
});

test('every row of every kind moves to the patient kept; none is lost and the bystander is untouched', function () {
    $keepBefore = ($this->countFor)($this->keep->id);
    $dupBefore = ($this->countFor)($this->duplicate->id);
    $bystanderBefore = ($this->countFor)($this->bystander->id);
    $totals = collect($this->tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
    $dupIds = collect($this->tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('patient_id', $this->duplicate->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()])->filter()->all();

    // The record really is realistic: every kind the brief names is present on the duplicate.
    foreach (['patient_visits', 'clinical_encounters', 'patient_treatments', 'patient_procedures', 'appointments', 'patient_follow_ups', 'patient_billings', 'attached_documents', 'consent_records', 'patient_timeline_events', 'patient_alerts', 'invoices', 'queue_entries', 'billing_references'] as $table) {
        expect($dupBefore[$table])->toBeGreaterThan(0, "the duplicate has no {$table}");
    }

    ($this->merge)()->assertSessionHasNoErrors();

    $keepAfter = ($this->countFor)($this->keep->id);
    foreach ($this->tables as $table) {
        // Timeline: the survivor also gains its `patient_merged` event.
        $extra = $table === 'patient_timeline_events' ? 1 : 0;
        expect($keepAfter[$table])->toBe($keepBefore[$table] + $dupBefore[$table] + $extra, "{$table} on the patient kept")
            ->and(DB::table($table)->count())->toBe($totals[$table] + $extra, "{$table} total rows");
    }
    expect(array_filter(($this->countFor)($this->duplicate->id)))->toBe([])
        ->and(($this->countFor)($this->bystander->id))->toBe($bystanderBefore)
        ->and(collect(PatientMerge::sole()->moved)->only(array_keys($dupIds))->map(fn ($ids) => collect($ids)->sort()->values()->all())->all())->toEqual($dupIds)
        ->and($this->duplicate->fresh()->status)->toBe(PatientStatus::Merged);
});

test('no moved row still refers to the duplicate: stages, procedures, visits, case numbers and enrolments follow', function () {
    ($this->merge)()->assertSessionHasNoErrors();
    $treatment = PatientTreatment::find($this->dup['treatment']->id);

    // Stages and procedures stay attached to their (moved) treatment and encounter.
    expect($treatment->patient_id)->toBe($this->keep->id)
        ->and(PatientTreatmentStage::where('patient_treatment_id', $treatment->id)->count())->toBe(3)
        ->and(DB::table('patient_procedures')->where('patient_treatment_id', $treatment->id)->pluck('patient_id')->unique()->all())->toBe([$this->keep->id])
        ->and(DB::table('clinical_encounters')->where('id', $this->dup['encounter']->id)->value('patient_id'))->toBe($this->keep->id);

    // Every row that points at an encounter or a treatment belongs to the same patient as it.
    foreach (['patient_procedures' => 'encounter_id', 'patient_treatments' => 'encounter_id', 'patient_follow_ups' => 'patient_treatment_id'] as $table => $column) {
        $parent = $column === 'encounter_id' ? 'clinical_encounters' : 'patient_treatments';
        expect(DB::table($table)->join($parent, "{$parent}.id", '=', "{$table}.{$column}")->whereColumn("{$parent}.patient_id", '!=', "{$table}.patient_id")->count())
            ->toBe(0, "{$table}.{$column} crosses patients");
    }

    // The treatment's specialty enrolment is the survivor's, and the visit is filed under a case number the survivor holds.
    expect(DB::table('patient_specialties')->where('id', $treatment->patient_specialty_id)->value('patient_id'))->toBe($this->keep->id)
        ->and(DB::table('case_numbers')->where('id', PatientVisit::find($this->dup['visit']->id)->case_number_id)->value('patient_id'))->toBe($this->keep->id)
        ->and(DB::table('patient_visits')->join('case_numbers', 'case_numbers.id', '=', 'patient_visits.case_number_id')->whereColumn('case_numbers.patient_id', '!=', 'patient_visits.patient_id')->count())->toBe(0);

    // The conflicting case number stays with the duplicate, recorded for review.
    expect(DB::table('case_numbers')->where('id', $this->dup['case']->id)->value('patient_id'))->toBe($this->duplicate->id)
        ->and(collect(PatientMerge::sole()->conflicts)->firstWhere('table', 'case_numbers')['visits_refiled'])->toBe([$this->dup['visit']->id]);
});

test('the merged record\'s page leads to the patient kept, whose page and treatments show the merged history', function () {
    ($this->merge)()->assertSessionHasNoErrors();
    $asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $asOwner()->get("/clinic/patients/{$this->duplicate->id}")->assertRedirect(route('clinic.patients.show', $this->keep->id));
    $asOwner()->get("/clinic/patients/{$this->keep->id}")->assertOk();
    $asOwner()->getJson("/clinic/patients/{$this->keep->id}/treatments")->assertOk()->assertJsonCount(2, 'data');
    $asOwner()->getJson("/clinic/patients/{$this->bystander->id}/treatments")->assertOk()->assertJsonCount(1, 'data');
});

test('every table that refers to a patient is moved, reconciled, or deliberately kept with the duplicate', function () {
    $handled = [...PatientMergeService::MOVED, ...PatientMergeService::MOVED_BY_PATIENT, ...PatientMergeService::RECONCILED, ...PatientMergeService::STAYS];
    $referencing = collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table) => collect(Schema::getForeignKeys($table))->contains(fn (array $fk) => $fk['foreign_table'] === 'patients'))
        ->values();

    expect($referencing)->not->toBeEmpty()
        ->and($referencing->diff($handled)->values()->all())->toBe([]);
});
