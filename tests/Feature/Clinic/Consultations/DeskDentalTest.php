<?php

use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\PatientDentalChart;
use App\Models\TreatmentPlan;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\Services\Staff\ClinicRolePermissions;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * The desk runs a dental visit for a dentist who has no login: it opens the
 * visit from the queue and works it in the dentist's workspace, in the
 * dentist's name. A dentist who can sign in does their own work, no other
 * specialty is opened this way, and the owner may take the right away.
 */
beforeEach(function () {
    ClinicRolePermissions::flush();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->deskUser, $this->deskSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->deskUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);
    $this->asDesk = fn () => $this->actingAs($this->deskUser)->withSession($this->deskSession);

    $this->dentist = $this->f->doctor('Dr. Tooth');
    $this->dentist->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Dentist']);
    [$signedInUser] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->signedIn = $this->f->doctor('Dr. Signed In', $signedInUser);
    $this->signedIn->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Dentist']);

    $this->rct = ClinicalProcedure::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'code' => 'RCTX', 'name' => 'Root canal treatment', 'category' => 'Endodontic', 'is_active' => true]);

    $this->checkIn = fn (ClinicEmployee $doctor, string $specialty = 'dental'): ClinicQueueItem => app(ReceptionCheckIn::class)->checkIn(
        $this->f->clinic, $this->f->branch, $this->f->patient('Ravi', 'Kumar'), doctor: $doctor,
        context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => $specialty, 'visit_reason' => 'Toothache']),
    );
    $this->open = fn (ClinicQueueItem $item) => ($this->asDesk)()->post("/clinic/reception/dental/queue/{$item->id}/open");
});

test('the desk opens the visit from the queue and finishes it in the dentist\'s name', function () {
    $item = ($this->checkIn)($this->dentist);
    ($this->open)($item)->assertRedirect();
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->sole();
    expect([$encounter->doctor_id, $encounter->specialty])->toBe([$this->dentist->id, 'dental']);

    ($this->asDesk)()->get("/clinic/front-desk/dental/{$encounter->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Consultations/Dental')
            ->where('desk.dentist', 'Dr. Tooth')->where('can_edit', true)->where('links.record', null)
            ->where('links.today', fn ($url) => str_ends_with($url, '/clinic/reception'))
            ->where('links.finish', fn ($url) => str_ends_with($url, "/clinic/front-desk/dental/{$encounter->id}/finish"))
            ->where('dental.specialty.can_write', false));

    ($this->asDesk)()->post("/clinic/front-desk/dental/{$encounter->id}/finish", [
        'consultation' => ['complaint' => 'Pain, lower left', 'diagnosis' => 'Irreversible pulpitis'],
        'findings' => [['tooth' => 36, 'condition' => 'caries', 'surfaces' => ['O']]],
        'work' => [['procedure_id' => $this->rct->id, 'tooth' => 36, 'done' => true]],
    ])->assertSessionHasNoErrors();

    $fresh = $encounter->fresh();
    expect($fresh->isFinalized())->toBeTrue()
        ->and($fresh->doctor_id)->toBe($this->dentist->id)
        ->and($fresh->finalized_by)->toBe($this->dentist->id)
        ->and(PatientDentalChart::withoutGlobalScopes()->where('patient_id', $fresh->patient_id)->pluck('tooth_number')->all())->toContain(36)
        ->and(TreatmentPlan::withoutGlobalScopes()->where('patient_id', $fresh->patient_id)->count())->toBe(1);

    // A finished visit is read only, at the desk as for the dentist.
    ($this->asDesk)()->post("/clinic/front-desk/dental/{$encounter->id}/finish", ['consultation' => ['complaint' => 'More']])->assertForbidden();
});

test('the Front Desk row offers to start the visit for a dentist without a login, then to continue it', function () {
    $item = ($this->checkIn)($this->dentist);
    ($this->asDesk)()->get('/clinic/reception')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('desk.waiting.0.links.desk_kind', 'dental')
        ->where('desk.waiting.0.links.desk_open', fn ($url) => str_ends_with($url, "/clinic/reception/dental/queue/{$item->id}/open")));

    ($this->open)($item)->assertRedirect();
    ($this->asDesk)()->get('/clinic/reception')->assertInertia(fn (Assert $page) => $page
        ->where('desk.with_doctor.0.links.desk_workspace', fn ($url) => str_contains($url, '/clinic/front-desk/dental/')));
});

test('the desk does not open or run the visit of a dentist who can sign in, nor another specialty', function () {
    ($this->open)(($this->checkIn)($this->signedIn))->assertForbidden();
    expect(ClinicalEncounter::query()->withoutGlobalScopes()->count())->toBe(0);

    // A physiotherapy visit of a practitioner without a login is not a dental one.
    ($this->open)(($this->checkIn)($this->t->physio, 'physiotherapy'))->assertForbidden();
});

test('the dentist\'s own visit is not opened at the desk, and the Doctor role cannot use the desk route', function () {
    $item = ($this->checkIn)($this->dentist);
    ($this->open)($item)->assertRedirect();
    $encounter = ClinicalEncounter::query()->withoutGlobalScopes()->sole();

    [$doctorUser, $doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->actingAs($doctorUser)->withSession($doctorSession)->get("/clinic/front-desk/dental/{$encounter->id}")->assertForbidden();
});

test('the owner can take the desk\'s right to run dental visits away', function () {
    $item = ($this->checkIn)($this->dentist);

    $this->actingAs($this->f->owner)->withSession($this->f->session)->put('/clinic/admin/roles/Receptionist/permissions', ['permission' => 'dental.desk.run', 'granted' => false])->assertRedirect();

    ($this->open)($item)->assertForbidden();
    expect(ClinicalEncounter::query()->withoutGlobalScopes()->count())->toBe(0);
});
