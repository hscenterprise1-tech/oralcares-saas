<?php

use App\ClinicRole;
use App\Models\AttachedDocument;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Models\PatientMedicalHistory;
use App\Models\PatientTimelineEvent;
use App\Models\SecurityAuditLog;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicalOrderService;
use App\Services\Dental\DentalRecord;
use App\Services\LaboratoryCaseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->patient = $this->f->patient('Meera', 'Iyer');
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    [$this->receptionUser, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    [$this->billingUser, $this->billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->as = fn (string $who) => $this->actingAs($this->{"{$who}User"})->withSession($this->{"{$who}Session"});
    $this->section = fn (string $s) => "/clinic/patients/{$this->patient->id}/360/{$s}";

    // A consultation with a charted tooth, a planned treatment, a resulted investigation.
    $this->actingAs($this->doctorUser);
    $workspace = app(EncounterWorkspace::class);
    $encounter = $workspace->startForPatient($this->patient, $this->f->branch, $this->me);
    app(DentalRecord::class)->recordFinding($encounter, 36, 'caries', ['M', 'O'], 'Deep, near pulp', $this->me);
    $service = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'FIL', 'name' => 'Composite filling', 'category' => 'Restorative', 'is_active' => true]);
    app(DentalRecord::class)->proposePlan($encounter, 'Restore 36', [['clinical_procedure_id' => $service->id, 'tooth_number' => 36, 'surfaces' => ['M', 'O']]], null, $this->me);
    $order = app(ClinicalOrderService::class)->placeOrder($encounter, ['priority' => 'routine'], [['item_name' => 'CBC', 'category' => 'lab']], $this->me);
    app(ClinicalOrderService::class)->recordResult($order->items()->sole(), null, ['summary' => 'Haemoglobin low', 'values' => [['name' => 'Hb', 'value' => '9.0', 'flag' => 'low']]]);
    PatientMedicalHistory::create(['patient_id' => $this->patient->id, 'clinic_id' => $this->patient->clinic_id, 'allergies' => ['Penicillin'], 'chronic_conditions' => ['Type 2 diabetes'], 'current_medications' => ['Metformin']]);
});

test('each role opens Patient 360 in its own persona, with its own default section', function () {
    ($this->as)('doctor')->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn (Assert $p) => $p->where('lens.persona', 'dental')->where('lens.default_section', 'dental'));
    ($this->as)('reception')->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn (Assert $p) => $p->where('lens.persona', 'reception')->where('lens.default_section', 'overview'));
    ($this->as)('billing')->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn (Assert $p) => $p->where('lens.persona', 'billing')->where('lens.default_section', 'billing'));
});

test('reception and billing get allergies but never the medical history, which clinical roles get', function () {
    foreach (['reception', 'billing'] as $who) {
        ($this->as)($who)->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn (Assert $p) => $p
            ->where('context.allergies', ['Penicillin'])
            ->where('context.conditions.reported', [])
            ->where('context.medications.reported', [])
            ->where('context.clinical', false));
    }
    ($this->as)('doctor')->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn (Assert $p) => $p
        ->where('context.conditions.reported', ['Type 2 diabetes'])
        ->where('context.medications.reported', ['Metformin']));
});

test('reception tracks investigation status but never receives the result', function () {
    $json = ($this->as)('reception')->getJson(($this->section)('investigations'))->assertOk()
        ->assertJsonPath('operational', true)->assertJsonPath('data.0.status', 'result_in')->json();

    expect(array_keys($json['data'][0]))->not->toContain('result', 'attachments', 'url', 'practitioner')
        ->and(json_encode($json))->not->toContain('Haemoglobin');
    ($this->as)('billing')->getJson(($this->section)('investigations'))->assertForbidden();
});

test('the dental record is sent as three different payloads: clinical, front desk, billing', function () {
    ($this->as)('doctor')->getJson(($this->section)('dental'))->assertOk()
        ->assertJsonPath('view', 'clinical')->assertJsonPath('teeth.0.surfaces', ['M', 'O'])
        ->assertJsonPath('states.36', 'planned')->assertJsonPath('ongoing.0.tooth', 36);

    $desk = ($this->as)('reception')->getJson(($this->section)('dental'))->assertOk()
        ->assertJsonPath('view', 'operational')->assertJsonPath('ongoing.0.tooth', 36)->assertJsonPath('ongoing.0.procedure', 'Composite filling')->json();
    expect($desk)->not->toHaveKeys(['teeth', 'conditions', 'plans', 'periodontal', 'endodontic'])
        ->and(json_encode($desk))->not->toContain('Deep, near pulp')->not->toContain('caries');

    $bill = ($this->as)('billing')->getJson(($this->section)('dental'))->assertOk()
        ->assertJsonPath('view', 'financial')->assertJsonPath('plans.0.items.0.procedure', 'Composite filling')->json();
    expect($bill)->not->toHaveKeys(['teeth', 'conditions', 'periodontal'])->and(json_encode($bill))->not->toContain('Deep, near pulp');

    // Tooth detail is clinical only.
    ($this->as)('reception')->getJson("/clinic/patients/{$this->patient->id}/360/dental/teeth/36")->assertForbidden();
    ($this->as)('billing')->getJson("/clinic/patients/{$this->patient->id}/360/dental/teeth/36")->assertForbidden();
    ($this->as)('doctor')->getJson("/clinic/patients/{$this->patient->id}/360/dental/teeth/36")->assertOk()->assertJsonPath('current.surfaces', ['M', 'O'])->assertJsonPath('history.0.notes', 'Deep, near pulp');
});

