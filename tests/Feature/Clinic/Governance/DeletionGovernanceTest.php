<?php

use App\BillingStatus;
use App\ClinicRole;
use App\Models\AttachedDocument;
use App\Models\ClinicalProcedure;
use App\Models\DeletionRequest;
use App\Models\PatientDentalChart;
use App\Models\PatientToothStatusHistory;
use App\Models\SecurityAuditLog;
use App\PatientStatus;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\Dental\DentalRecord;
use App\Services\OwnerMode\OwnerMode;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['dental']]);
    $this->patient = $this->f->patient('Asha', 'Rao');
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    [$this->adminUser, $this->adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);

    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    // Owner Mode is session state set by the step-up check; the same state the real flow writes.
    $this->asOwnerInOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => [
        'user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id, 'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID],
        'method' => 'password', 'activated_at' => now()->getTimestamp(), 'last_seen_at' => now()->getTimestamp(),
    ]]);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
    $this->asAdmin = fn () => $this->actingAs($this->adminUser)->withSession($this->adminSession);

    $this->document = AttachedDocument::create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'uploaded_by' => $this->f->owner->id,
        'file_name' => 'consent.pdf', 'file_path' => 'x/consent.pdf', 'file_size_bytes' => 10, 'document_type' => 'other', 'scan_status' => AttachedDocument::SCAN_CLEAN,
    ]);
    $this->ask = fn ($as, string $action, int $target, string $reason = 'Uploaded to the wrong patient record') => $as()->post('/clinic/approvals', ['action' => $action, 'target_id' => $target, 'reason' => $reason]);
});

test('a staff member can only ask: the record stays until the owner approves, and the approval carries it out once', function () {
    ($this->ask)($this->asDoctor, 'document.remove', $this->document->id)->assertSessionHasNoErrors();

    $request = DeletionRequest::sole();
    expect($request->status)->toBe(DeletionRequest::PENDING)
        ->and($request->requester_user_id)->toBe($this->doctorUser->id)
        ->and($request->patient_id)->toBe($this->patient->id)
        ->and(AttachedDocument::find($this->document->id))->not->toBeNull();

    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(DeletionRequest::EXECUTED)
        ->and(AttachedDocument::find($this->document->id))->toBeNull()
        ->and(AttachedDocument::withTrashed()->find($this->document->id)->removal_reason)->toBe('Uploaded to the wrong patient record')
        ->and(SecurityAuditLog::whereIn('event', ['deletion.requested', 'deletion.approved', 'deletion.executed'])->count())->toBe(3);

    // Replaying the approval does nothing a second time.
    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasNoErrors();
    expect(SecurityAuditLog::where('event', 'deletion.executed')->count())->toBe(1);
});

test('an administrator cannot act directly or approve; the owner needs Owner Mode to decide', function () {
    ($this->asAdmin)()->post('/clinic/approvals/direct', ['action' => 'patient.archive', 'target_id' => $this->patient->id, 'reason' => 'Duplicate registration of the same person'])->assertForbidden();
    ($this->ask)($this->asAdmin, 'patient.archive', $this->patient->id, 'Duplicate registration of the same person')->assertSessionHasNoErrors();
    $request = DeletionRequest::sole();

    ($this->asAdmin)()->post("/clinic/approvals/{$request->id}/approve")->assertForbidden();
    ($this->asOwner)()->post("/clinic/approvals/{$request->id}/approve")->assertForbidden();
    expect($this->patient->fresh()->status)->toBe(PatientStatus::Active);

    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasNoErrors();
    expect($this->patient->fresh()->status)->toBe(PatientStatus::Archived)
        ->and($this->patient->fresh()->archive_reason)->toBe('Duplicate registration of the same person');
});

test('a doctor cannot ask to archive a patient, and reception cannot void a consultation', function () {
    ($this->ask)($this->asDoctor, 'patient.archive', $this->patient->id)->assertForbidden();
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    ($this->ask)(fn () => $this->actingAs($receptionUser)->withSession($receptionSession), 'encounter.void', $encounter->id)->assertForbidden();
    expect(DeletionRequest::count())->toBe(0);
});

