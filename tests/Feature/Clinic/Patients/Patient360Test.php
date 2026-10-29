<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\ClinicalProcedure;
use App\Models\ClinicMembership;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\PatientFollowUp;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\Patient\FollowUps;
use App\Services\PaymentService;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Siddharth', 'Malhotra', ['uhid' => 'UHID-2026-0099', 'age' => 32]);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->as = fn (ClinicRole $role) => (function () use ($role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return $this->actingAs($user)->withSession($session);
    })();
    $this->section = fn (string $section, ?Patient $patient = null) => '/clinic/patients/'.($patient ?? $this->patient)->id."/360/{$section}";
    $this->procedure = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CON', 'name' => 'Consultation', 'category' => 'General', 'is_active' => true]);
    $this->bill = fn (float $price, ?Patient $patient = null, ?Branch $branch = null) => app(BillingService::class)
        ->createBill($this->f->clinic, $patient ?? $this->patient, $branch ?? $this->f->branch, [['clinical_procedure_id' => $this->procedure->id, 'unit_price' => $price]]);
});

test('Patient 360 ships only the summary; the overview is deferred and every section loads from its own endpoint', function () {
    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClinicPortal/Patients/Show', false)
            ->where('patient.name', 'Siddharth Malhotra')
            ->where('patient.uhid', 'UHID-2026-0099')
            ->has('state')
            ->has('context')
            ->missing('overview')
            ->missing('timeline')
            ->missing('billing')
            ->where('endpoints.billing', route('clinic.patients.360.section', [$this->patient->id, 'billing']))
            ->where('sections', fn ($sections) => collect($sections)->pluck('key')->all() === [
                'overview', 'visits', 'clinical', 'appointments', 'treatment', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'follow-ups', 'timeline', 'communication', 'requests', 'audit',
            ])
            ->where('lens.persona', 'owner')
            ->where('lens.owner', true)
            ->loadDeferredProps(fn (Assert $reload) => $reload->has('overview.current')->has('overview.next')->has('overview.recent')));
});

test('practitioners offered for booking are real practitioners with the specialty they recorded', function () {
    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('practitioners', 2)
            ->where('practitioners.0.name', 'Dr. Asha Mehta')
            ->where('practitioners.0.specialty', 'Dentist'));
});

test('reception sees desk sections only and is refused clinical, audit and specialty endpoints', function () {
    $reception = ($this->as)(ClinicRole::Receptionist);

    $reception->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('lens.persona', 'reception')
            ->where('actions', fn ($actions) => ! collect($actions)->contains('key', 'start_consultation') && collect($actions)->contains('key', 'collect_payment') && collect($actions)->firstWhere('kind', 'primary')['key'] === 'check_in')
            ->where('context.clinical', false)
            ->where('sections', fn ($sections) => collect($sections)->pluck('key')->intersect(['clinical', 'treatment', 'prescriptions', 'dental', 'physio', 'audit'])->isEmpty()));

    foreach (['encounters', 'treatment', 'prescriptions', 'audit', 'dental', 'physio'] as $section) {
        $reception->getJson(($this->section)($section))->assertForbidden();
    }
    $reception->getJson(($this->section)('billing'))->assertOk();
    $reception->getJson(($this->section)('appointments'))->assertOk();
});

test('billing staff cannot open appointments, and nobody can ask for an unknown section', function () {
    ($this->as)(ClinicRole::BillingStaff)->getJson(($this->section)('appointments'))->assertForbidden();
    ($this->asOwner)()->getJson(($this->section)('secrets'))->assertNotFound();
});

test('the billing section totals every live bill but pages the list', function () {
    foreach (range(1, 12) as $i) {
        ($this->bill)(1000);
    }
    $paid = ($this->bill)(500);
    app(PaymentService::class)->recordPayment($paid, PaymentMethod::Cash, 500);

    ($this->asOwner)()->getJson(($this->section)('billing'))
        ->assertOk()
        ->assertJsonPath('summary.billed', 12500)
        ->assertJsonPath('summary.paid', 500)
        ->assertJsonPath('summary.outstanding', 12000)
        ->assertJsonPath('meta.total', 13)
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('payments.0.amount', 500);
});

