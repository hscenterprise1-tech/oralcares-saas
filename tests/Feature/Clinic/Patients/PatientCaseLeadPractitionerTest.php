<?php

use App\BranchStatus;
use App\Models\Branch;
use App\Models\ClinicEmployee;
use App\Models\PatientCase;
use App\Models\PatientVisit;
use App\Services\Patient\PatientCaseService;
use App\Services\Reception\VisitContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\ReceptionFixture;

/*
 * Who leads a new case (`patient_cases.lead_practitioner_id`): only the
 * visit's own practitioner, when they may practise here. Nobody is put in
 * their place — no doctor from another branch, no receptionist, no staff
 * record made up on the spot. With no eligible lead the visit is left
 * unlinked (`patient_visits.case_id` null).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->cases = app(PatientCaseService::class);
    $this->east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $this->patient = $this->f->patient('Meera', 'Nair');

    $this->visit = fn (?ClinicEmployee $doctor = null) => PatientVisit::create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id,
        'doctor_id' => $doctor?->id, 'registration_type' => 'repeat', 'visit_date' => '2026-10-15',
        'queue_status' => 'not_queued', 'visit_status' => 'registered',
    ]);
    $this->resolve = fn (?ClinicEmployee $doctor, ?int $caseId = null, ?string $specialty = null) => $this->cases->resolveCaseForVisit(
        $this->f->clinic, $this->f->branch, $this->patient, ($this->visit)($doctor), $doctor,
        $specialty ? new VisitContext(specialtyKey: $specialty) : null, $caseId,
    );
});

test('no practitioner on the visit opens no case and creates no staff record', function () {
    $employees = ClinicEmployee::withoutGlobalScopes()->count();

    expect(($this->resolve)(null))->toBeNull()
        ->and(PatientCase::withoutGlobalScopes()->count())->toBe(0)
        ->and(ClinicEmployee::withoutGlobalScopes()->count())->toBe($employees);
});

test('a practitioner placed only at another branch never leads a case here', function () {
    $this->f->doctorB->branches()->sync([$this->east->id]);

    expect(($this->resolve)($this->f->doctorB))->toBeNull()
        ->and(PatientCase::withoutGlobalScopes()->count())->toBe(0);
});

test('a receptionist or other non-practitioner never leads a case', function () {
    $receptionist = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Front Desk', 'status' => 'active', 'email' => 'desk@example.com']);

    expect(($this->resolve)($receptionist))->toBeNull()
        ->and(PatientCase::withoutGlobalScopes()->count())->toBe(0);
});

test('an inactive practitioner or one from another clinic never leads a case', function () {
    $this->f->doctorB->update(['status' => 'inactive']);
    $elsewhere = ReceptionFixture::make('other-clinic')->doctorA;

    expect(($this->resolve)($this->f->doctorB))->toBeNull()
        ->and(($this->resolve)($elsewhere))->toBeNull()
        ->and(PatientCase::withoutGlobalScopes()->count())->toBe(0);
});

test('a practitioner who does not practise the visit\'s specialty never leads it', function () {
    // The fixture's practitioners practise dental; this visit is for physiotherapy.
    $practised = $this->f->doctorA->practitionerProfile()->withoutGlobalScopes()->first()->specialtyKeys();
    if ($practised === []) {
        $this->markTestSkipped('The fixture practitioner practises every specialty.');
    }

    expect(($this->resolve)($this->f->doctorA, specialty: 'physiotherapy'))->toBeNull();
});

test('the visit\'s valid practitioner leads the new case', function () {
    $case = ($this->resolve)($this->f->doctorA);

    expect($case)->not->toBeNull()
        ->and($case->lead_practitioner_id)->toBe($this->f->doctorA->id)
        ->and($case->branch_id)->toBe($this->f->branch->id)
        ->and($case->clinic_id)->toBe($this->f->clinic->id);
});

test('an existing case keeps its lead and earlier visits keep their doctor when another doctor attends', function () {
    $case = ($this->resolve)($this->f->doctorA);
    $first = PatientVisit::query()->withoutGlobalScopes()->latest('id')->first();
    $first->update(['case_id' => $case->id]);

    $again = ($this->resolve)($this->f->doctorB, $case->id);

    expect($again->id)->toBe($case->id)
        ->and($case->fresh()->lead_practitioner_id)->toBe($this->f->doctorA->id)
        ->and($first->fresh()->doctor_id)->toBe($this->f->doctorA->id);
});

test('a case of another patient or clinic is refused, never replaced by a new one', function () {
    $other = $this->f->patient('Someone', 'Else');
    $theirs = PatientCase::create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $other->id,
        'case_number' => 'CASE-2026-000901', 'specialty' => 'dental', 'title' => 'Their case', 'chief_complaint' => 'Pain',
        'lead_practitioner_id' => $this->f->doctorA->id, 'status' => 'active', 'opened_at' => now(),
    ]);

    expect(fn () => ($this->resolve)($this->f->doctorA, $theirs->id))->toThrow(ValidationException::class)
        ->and(PatientCase::withoutGlobalScopes()->count())->toBe(1);
});

test('a desk check-in with no doctor available leaves the visit unassigned and unlinked', function () {
    // Every practitioner works at another branch, so nobody is on the roster here.
    $this->f->doctorA->branches()->sync([$this->east->id]);
    $this->f->doctorB->branches()->sync([$this->east->id]);
    $employees = ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count();

    $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->postJson('/clinic/reception/check-in', ['patient_id' => $this->patient->id, 'pay_later' => true])
        ->assertCreated();

    $visit = PatientVisit::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    expect($visit->doctor_id)->toBeNull()
        ->and($visit->case_id)->toBeNull()
        ->and(PatientCase::withoutGlobalScopes()->count())->toBe(0)
        ->and(ClinicEmployee::withoutGlobalScopes()->where('clinic_id', $this->f->clinic->id)->count())->toBe($employees);
});