test('the owner in Owner Mode acts directly, and it is still recorded as an executed request', function () {
    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ['action' => 'document.remove', 'target_id' => $this->document->id, 'reason' => 'Scanned twice by mistake'])->assertSessionHasNoErrors();

    $request = DeletionRequest::sole();
    expect($request->status)->toBe(DeletionRequest::EXECUTED)->and($request->direct)->toBeTrue()
        ->and($request->approver_user_id)->toBe($this->f->owner->id)
        ->and(AttachedDocument::find($this->document->id))->toBeNull();
});

test('a rejected request keeps the record and its reason; a question goes back to the requester', function () {
    ($this->ask)($this->asDoctor, 'document.remove', $this->document->id)->assertSessionHasNoErrors();
    $request = DeletionRequest::sole();

    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/clarify", ['question' => 'Which patient should it belong to?'])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(DeletionRequest::CLARIFICATION);
    ($this->asDoctor)()->post("/clinic/approvals/{$request->id}/respond", ['answer' => 'Patient Ravi Kumar, UHID 0042'])->assertSessionHasNoErrors();
    expect($request->fresh()->status)->toBe(DeletionRequest::PENDING);

    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/reject", ['reason' => 'Move it instead of removing it'])->assertSessionHasNoErrors();
    expect($request->fresh())->status->toBe(DeletionRequest::REJECTED)->rejected_reason->toBe('Move it instead of removing it')
        ->and(AttachedDocument::find($this->document->id))->not->toBeNull()
        ->and(collect($request->fresh()->thread)->pluck('event')->all())->toBe(['requested', 'clarification_requested', 'responded', 'rejected']);

    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasErrors('governance');
});

test('one open request per record; a changed record cannot be approved against a stale request', function () {
    $bill = app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['clinical_procedure_id' => ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'C', 'name' => 'Consult', 'category' => 'G', 'is_active' => true])->id, 'unit_price' => 500]]);
    [$billingUser, $billingSession] = $this->f->memberWithRole(ClinicRole::BillingStaff);
    $asBilling = fn () => $this->actingAs($billingUser)->withSession($billingSession);

    ($this->ask)($asBilling, 'bill.void', $bill->id, 'Raised for the wrong procedure')->assertSessionHasNoErrors();
    ($this->ask)($asBilling, 'bill.void', $bill->id, 'Raised for the wrong procedure')->assertSessionHasErrors('governance');
    $request = DeletionRequest::sole();
    $asBilling()->get("/clinic/patients/{$this->patient->id}")->assertInertia(fn ($page) => $page->where('lens.open_requests', ["bill.void:{$bill->id}"]));

    app(PaymentService::class)->recordPayment($bill, PaymentMethod::Cash, 200);
    ($this->asOwnerInOwnerMode)()->post("/clinic/approvals/{$request->id}/approve")->assertSessionHasErrors('governance');
    expect($bill->fresh()->status)->not->toBe(BillingStatus::Cancelled)->and($request->fresh()->status)->toBe(DeletionRequest::PENDING);
});

test('another clinic cannot see, ask about or decide on this clinic\'s requests', function () {
    ($this->ask)($this->asDoctor, 'document.remove', $this->document->id)->assertSessionHasNoErrors();
    $request = DeletionRequest::sole();

    $other = ReceptionFixture::make('other-clinic');
    $otherOwnerMode = [...$other->session, OwnerMode::SESSION_KEY => [
        'user_id' => $other->owner->id, 'clinic_id' => $other->clinic->id, 'membership_id' => $other->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID],
        'method' => 'password', 'activated_at' => now()->getTimestamp(), 'last_seen_at' => now()->getTimestamp(),
    ]];
    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($other->owner)->withSession($otherOwnerMode)->post("/clinic/approvals/{$request->id}/approve")->assertNotFound();
    $this->actingAs($other->owner)->withSession($otherOwnerMode)->post('/clinic/approvals/direct', ['action' => 'document.remove', 'target_id' => $this->document->id, 'reason' => 'Trying another clinic\'s file'])->assertSessionHasErrors('governance');
    expect(AttachedDocument::withoutGlobalScopes()->find($this->document->id))->not->toBeNull()
        ->and($request->fresh()->status)->toBe(DeletionRequest::PENDING);
});