test('a bill opens only through its own patient, and refunds need refund permission', function () {
    $bill = ($this->bill)(800);
    app(PaymentService::class)->recordPayment($bill, PaymentMethod::Cash, 800);
    $other = $this->f->patient('Other', 'Person');

    ($this->asOwner)()->getJson("/clinic/patients/{$other->id}/360/bills/{$bill->id}")->assertNotFound();
    ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$bill->id}")
        ->assertOk()
        ->assertJsonPath('items.0.description', 'Consultation')
        ->assertJsonPath('actions.pay_url', null)
        // An owner who does not treat patients refunds with no mode to enter.
        ->assertJsonPath('actions.refund_url', route('clinic.billing.refunds.store', $bill));

    // An owner who also treats patients refunds only in Owner Mode, so the refund is offered only then.
    $this->f->doctor('Dr. Owner', $this->f->owner);
    ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$bill->id}")->assertJsonPath('actions.refund_url', null);
    $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session))
        ->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$bill->id}")
        ->assertJsonPath('actions.refund_url', route('clinic.billing.refunds.store', $bill));

    $billing = ($this->as)(ClinicRole::BillingStaff);
    $billing->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$bill->id}")->assertJsonPath('actions.refund_url', null);
    $billing->post(route('clinic.billing.refunds.store', $bill), ['amount' => 100, 'reason' => 'Test'])->assertForbidden();

    // The owner refunds in Owner Mode.
    $inOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session));
    $inOwnerMode()->post(route('clinic.billing.refunds.store', $bill), ['amount' => 300])->assertSessionHasErrors('reason');
    $inOwnerMode()->post(route('clinic.billing.refunds.store', $bill), ['amount' => 300, 'reason' => 'Overcharged'])->assertSessionHasNoErrors();
    expect((float) $bill->fresh()->refunded_amount)->toBe(300.0);

    ($this->asOwner)()->get(route('clinic.billing.bills.print', $bill))->assertOk()->assertSee($bill->billing_number);
    expect(SecurityAuditLog::where('event', 'billing.printed')->count())->toBe(1);
});

test('a member restricted to one branch sees neither the bills nor the bill detail of another branch', function () {
    $other = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North Branch', 'status' => BranchStatus::Active]);
    $mine = ($this->bill)(100);
    $theirs = ($this->bill)(900, null, $other);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
        'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active,
    ]);
    $this->f->memberWithRole(ClinicRole::BillingStaff); // ensures the role exists
    $membership->assignBranch($this->f->branch);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::BillingStaff->value], [$this->f->branch]);
    $session = [ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $this->f->branch->id];

    $this->actingAs($user)->withSession($session)->getJson(($this->section)('billing'))
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
    $this->actingAs($user)->withSession($session)->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$theirs->id}")->assertNotFound();
});

test('follow-ups can be added, rescheduled and completed, and only through their own patient', function () {
    $doctor = ($this->as)(ClinicRole::Doctor);
    $doctor->post("/clinic/patients/{$this->patient->id}/follow-ups", ['due_on' => '2026-09-30'])->assertSessionHasErrors();
    $doctor->post("/clinic/patients/{$this->patient->id}/follow-ups", ['due_on' => '2026-10-20', 'reason' => 'Review', 'practitioner_id' => $this->f->doctorA->id])->assertSessionHasNoErrors();

    $followUp = PatientFollowUp::where('patient_id', $this->patient->id)->sole();
    expect($followUp->status)->toBe(PatientFollowUp::DUE)->and($followUp->clinic_id)->toBe($this->f->clinic->id);

    $other = $this->f->patient('Other', 'Person');
    $doctor->post("/clinic/patients/{$other->id}/follow-ups/{$followUp->id}/complete")->assertNotFound();

    $doctor->patch("/clinic/patients/{$this->patient->id}/follow-ups/{$followUp->id}", ['due_on' => '2026-10-22'])->assertSessionHasNoErrors();
    expect($followUp->fresh()->due_on->toDateString())->toBe('2026-10-22');

    $doctor->getJson(($this->section)('follow-ups'))
        ->assertJsonPath('data.0.reason', 'Review')
        ->assertJsonPath('data.0.practitioner', 'Dr. Asha Mehta')
        ->assertJsonPath('data.0.overdue', false);

    $doctor->post("/clinic/patients/{$this->patient->id}/follow-ups/{$followUp->id}/complete")->assertSessionHasNoErrors();
    expect($followUp->fresh()->status)->toBe(PatientFollowUp::COMPLETED);
    $doctor->getJson(($this->section)('follow-ups'))->assertJsonPath('data.0.actions', null);
});

test('a member who neither treats nor books cannot manage follow-ups', function () {
    $followUp = app(FollowUps::class)->add($this->patient, '2026-10-20', null, null, $this->f->branch->id);
    $billing = ($this->as)(ClinicRole::BillingStaff);

    $billing->post("/clinic/patients/{$this->patient->id}/follow-ups", ['due_on' => '2026-10-20'])->assertForbidden();
    $billing->post("/clinic/patients/{$this->patient->id}/follow-ups/{$followUp->id}/cancel")->assertForbidden();
    $billing->getJson(($this->section)('follow-ups'))->assertJsonPath('add_url', null)->assertJsonPath('data.0.actions', null);
});

test('messages never read as sent without a real provider', function () {
    config(['clinic_messages.provider' => null]);
    $followUp = app(FollowUps::class)->add($this->patient, '2026-10-20', 'Review', null, $this->f->branch->id);
    $message = Notification::where('event_key', "follow_up:{$followUp->id}:20261020")->sole();

    ($this->asOwner)()->getJson(($this->section)('communications'))
        ->assertJsonPath('provider_connected', false)
        ->assertJsonPath('data.0.status', 'scheduled');

    $message->update(['status' => 'delivered', 'sent_at' => now(), 'channel' => 'sms']);
    ($this->asOwner)()->getJson(($this->section)('communications'))->assertJsonPath('data.0.status', 'simulated');

    // WhatsApp is delivered for real on the clinic's number.
    $message->update(['channel' => 'whatsapp']);
    ($this->asOwner)()->getJson(($this->section)('communications'))->assertJsonPath('data.0.status', 'sent');

    $message->update(['status' => 'failed', 'failed_reason' => 'Invalid number']);
    ($this->asOwner)()->getJson(($this->section)('communications'))
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.failed_reason', 'Invalid number');
});