test('billing still sees a planned item priced when a lab case for the same tooth leads the clinical view', function () {
    $this->actingAs($this->doctorUser);
    $encounter = $this->patient->encounters()->sole();
    $crown = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CRN', 'name' => 'Zirconia crown', 'category' => 'Prosthodontics', 'is_active' => true]);
    ClinicalProcedurePrice::create(['clinic_id' => $this->f->clinic->id, 'clinical_procedure_id' => $crown->id, 'price' => 9500]);
    $plan = app(DentalRecord::class)->proposePlan($encounter, 'Crown 26', [['clinical_procedure_id' => $crown->id, 'tooth_number' => 26]], null, $this->me);
    app(DentalRecord::class)->acceptPlan($plan);
    app(LaboratoryCaseService::class)->order($encounter, $this->me, ['teeth' => [26], 'restoration' => 'Zirconia crown', 'laboratory_name' => 'SmileLab']);

    $clinical = collect(($this->as)('doctor')->getJson(($this->section)('dental'))->assertOk()->json('ongoing'))->where('tooth', 26);
    expect($clinical)->toHaveCount(1)->and($clinical->first()['source'])->toBe('lab');

    $billed = collect(($this->as)('billing')->getJson(($this->section)('dental'))->assertOk()->json('ongoing'))->where('tooth', 26);
    expect($billed)->toHaveCount(1)->and($billed->first()['procedure'])->toBe('Zirconia crown')->and((float) $billed->first()['price'])->toBe(9500.0);
});

test('the timeline sends each role only the kinds of event it may read', function () {
    PatientTimelineEvent::create(['clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'event_type' => 'some_future_clinical_event', 'context' => []]);

    $types = fn (string $who) => collect(($this->as)($who)->getJson(($this->section)('timeline'))->assertOk()->json('data'))->pluck('category')->unique()->values()->all();
    expect($types('reception'))->not->toContain('consultation', 'investigation', 'dental', 'treatment')
        ->and($types('doctor'))->toContain('dental', 'investigation')
        ->and(PatientTimelineEvent::where('event_type', 'some_future_clinical_event')->exists())->toBeTrue();
    expect(collect(($this->as)('doctor')->getJson(($this->section)('timeline'))->json('data'))->pluck('type'))->not->toContain('some_future_clinical_event');
});

test('clinical documents are neither listed nor opened for reception; administrative ones are', function () {
    Storage::fake('private');
    $mk = fn (string $type, string $name) => tap(AttachedDocument::create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'uploaded_by' => $this->f->owner->id,
        'file_name' => $name, 'file_path' => "docs/{$name}", 'file_size_bytes' => 4, 'document_type' => $type, 'scan_status' => AttachedDocument::SCAN_CLEAN,
    ]), fn ($d) => Storage::disk('private')->put($d->file_path, 'data'));
    $xray = $mk('xray', 'opg.pdf');
    $id = $mk('id_proof', 'aadhaar.pdf');

    $names = collect(($this->as)('reception')->getJson(($this->section)('documents'))->assertOk()->json('data'))->pluck('name');
    expect($names->all())->toBe(['aadhaar.pdf']);
    ($this->as)('reception')->get(route('clinic.patients.documents.download', [$this->patient->id, $xray->id]))->assertForbidden();
    ($this->as)('reception')->get(route('clinic.patients.documents.download', [$this->patient->id, $id->id]))->assertOk();
    expect(collect(($this->as)('doctor')->getJson(($this->section)('documents'))->json('data'))->pluck('name')->sort()->values()->all())->toBe(['aadhaar.pdf', 'opg.pdf']);
});

test('actions are built per role and nothing destructive is offered without authority', function () {
    $keys = fn (string $who) => collect(($this->as)($who)->get("/clinic/patients/{$this->patient->id}")->viewData('page')['props']['actions'])->pluck('key')->all();

    // The desk does not read the patient's ledger by default (OD-9), so it is not offered the Billing section's payments.
    expect($keys('reception'))->toContain('check_in', 'book')->not->toContain('collect_payment', 'start_consultation', 'continue_consultation', 'archive')
        ->and($keys('billing'))->toContain('collect_payment')->not->toContain('check_in', 'start_consultation', 'archive')
        ->and($keys('doctor'))->toContain('continue_consultation')->not->toContain('archive', 'collect_payment');

    // There is no patient archive: not even the owner, who decides every governed action, is offered one.
    $ownerActions = collect($this->actingAs($this->f->owner)->withSession($this->f->session)->get("/clinic/patients/{$this->patient->id}")->viewData('page')['props']['actions']);
    expect($ownerActions->pluck('key')->all())->toContain('approvals')->not->toContain('archive')
        ->and($ownerActions->pluck('governed')->filter()->all())->not->toContain('patient.archive');
});

test('audit entries say in what capacity the actor acted', function () {
    ($this->as)('reception')->get("/clinic/patients/{$this->patient->id}");
    expect(SecurityAuditLog::where('event', 'patient.viewed')->latest('id')->first()->context['actor_role'])->toBe('Receptionist');
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get("/clinic/patients/{$this->patient->id}");
    expect(SecurityAuditLog::where('event', 'patient.viewed')->latest('id')->first()->context['actor_role'])->toBe('Owner');
});
