<?php

use App\BranchStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\ClinicQueueItem;
use App\Models\PatientCase;
use App\Models\PatientTimelineEvent;
use App\Models\PatientTreatment;
use App\Models\PatientVisit;
use App\Models\SecurityAuditLog;
use App\QueueItemStatus;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Reception\ReceptionCheckIn;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/*
 * The controlled case lifecycle (D3): a treating practitioner recommends
 * completion, an authorised member closes, a closed case is reopened with a
 * reason. Nothing closes a case on its own, every step is audited, and the
 * case's visits stay as they were. Patient 360 shows it in the Cases & Plans
 * zone and the persistent Active Cases bar.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Ravi', 'Kumar');
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->checkIn = fn ($doctor = null) => app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $doctor ?? $this->me);
    $this->step = fn ($as, PatientCase $case, string $transition, ?string $note = null) => $as()->post(route('clinic.patients.cases.transition', [$this->patient->id, $case->id, $transition]), ['note' => $note]);
    $this->cases = function ($as): array {
        return $as()->getJson(route('clinic.patients.360.section', [$this->patient->id, 'cases']))->assertOk()->json();
    };
    $this->case = function (): PatientCase {
        ($this->checkIn)();

        return PatientCase::withoutGlobalScopes()->sole();
    };
});

test('the lead recommends completion, the case stays open, and a new visit joins it rather than opening another', function () {
    $case = ($this->case)();

    ($this->step)($this->asDoctor, $case, 'recommend', 'Treatment complete')->assertRedirect()->assertSessionHasNoErrors();

    expect($case->fresh()->status)->toBe(PatientCase::COMPLETION_RECOMMENDED)
        ->and($case->fresh()->closed_at)->toBeNull()
        ->and(PatientTimelineEvent::where('event_type', 'case_completion_recommended')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'patient.case_completion_recommended')->exists())->toBeTrue();
    // Recommending again is refused.
    ($this->step)($this->asDoctor, $case, 'recommend')->assertSessionHasErrors('case');

    // Today's first visit is over; the patient comes back.
    PatientVisit::withoutGlobalScopes()->update(['visit_status' => 'completed']);
    ClinicQueueItem::query()->update(['status' => QueueItemStatus::Completed]);
    app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
    expect(PatientCase::withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientVisit::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(2);
});

test('only a recommended case closes; the lead or management closes it; history is kept', function () {
    $case = ($this->case)();
    ($this->step)($this->asOwner, $case, 'close')->assertSessionHasErrors('case');

    ($this->step)($this->asDoctor, $case, 'recommend')->assertSessionHasNoErrors();
    ($this->step)($this->asDoctor, $case, 'close', 'Healed well')->assertSessionHasNoErrors();

    $case = $case->fresh();
    expect($case->status)->toBe(PatientCase::CLOSED)
        ->and($case->closed_at)->not->toBeNull()
        ->and($case->discharge_summary)->toBe('Healed well')
        ->and(PatientVisit::withoutGlobalScopes()->where('case_id', $case->id)->count())->toBe(1)
        ->and(SecurityAuditLog::where('event', 'patient.case_closed')->exists())->toBeTrue();
});

test('a closed case is reopened only with a reason, keeping its earlier closure in the timeline', function () {
    $case = ($this->case)();
    ($this->step)($this->asDoctor, $case, 'recommend');
    ($this->step)($this->asOwner, $case, 'close');

    ($this->step)($this->asOwner, $case, 'reopen')->assertSessionHasErrors('reason');
    ($this->step)($this->asOwner, $case, 'reopen', 'Pain returned')->assertSessionHasNoErrors();

    expect($case->fresh()->status)->toBe(PatientCase::ACTIVE)
        ->and($case->fresh()->closed_at)->toBeNull()
        ->and(PatientTimelineEvent::whereIn('event_type', ['case_completion_recommended', 'case_closed', 'case_reopened'])->count())->toBe(3);
});

test('who may act: not the desk, not a doctor who never treated the case; a doctor who saw one of its visits may recommend but not close', function () {
    $case = ($this->case)();
    [$deskUser, $deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    [$otherUser, $otherSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $other = $this->f->doctor('Dr. Other', $otherUser);
    $asDesk = fn () => $this->actingAs($deskUser)->withSession($deskSession);
    $asOther = fn () => $this->actingAs($otherUser)->withSession($otherSession);

    ($this->step)($asDesk, $case, 'recommend')->assertForbidden();
    ($this->step)($asOther, $case, 'recommend')->assertForbidden();

    // The other doctor treats a visit of the case: they may recommend, but closing is the lead's or management's.
    PatientVisit::withoutGlobalScopes()->where('case_id', $case->id)->update(['doctor_id' => $other->id]);
    ($this->step)($asOther, $case, 'recommend')->assertSessionHasNoErrors();
    ($this->step)($asOther, $case, 'close')->assertForbidden();
    expect($case->fresh()->status)->toBe(PatientCase::COMPLETION_RECOMMENDED);
});

test('a physiotherapy case is recommended only once its plans of care are discharged', function () {
    $case = ($this->case)();
    $case->update(['specialty' => 'physiotherapy']);
    $visit = PatientVisit::withoutGlobalScopes()->where('case_id', $case->id)->sole();
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me, $visit);
    $treatment = PatientTreatment::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'encounter_id' => $encounter->id,
        'source' => PatientTreatment::SOURCE_PHYSIO_PLAN, 'title' => 'Knee rehab', 'specialty_key' => 'physiotherapy', 'status' => PatientTreatment::IN_PROGRESS,
    ]);

    ($this->step)($this->asDoctor, $case, 'recommend')->assertSessionHasErrors('case');
    $treatment->forceFill(['status' => PatientTreatment::COMPLETED])->save();
    ($this->step)($this->asDoctor, $case, 'recommend')->assertSessionHasNoErrors();
});

test('Cases & Plans shows the lead apart from who treated, never the case number, and plans only to clinicians', function () {
    $case = ($this->case)();
    PatientVisit::withoutGlobalScopes()->where('case_id', $case->id)->update(['doctor_id' => $this->f->doctorB->id]);
    [$deskUser, $deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $mine = ($this->cases)($this->asDoctor)['data'][0];
    expect($mine['lead'])->toBe('Dr. Me')
        ->and($mine['visits']['treated_by'])->toBe(['Dr. Bala Iyer'])
        ->and($mine)->not->toHaveKey('case_number')
        ->and($mine['treatments'])->toBeArray()
        ->and($mine['actions']['recommend'])->not->toBeNull()
        ->and($mine['actions']['close'])->toBeNull();

    $desk = ($this->cases)(fn () => $this->actingAs($deskUser)->withSession($deskSession))['data'][0];
    expect($desk['treatments'])->toBeNull()->and($desk['actions'])->toBe(['recommend' => null, 'close' => null, 'reopen' => null]);
});

test('Patient 360 has exactly seven zones, and the Active Cases bar shows open cases of offered modules only', function () {
    $case = ($this->case)();
    $closed = PatientCase::withoutGlobalScopes()->create([
        ...collect($case->getAttributes())->only(['clinic_id', 'branch_id', 'patient_id', 'lead_practitioner_id'])->all(),
        'case_number' => 'CASE-2026-000777', 'specialty' => 'dental', 'title' => 'Old filling', 'chief_complaint' => 'Old', 'status' => PatientCase::CLOSED, 'opened_at' => now()->subYear(), 'closed_at' => now()->subMonths(6),
    ]);
    $physio = PatientCase::withoutGlobalScopes()->create([
        ...collect($case->getAttributes())->only(['clinic_id', 'branch_id', 'patient_id', 'lead_practitioner_id'])->all(),
        'case_number' => 'CASE-2026-000778', 'specialty' => 'physiotherapy', 'title' => 'Neck pain', 'chief_complaint' => 'Neck', 'status' => PatientCase::ACTIVE, 'opened_at' => now(),
    ]);

    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn (Assert $page) => $page
        ->where('zones', fn ($zones) => collect($zones)->pluck('key')->all() === ['overview', 'clinical', 'cases', 'visits', 'docs', 'billing', 'timeline'])
        ->where('zones', fn ($zones) => collect($zones)->firstWhere('key', 'cases')['sections'] === ['cases', 'treatment'])
        // A dental-only clinic: the open dental case shows; the closed one and the physiotherapy one do not.
        ->where('active_cases', fn ($cases) => collect($cases)->pluck('id')->all() === [$case->id])
        ->where('active_cases.0.lead', 'Dr. Me'));

    // The Cases & Plans zone still lists every case, the physiotherapy one marked as no longer offered.
    $rows = collect(($this->cases)($this->asOwner)['data'])->keyBy('id');
    expect($rows)->toHaveCount(3)
        ->and($rows[$physio->id]['module_offered'])->toBeFalse()
        ->and($rows[$closed->id]['status'])->toBe(PatientCase::CLOSED);
});

test('a case of another branch or clinic is neither listed nor changed', function () {
    $case = ($this->case)();
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $case->update(['branch_id' => $east->id]);
    // The doctor works at the main branch only.
    $this->me->branches()->sync([$this->f->branch->id]);
    ClinicMembership::query()->where('user_id', $this->doctorUser->id)->update(['has_all_branches_access' => false]);

    // Refused outright, or answered without the other branch's case.
    $response = ($this->asDoctor)()->getJson(route('clinic.patients.360.section', [$this->patient->id, 'cases']));
    expect($response->status() === 403 || $response->json('data') === [])->toBeTrue();
    ($this->step)($this->asDoctor, $case, 'recommend')->assertForbidden();

    $elsewhere = ReceptionFixture::make('other-case-clinic');
    $theirs = $elsewhere->patient('Other', 'Clinic');
    $status = ($this->asOwner)()->post(route('clinic.patients.cases.transition', [$theirs->id, $case->id, 'recommend']))->status();
    expect($status)->toBeIn([403, 404])->and($case->fresh()->status)->toBe(PatientCase::ACTIVE);
});