test('voiding a tooth entry keeps it in history and returns the tooth to its previous state', function () {
    $encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->actingAs($this->doctorUser);
    app(DentalRecord::class)->recordFinding($encounter, 36, 'caries', ['O'], null, $this->me);
    $wrong = app(DentalRecord::class)->recordFinding($encounter, 36, 'fractured', [], null, $this->me);

    ($this->ask)($this->asDoctor, 'tooth_entry.void', $wrong->id, 'Charted on 36 instead of 46')->assertSessionHasNoErrors();
    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/'.DeletionRequest::sole()->id.'/approve')->assertSessionHasNoErrors();

    expect(PatientToothStatusHistory::find($wrong->id)->voided_at)->not->toBeNull()
        ->and(PatientToothStatusHistory::where('tooth_number', 36)->count())->toBe(2)
        ->and(PatientDentalChart::where('patient_id', $this->patient->id)->where('tooth_number', 36)->value('status'))->toBe('caries');
});

test('a signed consultation is voided, never deleted, and leaves the clinical summary', function () {
    $workspace = app(EncounterWorkspace::class);
    $this->actingAs($this->doctorUser);
    $encounter = $workspace->startForPatient($this->patient, $this->f->branch, $this->me);
    $workspace->autosave($encounter, ['chief_complaint' => 'Wrong patient'], 0);
    $workspace->addDiagnosis($encounter, ['description' => 'Irreversible pulpitis', 'code' => 'K04.0']);
    $workspace->finalize($encounter, $this->me);

    ($this->asOwnerInOwnerMode)()->post('/clinic/approvals/direct', ['action' => 'encounter.void', 'target_id' => $encounter->id, 'reason' => 'Documented against the wrong patient'])->assertSessionHasNoErrors();

    expect($encounter->fresh()->voided_at)->not->toBeNull()->and($encounter->fresh()->isFinalized())->toBeTrue();
    ($this->asDoctor)()->get("/clinic/patients/{$this->patient->id}")
        ->assertInertia(fn ($page) => $page->where('context.conditions.diagnosed', []));
    ($this->asDoctor)()->getJson("/clinic/patients/{$this->patient->id}/360/encounters")->assertJsonPath('data.0.status', 'voided');
});

test('the approval queue shows the owner every request and anyone else only their own', function () {
    ($this->ask)($this->asDoctor, 'document.remove', $this->document->id)->assertSessionHasNoErrors();
    ($this->ask)($this->asAdmin, 'patient.archive', $this->patient->id, 'Duplicate registration of the same person')->assertSessionHasNoErrors();

    ($this->asOwner)()->get('/clinic/approvals')->assertOk()->assertInertia(fn ($page) => $page
        ->component('ClinicPortal/Approvals/Index', false)->where('owner', true)->where('ownerMode', false)->where('counts.open', 2)
        ->where('requests.data.0.can.decide', true));
    ($this->asDoctor)()->get('/clinic/approvals')->assertOk()->assertInertia(fn ($page) => $page
        ->where('owner', false)->where('counts.open', 1)->where('requests.data.0.action', 'document.remove')
        ->where('requests.data.0.can.decide', false)->where('requests.data.0.can.cancel', true));
});

test('an undecided request expires after its lifetime, freeing the record for a new request', function () {
    ($this->ask)($this->asDoctor, 'document.remove', $this->document->id)->assertSessionHasNoErrors();
    $this->travel(DeletionRequest::LIFETIME_DAYS + 1)->days();

    $this->artisan('clinic:expire-deletion-requests')->assertSuccessful();

    expect(DeletionRequest::withoutGlobalScopes()->sole())->status->toBe(DeletionRequest::EXPIRED)->open_key->toBeNull()
        ->and(AttachedDocument::find($this->document->id))->not->toBeNull();
    ($this->ask)($this->asDoctor, 'document.remove', $this->document->id)->assertSessionHasNoErrors();
});