test('the dental workspace exists only where the clinic practises dentistry', function () {
    ($this->asOwner)()->getJson(($this->section)('dental'))->assertForbidden();
    ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/dental/teeth/16")->assertForbidden();

    $this->f->clinic->update(['specialties' => ['dental']]);

    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn (Assert $page) => $page->where('sections', fn ($sections) => collect($sections)->contains('key', 'dental') && ! collect($sections)->contains('key', 'physio')));
    ($this->asOwner)()->getJson(($this->section)('dental'))
        ->assertOk()
        ->assertJsonPath('teeth', [])
        ->assertJsonPath('open_consultation', null)
        ->assertJsonPath('links.chart', null)
        ->assertJsonPath('view', 'clinical')
        ->assertJsonPath('conditions.caries.surface', true);
    ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/dental/teeth/16")->assertOk()->assertJsonPath('history', [])->assertJsonPath('current', null);
});

test('the audit trail is for owners and administrators only', function () {
    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}");

    ($this->asOwner)()->getJson(($this->section)('audit'))->assertOk()->assertJsonPath('data.0.event', 'patient.viewed');
    ($this->as)(ClinicRole::Doctor)->getJson(($this->section)('audit'))->assertForbidden();
    ($this->as)(ClinicRole::ClinicAdmin)->getJson(($this->section)('audit'))->assertOk();
});

test('a document uploads securely, previews inline and downloads only through its authorized route', function () {
    Storage::fake('private');

    ($this->asOwner)()->post("/clinic/patients/{$this->patient->id}/documents", [
        'document' => UploadedFile::fake()->create('blood_test.pdf', 500, 'application/pdf'),
        'title' => 'Routine Blood Panel',
        'document_type' => 'medical_report',
    ])->assertRedirect()->assertSessionHas('success');

    $doc = AttachedDocument::where('patient_id', $this->patient->id)->sole();
    expect($doc->file_name)->toBe('Routine Blood Panel')->and($doc->clinic_id)->toBe($this->f->clinic->id);

    $doc->update(['scan_status' => AttachedDocument::SCAN_CLEAN]);
    Storage::disk('private')->put($doc->file_path, 'pdf');

    ($this->asOwner)()->getJson(($this->section)('documents'))
        ->assertJsonPath('data.0.name', 'Routine Blood Panel')
        ->assertJsonPath('data.0.preview_url', route('clinic.patients.documents.download', [$this->patient->id, $doc->id, 'inline' => 1]));
    $preview = ($this->asOwner)()->get(route('clinic.patients.documents.download', [$this->patient->id, $doc->id, 'inline' => 1]))
        ->assertOk();
    expect($preview->headers->get('content-disposition'))->toStartWith('inline');
    expect(SecurityAuditLog::where('event', 'document.previewed')->count())->toBe(1);

    $other = $this->f->patient('Other', 'Person');
    ($this->asOwner)()->get(route('clinic.patients.documents.download', [$other->id, $doc->id]))->assertNotFound();
    $this->actingAs($this->f->owner)->get('/files/download?path='.base64_encode($doc->file_path).'&disk=private')->assertNotFound();
});

test('another clinic\'s patient is invisible on the page and on every section endpoint', function () {
    $foreign = ReceptionFixture::make('foreign-clinic');
    $foreignPatient = $foreign->patient('Foreign', 'Patient');
    $foreignBill = app(BillingService::class)->createBill($foreign->clinic, $foreignPatient, $foreign->branch, [[
        'clinical_procedure_id' => ClinicalProcedure::create(['clinic_id' => $foreign->clinic->id, 'code' => 'X', 'name' => 'X', 'category' => 'X', 'is_active' => true])->id, 'unit_price' => 10,
    ]]);
    app()->instance('enforce_tenant_scope_in_tests', true);

    $responses = collect([
        ($this->asOwner)()->get("/clinic/patients/{$foreignPatient->id}"),
        ...collect(['timeline', 'encounters', 'billing', 'documents', 'follow-ups', 'communications', 'audit'])
            ->map(fn ($section) => ($this->asOwner)()->getJson(($this->section)($section, $foreignPatient))),
        ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$foreignBill->id}"),
        ($this->asOwner)()->get(route('clinic.billing.bills.print', $foreignBill)),
        ($this->asOwner)()->post(route('clinic.billing.refunds.store', $foreignBill), ['amount' => 1, 'reason' => 'x']),
    ]);

    expect($responses->map->status()->reject(fn ($status) => in_array($status, [403, 404], true))->all())->toBe([]);
});
