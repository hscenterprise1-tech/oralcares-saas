<?php

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\QueueHistory;
use App\Models\SecurityAuditLog;
use App\QueueItemStatus;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\QueueStateMachine;
use App\Services\Reception\FrontDesk;
use App\Services\Reception\QueueCorrections;
use App\Services\Reception\ReceptionCheckIn;
use App\Services\Reception\VisitContext;
use App\VisitPriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TreatmentFixture;

/**
 * The Front Desk (Reception's workspace) and the role workspaces around it:
 * who lands where, who may open what, the bounded day lists, the corrections
 * Reception may make before a consultation, and collection at the desk.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    [$this->receptionUser, $this->receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    // Staff have a staff record (bills and payments are made by a member of staff).
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->receptionUser->id, 'name' => 'Front Desk', 'email' => 'desk@example.com', 'status' => 'active']);
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->doctor = $this->f->doctor('Dr. Rehab', $this->doctorUser);
    $this->doctor->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist', 'consultation_fee' => 500]);
    $this->otherPhysio = $this->f->doctor('Dr. Other');
    $this->otherPhysio->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->asReception = fn () => $this->actingAs($this->receptionUser)->withSession($this->receptionSession);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->checkIn = function (string $first, ?ClinicEmployee $doctor = null, string $specialty = 'physiotherapy', VisitPriority $priority = VisitPriority::Normal): ClinicQueueItem {
        return app(ReceptionCheckIn::class)->checkIn(
            $this->f->clinic, $this->f->branch, $this->f->patient($first, 'Desk'), doctor: $doctor ?? $this->doctor, priority: $priority,
            context: VisitContext::fromInput($this->f->clinic, ['visit_specialty' => $specialty, 'visit_reason' => 'Knee pain']),
        );
    };
    // The desk's billing overview is the ledger's (OD-9: not a default Reception right); a clinic may grant it.
    $this->grantDeskLedger = function () {
        setPermissionsTeamId(null);
        Role::findByName(ClinicRole::Receptionist->value, 'web')->givePermissionTo(Permission::firstOrCreate(['name' => ClinicPermission::BillingView->value, 'guard_name' => 'web']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
    $this->desk = function () {
        $desk = null;
        ($this->asReception)()->get('/clinic/reception')->assertOk()->assertInertia(function (Assert $page) use (&$desk) {
            $desk = $page->toArray()['props'];
        });

        return $desk;
    };
});

// ─── Role homes and workspace authorization ─────────────────

test('every role lands from the one home route, its primary workspace noted; whoever runs the clinic lands on the Command Center', function () {
    [$billingUser, $billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    [$adminUser, $adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    // No separate staff dashboard: reception lands on the Front Desk, a doctor on the Doctor Dashboard (Today), billing staff on Billing.
    $home = fn ($as, string $workspace, string $component) => $as->followingRedirects()->get('/clinic/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component, true)->where('clinic.workspaces.0.key', $workspace));

    $home(($this->asReception)(), 'front_desk', 'ClinicPortal/FrontDesk/Index');
    $home(($this->asDoctor)(), 'today', 'ClinicPortal/Today/Index');
    $home($this->actingAs($billingUser)->withSession($billingSession), 'billing', 'ClinicPortal/Billing/Index');
    $home($this->actingAs($adminUser)->withSession($adminSession), 'command_center', 'ClinicPortal/CommandCenter/Index');
    $home($this->actingAs($this->f->owner)->withSession($this->f->session), 'command_center', 'ClinicPortal/CommandCenter/Index');
});

test('an owner who practises works in Doctor Mode, and reaches the Queue and Billing from the sidebar in Owner Mode', function () {
    $ownerDoctor = $this->f->doctor('Dr. Owner', $this->f->owner);
    $ownerDoctor->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);

    // Doctor Mode, the default: the doctor's day, without the Front Desk or Billing.
    $this->actingAs($this->f->owner)->withSession($this->f->session)->followingRedirects()->get('/clinic/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('clinic.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['today'])
            ->where('clinic.navigation', fn ($nav) => ! collect($nav)->pluck('key')->contains('billing')));
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/reception')->assertForbidden();

    // Owner Mode: every workspace, the Command Center first.
    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))->get('/clinic/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('clinic.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['command_center', 'front_desk', 'today', 'billing']
                && collect($workspaces)->firstWhere('primary', true)['key'] === 'command_center')
            ->where('clinic.navigation', fn ($nav) => collect($nav)->pluck('key')->take(4)->all() === ['dashboard', 'appointments', 'patients', 'front_desk']
                && collect($nav)->pluck('key')->contains('billing') && ! collect($nav)->pluck('key')->contains('new_case')));
});

test('the Front Desk and its actions are closed on the server to anyone whose workspace it is not', function () {
    [$billingUser, $billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $item = ($this->checkIn)('Ravi');
    $patch = fn ($as, string $what, array $data) => $as->patch("/clinic/reception/queue/{$item->id}/{$what}", $data);

    ($this->asReception)()->get('/clinic/reception')->assertOk();
    $this->actingAs($this->f->owner)->withSession($this->f->session)->get('/clinic/reception')->assertOk();

    // A practitioner holds queue.manage only to call their next patient.
    ($this->asDoctor)()->get('/clinic/reception')->assertForbidden();
    ($this->asDoctor)()->get('/clinic/reception/list/waiting')->assertForbidden();
    $patch(($this->asDoctor)(), 'status', ['action' => 'call'])->assertForbidden();
    $patch(($this->asDoctor)(), 'priority', ['priority' => 'emergency'])->assertForbidden();
    $patch(($this->asDoctor)(), 'doctor', ['practitioner_id' => $this->otherPhysio->id])->assertForbidden();
    $patch(($this->asDoctor)(), 'visit', ['visit_reason' => 'x'])->assertForbidden();
    ($this->asDoctor)()->post('/clinic/reception/check-in', ['patient_id' => $this->f->patient('X', 'Y')->id])->assertForbidden();
    ($this->asDoctor)()->post('/clinic/queue/call-next')->assertSessionHas('success');
    // Billing staff have neither the queue nor the Front Desk.
    $this->actingAs($billingUser)->withSession($billingSession)->get('/clinic/reception')->assertForbidden();

    expect($item->fresh()->doctor_id)->toBe($this->doctor->id);
});

test('the Billing work center is the billing staff\'s, the owner\'s and the front desk\'s, never a practitioner\'s; a clinic may take it from the desk', function () {
    [$billingUser, $billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);

    $this->actingAs($billingUser)->withSession($billingSession)->get('/clinic/billing')->assertOk();
    // The desk runs billing in full since 2026-11-05.
    ($this->asReception)()->get('/clinic/billing')->assertOk();
    ($this->asDoctor)()->get('/clinic/billing')->assertForbidden();
    withoutDeskLedger();
    ($this->asReception)()->get('/clinic/billing')->assertForbidden();
});

test('New Case and Repeat Case open their dialogs on the Front Desk, never a page of their own', function () {
    ($this->asReception)()->get('/clinic/reception')->assertInertia(fn (Assert $page) => $page
        ->where('clinic.actions', fn ($actions) => collect($actions)->firstWhere('key', 'new_case')['href'] === route('clinic.reception', ['intake' => 'new'], false)
            && collect($actions)->firstWhere('key', 'repeat_case')['href'] === route('clinic.reception', ['intake' => 'repeat'], false))
        ->where('intake', null));

    ($this->asReception)()->get('/clinic/reception?intake=repeat&q=OC-0042')
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/FrontDesk/Index', false)->where('intake', ['mode' => 'repeat', 'query' => 'OC-0042']));
});

// ─── Bounded lists ──────────────────────────────────────────

test('each list shows at most ten, waiting by emergency then longest wait, done latest first, and View all pages the rest', function () {
    foreach (range(1, 12) as $i) {
        ($this->checkIn)("W{$i}");
        $this->travel(1)->minute();
    }
    $emergency = ($this->checkIn)('Urgent', priority: VisitPriority::Emergency);
    $done = collect(range(1, 12))->map(function (int $i) {
        $item = ($this->checkIn)("D{$i}");
        app(QueueStateMachine::class)->start($item);
        app(QueueStateMachine::class)->complete($item);
        $this->travel(1)->minute();

        return $item;
    });

    $props = ($this->desk)();
    $desk = $props['desk'];
    expect($desk['counts'])->toMatchArray(['waiting' => 13, 'done' => 12])
        ->and($desk['waiting'])->toHaveCount(FrontDesk::PREVIEW)
        ->and($desk['done'])->toHaveCount(FrontDesk::PREVIEW)
        ->and($desk['waiting'][0]['id'])->toBe($emergency->id)
        ->and($desk['waiting'][1]['patient']['name'])->toBe('W1 Desk')
        ->and($desk['done'][0]['id'])->toBe($done->last()->id)
        ->and($desk['attention']['long_wait']['rows'])->toHaveCount(3);

    ($this->asReception)()->get('/clinic/reception/list/waiting')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ClinicPortal/FrontDesk/List', false)
        ->where('list', 'waiting')
        ->has('page.data', 13)
        ->where('page.data', fn ($rows) => collect($rows)->every(fn ($row) => in_array($row['status'], ['waiting', 'called'], true))));
});

test('the desk reads a fixed number of queries however busy the day is, and sends rows without clinical notes', function () {
    $count = function () {
        $this->t->actAs($this->receptionUser, $this->receptionSession);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $desk = app(FrontDesk::class)->desk($this->f->clinic, $this->f->branch, $this->receptionUser);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $desk];
    };
    foreach (range(1, 3) as $i) {
        ($this->checkIn)("A{$i}");
    }
    $count();
    [$few] = $count();
    foreach (range(1, 15) as $i) {
        ($this->checkIn)("B{$i}");
    }
    [$many, $desk] = $count();

    expect($many)->toBe($few)
        ->and(array_keys($desk['waiting'][0]))->toEqualCanonicalizing([
            'id', 'status', 'priority', 'patient', 'source', 'case', 'visit', 'doctor', 'checked_in_at', 'called_at', 'started_at', 'completed_at',
            'wait_minutes', 'safety', 'safety_more', 'can_correct', 'links',
        ])
        ->and(array_keys($desk['waiting'][0]['patient']))->toBe(['id', 'name', 'uhid', 'age', 'gender']);
});

test('the page polls only its live parts', function () {
    ($this->checkIn)('Ravi');
    $version = ($this->asReception)()->get('/clinic/reception')->viewData('page')['version'];

    $response = ($this->asReception)()->withHeaders([
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'ClinicPortal/FrontDesk/Index', 'X-Inertia-Partial-Data' => 'desk,billing',
    ])->get('/clinic/reception');

    expect(array_keys($response->json('props')))->toContain('desk', 'billing')->not->toContain('branch', 'links', 'can');
});

// ─── Queue ownership ────────────────────────────────────────

test('Reception moves the queue but never takes a patient in or finishes a visit; the doctor does, and queue and consultation agree', function () {
    $item = ($this->checkIn)('Ravi');

    foreach (['start', 'complete'] as $action) {
        ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/status", ['action' => $action])->assertSessionHasErrors('action');
    }
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/status", ['status' => 'in_consultation'])->assertSessionHasErrors('status');
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/status", ['action' => 'call'])->assertSessionHasNoErrors();
    expect($item->fresh()->status)->toBe(QueueItemStatus::Called)->and($item->fresh()->encounter_id)->toBeNull();

    ($this->asDoctor)()->post("/clinic/consultations/queue/{$item->id}")->assertRedirect();
    $encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_visit_id', $item->patient_visit_id)->sole();
    expect($item->fresh())->status->toBe(QueueItemStatus::InConsultation)->encounter_id->toBe($encounter->id);

    $this->actingAs($this->doctorUser);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")->assertRedirect();
    expect($item->fresh()->status)->toBe(QueueItemStatus::Completed)->and($encounter->fresh()->isFinalized())->toBeTrue();
});

// ─── Corrections before the consultation ────────────────────

test('Reception gives a waiting patient to another doctor of the same specialty, audited; never across specialty or after the visit starts', function () {
    $item = ($this->checkIn)('Ravi');
    app(QueueStateMachine::class)->call($item, $this->doctor);

    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/doctor", ['practitioner_id' => $this->otherPhysio->id])->assertSessionHasNoErrors();
    expect($item->fresh())->doctor_id->toBe($this->otherPhysio->id)->status->toBe(QueueItemStatus::Waiting)
        ->and(QueueHistory::where('clinic_queue_item_id', $item->id)->where('action', 'reassign')->exists())->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'queue.doctor_changed')->exists())->toBeTrue();

    // A dentist does not see physiotherapy patients.
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/doctor", ['practitioner_id' => $this->f->doctorA->id])
        ->assertSessionHasErrors(['status' => 'Dr. Asha Mehta does not practise Physiotherapy. Choose another doctor or change the visit\'s specialty.']);
    // Another clinic's doctor is not found.
    $elsewhere = TreatmentFixture::make('elsewhere');
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/doctor", ['practitioner_id' => $elsewhere->physio->id])->assertNotFound();

    $started = ($this->checkIn)('Started', $this->otherPhysio);
    app(QueueStateMachine::class)->start($started);
    ($this->asReception)()->patch("/clinic/reception/queue/{$started->id}/doctor", ['practitioner_id' => $this->doctor->id])
        ->assertSessionHasErrors(['status' => 'The doctor has already started this visit; it can no longer be changed here.']);
    expect($started->fresh()->doctor_id)->toBe($this->otherPhysio->id);
});

test('a reassignment read before the doctor started the visit is refused under the lock', function () {
    $item = ($this->checkIn)('Ravi');
    $stale = ClinicQueueItem::findOrFail($item->id);

    // The doctor takes the patient in between the desk reading the row and saving the change.
    $this->actingAs($this->doctorUser);
    app(EncounterWorkspace::class)->startFromQueue($item, $this->doctor);

    $this->t->actAs($this->receptionUser, $this->receptionSession);
    expect(fn () => app(QueueCorrections::class)->reassign($this->f->clinic, $this->f->branch, $stale, $this->otherPhysio, null))
        ->toThrow(DomainException::class, 'already started');
    expect($item->fresh()->doctor_id)->toBe($this->doctor->id);
});

test('Reception corrects why the patient came before the consultation, and not after', function () {
    $item = ($this->checkIn)('Ravi');

    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/visit", ['visit_specialty' => 'physiotherapy', 'visit_type' => 'follow_up', 'visit_reason' => 'Back pain'])
        ->assertSessionHasNoErrors();
    expect($item->patientVisit()->withoutGlobalScopes()->first())->visit_reason->toBe('Back pain')->visit_type_key->toBe('follow_up')
        ->and(SecurityAuditLog::where('event', 'visit.context_corrected')->exists())->toBeTrue();

    // Moving the visit to a specialty its doctor does not practise is refused.
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/visit", ['visit_specialty' => 'dental', 'visit_reason' => 'Tooth'])
        ->assertSessionHasErrors('status');

    app(QueueStateMachine::class)->start($item);
    ($this->asReception)()->patch("/clinic/reception/queue/{$item->id}/visit", ['visit_reason' => 'Changed later'])
        ->assertSessionHasErrors(['status' => 'The doctor has already started this visit; it can no longer be changed here.']);
    expect($item->patientVisit()->withoutGlobalScopes()->first()->visit_reason)->toBe('Back pain');
});

// ─── Collection at the desk ─────────────────────────────────

test('Reception bills a signed visit at the clinic\'s prices and takes the payment in one step; extra prices sent by the browser are ignored', function () {
    ($this->grantDeskLedger)();
    $item = ($this->checkIn)('Ravi');
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->doctor);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->doctor);

    $billing = ($this->desk)()['billing'];
    expect($billing['ready'])->toHaveCount(1)
        ->and($billing['ready'][0]['encounter_id'])->toBe($encounter->id)->and($billing['ready'][0]['total'])->toEqual(500);

    ($this->asReception)()->post($billing['ready'][0]['links']['collect'], ['method' => 'cash', 'amount' => 500, 'prices' => ['consultation' => 1]])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    $bill = PatientBilling::withoutGlobalScopes()->where('encounter_id', $encounter->id)->sole();
    expect((float) $bill->final_amount)->toBe(500.0)
        ->and((float) $bill->outstanding_balance)->toBe(0.0)
        ->and(($this->desk)()['billing']['ready'])->toBe([]);

    // Nothing left to bill: a second collection is refused, not a second bill.
    ($this->asReception)()->post($billing['ready'][0]['links']['collect'], ['method' => 'cash', 'amount' => 500])->assertSessionHasErrors('billing');
    expect(PatientBilling::withoutGlobalScopes()->where('encounter_id', $encounter->id)->count())->toBe(1);
});

test('collection at the desk needs billing rights, and an open bill\'s balance is collected from the list', function () {
    ($this->grantDeskLedger)();
    $item = ($this->checkIn)('Ravi');
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->doctor);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->doctor);
    $collect = route('clinic.reception.billing.collect', $encounter);

    ($this->asDoctor)()->post($collect, ['method' => 'cash', 'amount' => 500])->assertForbidden();

    // Part-paid at the desk, the rest collected from "Balance due".
    ($this->asReception)()->post($collect, ['method' => 'upi', 'amount' => 200, 'reference' => 'UPI-1'])->assertSessionHasNoErrors();
    $unpaid = ($this->desk)()['billing']['unpaid'];
    expect($unpaid)->toHaveCount(1)->and($unpaid[0]['outstanding'])->toEqual(300);
    ($this->asReception)()->post($unpaid[0]['links']['pay'], ['method' => 'cash', 'amount' => 300])->assertSessionHasNoErrors();

    expect((float) PatientBilling::withoutGlobalScopes()->where('encounter_id', $encounter->id)->sole()->outstanding_balance)->toBe(0.0);
});

test('a login with no staff record is told why it cannot bill, and no bill is raised', function () {
    ($this->grantDeskLedger)();
    $item = ($this->checkIn)('Ravi');
    $this->actingAs($this->doctorUser);
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->doctor);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Knee pain'], 0);
    app(EncounterWorkspace::class)->finalize($encounter->fresh(), $this->doctor);
    ClinicEmployee::withoutGlobalScopes()->where('user_id', $this->receptionUser->id)->delete();

    expect(($this->desk)()['billing']['can']['bill'])->toBeFalse();
    ($this->asReception)()->post(route('clinic.reception.billing.collect', $encounter), ['method' => 'cash', 'amount' => 500])
        ->assertRedirect()->assertSessionHasErrors(['billing' => 'Your login has no staff record at this clinic, so it cannot raise bills. Ask the administrator to add you under Staff.']);
    expect(PatientBilling::withoutGlobalScopes()->where('encounter_id', $encounter->id)->exists())->toBeFalse();
});

test('the billing preview is bounded too', function () {
    ($this->grantDeskLedger)();
    $patient = $this->f->patient('Many', 'Bills');
    foreach (range(1, 12) as $i) {
        DB::table('patient_billings')->insert([
            'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'billing_number' => "B-{$i}",
            'status' => 'pending', 'total_amount' => 100, 'final_amount' => 100, 'outstanding_balance' => 100, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $billing = ($this->desk)()['billing'];
    expect($billing['unpaid'])->toHaveCount(FrontDesk::PREVIEW)->and($billing['unpaid_count'])->toBe(12);
});

// ─── Global search ──────────────────────────────────────────

test('the top-bar patient search returns identity and a Patient 360 link only', function () {
    $patient = $this->f->patient('Ravi', 'Kumar', ['phone' => '9811122233']);

    ($this->asReception)()->getJson('/clinic/search/patients?q=98111')->assertOk()
        ->assertJsonPath('0.id', $patient->id)
        ->assertJsonPath('0.url', route('clinic.patients.show', $patient->id, false))
        ->assertJson(fn ($json) => $json->first(fn ($row) => $row->hasAll(['id', 'uhid', 'name', 'phone', 'gender', 'age', 'url'])->etc()));
    expect(array_keys(($this->asReception)()->getJson('/clinic/search/patients?q=Ravi')->json('0')))->toBe(['id', 'uhid', 'name', 'phone', 'gender', 'age', 'url']);
});
