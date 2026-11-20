<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalDiagnosis;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalEncounterAddendum;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\ClinicalProcedure;
use App\Models\ClinicMembership;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\QueueItemStatus;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\QueueStateMachine;
use App\Services\Reception\ReceptionCheckIn;
use App\UserType;
use App\VisitStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    [$this->doctorUser, $this->doctorSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Dr. Me', $this->doctorUser);
    $this->patient = $this->f->patient('Rahul', 'Sharma');
    $this->item = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->patient, doctor: $this->me);
    app(QueueStateMachine::class)->call($this->item, $this->me);
    $this->asDoctor = fn () => $this->actingAs($this->doctorUser)->withSession($this->doctorSession);
});

function consultationFor(object $test): ClinicalEncounter
{
    ($test->asDoctor)()->post("/clinic/consultations/queue/{$test->item->id}")->assertRedirect();

    return ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $test->patient->id)->firstOrFail();
}

test('a doctor takes their called patient in and the consultation opens on the canonical encounter', function () {
    $response = ($this->asDoctor)()->post("/clinic/consultations/queue/{$this->item->id}");

    $encounter = ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $this->patient->id)->sole();
    $response->assertRedirect("/clinic/consultations/{$encounter->id}");

    expect($encounter->encounter_number)->toBe('ENC-2627-000001')
        ->and($encounter->clinic_id)->toBe($this->f->clinic->id)
        ->and($encounter->branch_id)->toBe($this->f->branch->id)
        ->and($encounter->doctor_id)->toBe($this->me->id)
        ->and($encounter->patient_visit_id)->toBe($this->item->patient_visit_id)
        ->and($encounter->status)->toBe(VisitStatus::InProgress)
        ->and($this->item->fresh()->status)->toBe(QueueItemStatus::InConsultation)
        ->and($this->item->fresh()->encounter_id)->toBe($encounter->id);
});

test('taking the same patient in twice reopens the one consultation', function () {
    $first = consultationFor($this);

    ($this->asDoctor)()->post("/clinic/consultations/queue/{$this->item->id}")->assertRedirect("/clinic/consultations/{$first->id}");

    expect(ClinicalEncounter::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count())->toBe(1);
});

test('a doctor cannot take in a patient queued for another doctor', function () {
    $theirs = app(ReceptionCheckIn::class)->checkIn($this->f->clinic, $this->f->branch, $this->f->patient('Other', 'Patient'), doctor: $this->f->doctorA);

    ($this->asDoctor)()->post("/clinic/consultations/queue/{$theirs->id}")->assertForbidden();

    expect(ClinicalEncounter::withoutGlobalScopes()->count())->toBe(0);
});

test('the consultation screen shows the patient, the record and what the doctor may do', function () {
    $encounter = consultationFor($this);

    ($this->asDoctor)()->get("/clinic/consultations/{$encounter->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClinicPortal/Consultations/Workspace', false)
            ->where('encounter.number', 'ENC-2627-000001')
            ->where('encounter.is_finalized', false)
            ->where('patient.name', 'Rahul Sharma')
            ->where('can.edit', true)
            ->where('can.finalize', true)
            ->where('can.addendum', false));
});

test('autosave keeps the draft, moves the version on and leaves no audit entry per keystroke', function () {
    $encounter = consultationFor($this);
    $auditsBefore = SecurityAuditLog::count();

    ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", [
        'lock_version' => 0,
        'chief_complaint' => 'Pain in lower left molar',
    ])->assertOk()->assertJson(['lock_version' => 1]);

    $encounter->refresh();
    expect($encounter->chief_complaint)->toBe('Pain in lower left molar')
        ->and($encounter->last_autosaved_at)->not->toBeNull()
        ->and(SecurityAuditLog::count())->toBe($auditsBefore);
});

test('a draft saved over a version the screen had not seen is refused, not overwritten', function () {
    $encounter = consultationFor($this);
    ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'history' => 'From tab one'])->assertOk();

    ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'history' => 'From tab two'])
        ->assertStatus(409)
        ->assertJson(['lock_version' => 1]);

    expect($encounter->fresh()->history)->toBe('From tab one');
});

// Vitals were removed from the product: the consultation no longer records them.
test('vitals sent with a draft are ignored, and a consultation never starts with any', function () {
    $encounter = consultationFor($this);
    expect($encounter->vitals)->toBeNull();

    ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'history' => 'Draft', 'vitals' => ['pulse' => 76]])
        ->assertOk();

    expect($encounter->fresh()->vitals)->toBeNull()->and($encounter->fresh()->history)->toBe('Draft');
});

test('the first diagnosis is primary, a new primary replaces it, and removing it promotes the next', function () {
    $encounter = consultationFor($this);
    $add = fn (array $data) => ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/diagnoses", $data + ['status' => 'provisional'])->assertSessionHasNoErrors();

    $add(['description' => 'Dental caries', 'code' => 'K02.1']);
    $add(['description' => 'Gingivitis']);
    $add(['description' => 'Irreversible pulpitis', 'code' => 'K04.0', 'is_primary' => true]);

    $primary = fn () => $encounter->diagnoses()->where('is_primary', true)->pluck('description')->all();
    expect($primary())->toBe(['Irreversible pulpitis']);

    $pulpitis = $encounter->diagnoses()->where('description', 'Irreversible pulpitis')->first();
    ($this->asDoctor)()->delete("/clinic/consultations/{$encounter->id}/diagnoses/{$pulpitis->id}")->assertSessionHasNoErrors();

    expect($primary())->toBe(['Dental caries'])
        ->and($encounter->diagnoses()->first()->clinic_id)->toBe($this->f->clinic->id);
});

test('a diagnosis from another consultation cannot be removed through this one', function () {
    $encounter = consultationFor($this);
    $other = app(EncounterWorkspace::class)->startForPatient($this->f->patient('Second', 'Patient'), $this->f->branch, $this->me);
    $theirs = app(EncounterWorkspace::class)->addDiagnosis($other, ['description' => 'Fracture']);

    ($this->asDoctor)()->delete("/clinic/consultations/{$encounter->id}/diagnoses/{$theirs->id}")->assertNotFound();

    expect(ClinicalDiagnosis::withoutGlobalScopes()->whereKey($theirs->id)->exists())->toBeTrue();
});

test('procedures come only from the clinic\'s own service catalogue', function () {
    $encounter = consultationFor($this);
    $mine = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'RCT', 'name' => 'Root canal treatment', 'category' => 'Endodontic', 'is_active' => true]);
    $foreign = ClinicalProcedure::withoutGlobalScopes()->create(['clinic_id' => Clinic::factory()->create()->id, 'code' => 'X', 'name' => 'Other clinic service', 'category' => 'General', 'is_active' => true]);

    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $mine->id, 'quantity' => 1])->assertSessionHasNoErrors();
    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/procedures", ['clinical_procedure_id' => $foreign->id])
        ->assertSessionHasErrors('consultation');

    expect($encounter->procedures()->pluck('name')->all())->toBe(['Root canal treatment'])
        ->and($encounter->procedures()->first()->clinical_procedure_id)->toBe($mine->id);
});

test('a consultation cannot be signed with neither a complaint nor a diagnosis', function () {
    $encounter = consultationFor($this);

    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")
        ->assertSessionHasErrors(['consultation' => 'Record the chief complaint or a diagnosis before signing.']);

    expect($encounter->fresh()->isFinalized())->toBeFalse();
});

test('signing fixes the record, ends the consultation and the queue entry, and is audited', function () {
    $encounter = consultationFor($this);
    $workspace = app(EncounterWorkspace::class);
    $this->actingAs($this->doctorUser);
    $workspace->autosave($encounter, ['chief_complaint' => 'Toothache'], 0);
    $workspace->addNote($encounter, 'general', 'Advised warm saline rinses.');

    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/finalize")
        ->assertRedirect("/clinic/consultations/{$encounter->id}");

    $encounter->refresh();
    expect($encounter->status)->toBe(VisitStatus::Completed)
        ->and($encounter->finalized_by)->toBe($this->me->id)
        ->and($encounter->finalized_at)->not->toBeNull()
        ->and($encounter->signature_hash)->toHaveLength(64)
        ->and($workspace->signatureIsIntact($encounter))->toBeTrue()
        ->and($encounter->clinicalNotes()->first()->status)->toBe('signed')
        ->and($this->item->fresh()->status)->toBe(QueueItemStatus::Completed)
        ->and(SecurityAuditLog::where('event', 'encounter.finalized')->where('subject_id', $encounter->id)->exists())->toBeTrue()
        ->and($this->patient->timelineEvents()->where('event_type', 'encounter_finalized')->exists())->toBeTrue();

    ($this->asDoctor)()->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => $encounter->lock_version, 'history' => 'Changed later'])->assertForbidden();
    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/diagnoses", ['description' => 'Late', 'status' => 'confirmed'])->assertForbidden();
});

test('a change made to a signed record outside the workspace breaks its signature', function () {
    $encounter = consultationFor($this);
    $this->actingAs($this->doctorUser);
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($encounter, ['chief_complaint' => 'Toothache'], 0);
    $signed = $workspace->finalize($encounter, $this->me);

    DB::table('clinical_encounters')->where('id', $signed->id)->update(['chief_complaint' => 'Edited directly']);

    expect($workspace->signatureIsIntact($signed->fresh()))->toBeFalse();
});

test('after signing, a colleague may add an addendum but only the signer may amend', function () {
    $encounter = consultationFor($this);
    $this->actingAs($this->doctorUser);
    $workspace = app(EncounterWorkspace::class);
    $workspace->autosave($encounter, ['chief_complaint' => 'Toothache', 'examination' => 'Caries 36'], 0);
    $workspace->finalize($encounter, $this->me);

    [$colleagueUser, $colleagueSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctor('Dr. Colleague', $colleagueUser);

    $this->actingAs($colleagueUser)->withSession($colleagueSession)
        ->post("/clinic/consultations/{$encounter->id}/addenda", ['kind' => 'addendum', 'content' => 'Patient phoned: pain settled.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($colleagueUser)->withSession($colleagueSession)
        ->post("/clinic/consultations/{$encounter->id}/addenda", ['kind' => 'amendment', 'section' => 'examination', 'content' => 'Caries 37', 'reason' => 'Wrong tooth'])
        ->assertSessionHasErrors(['consultation' => 'Only the doctor who signed this consultation can amend it.']);

    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/addenda", ['kind' => 'amendment', 'section' => 'examination', 'content' => 'Caries 37', 'reason' => 'Wrong tooth recorded'])
        ->assertSessionHasNoErrors();

    $amendment = $encounter->addenda()->where('kind', 'amendment')->sole();
    expect($amendment->previous_content)->toBe('Caries 36')
        ->and($amendment->content_hash)->toHaveLength(64)
        ->and($workspace->effectiveNarrative($encounter->fresh())['examination'])->toBe('Caries 37')
        ->and($encounter->fresh()->examination)->toBe('Caries 36')
        ->and($workspace->signatureIsIntact($encounter->fresh()))->toBeTrue()
        ->and(SecurityAuditLog::where('event', 'encounter.amendment')->exists())->toBeTrue()
        ->and(fn () => $amendment->update(['content' => 'Rewritten']))->toThrow(LogicException::class);
});

test('an amendment needs a reason and a section', function () {
    $encounter = consultationFor($this);
    $this->actingAs($this->doctorUser);
    app(EncounterWorkspace::class)->autosave($encounter, ['chief_complaint' => 'Toothache'], 0);
    app(EncounterWorkspace::class)->finalize($encounter, $this->me);

    ($this->asDoctor)()->post("/clinic/consultations/{$encounter->id}/addenda", ['kind' => 'amendment', 'content' => 'x'])
        ->assertSessionHasErrors(['section', 'reason']);

    expect(ClinicalEncounterAddendum::count())->toBe(0);
});

test('another doctor can read the consultation but not write it', function () {
    $encounter = consultationFor($this);
    [$colleagueUser, $colleagueSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctor('Dr. Colleague', $colleagueUser);

    $this->actingAs($colleagueUser)->withSession($colleagueSession)->get("/clinic/consultations/{$encounter->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.edit', false)->where('can.finalize', false));
    $this->actingAs($colleagueUser)->withSession($colleagueSession)
        ->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'history' => 'Not mine'])
        ->assertForbidden();
});

test('reception, without clinical access, cannot open consultations', function () {
    $encounter = consultationFor($this);
    [$receptionUser, $receptionSession] = $this->f->memberWithRole(ClinicRole::Receptionist);

    $this->actingAs($receptionUser)->withSession($receptionSession)->get('/clinic/consultations')->assertForbidden();
    $this->actingAs($receptionUser)->withSession($receptionSession)->get("/clinic/consultations/{$encounter->id}")->assertForbidden();
});

test('another clinic\'s consultation does not exist for this clinic', function () {
    $encounter = consultationFor($this);
    $other = ReceptionFixture::make('other-clinic');
    [$otherUser, $otherSession] = $other->memberWithRole(ClinicRole::Doctor);
    $other->doctor('Dr. Elsewhere', $otherUser);
    app()->instance('enforce_tenant_scope_in_tests', true);

    $this->actingAs($otherUser)->withSession($otherSession)->get("/clinic/consultations/{$encounter->id}")->assertNotFound();
    $this->actingAs($otherUser)->withSession($otherSession)
        ->patchJson("/clinic/consultations/{$encounter->id}/draft", ['lock_version' => 0, 'history' => 'x'])->assertNotFound();
});

test('a member without access to the consultation\'s branch cannot open it', function () {
    $encounter = consultationFor($this);
    $east = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active,
    ]);
    $membership->assignBranch($east);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Doctor->value], [$east]);
    $this->f->doctor('Dr. East', $user);

    $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $east->id])
        ->get("/clinic/consultations/{$encounter->id}")
        ->assertForbidden();
});

test('Today shows my open consultations as in consultation and what I signed today as completed', function () {
    $open = consultationFor($this);
    $this->actingAs($this->doctorUser);
    $signed = app(EncounterWorkspace::class)->startForPatient($this->f->patient('Signed', 'Today'), $this->f->branch, $this->me);
    app(EncounterWorkspace::class)->autosave($signed, ['chief_complaint' => 'Review'], 0);
    app(EncounterWorkspace::class)->finalize($signed, $this->me);

    ($this->asDoctor)()->get('/clinic/consultations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ClinicPortal/Today/Index', false)
            ->where('is_doctor', true)
            ->has('today.in_consultation', 1)
            ->where('today.in_consultation.0.encounter.id', $open->id)
            ->has('today.completed', 1)
            ->where('today.completed.0.encounter.id', $signed->id));
});

test('a document is filed against a consultation only if it is that patient\'s', function () {
    Storage::fake('private');
    $encounter = consultationFor($this);
    $stranger = app(EncounterWorkspace::class)->startForPatient($this->f->patient('Someone', 'Else'), $this->f->branch, $this->me);
    $upload = fn (int $encounterId) => $this->actingAs($this->f->owner)->withSession($this->f->session)
        ->post("/clinic/patients/{$this->patient->id}/documents", [
            'document' => UploadedFile::fake()->create('opg.pdf', 100, 'application/pdf'), 'document_type' => 'xray', 'encounter_id' => $encounterId,
        ]);

    $upload($stranger->id)->assertNotFound();
    $upload($encounter->id)->assertRedirect();

    expect(AttachedDocument::where('patient_id', $this->patient->id)->sole()->encounter_id)->toBe($encounter->id);
});

test('Today lists the results back for my orders that wait for my review, and only mine', function () {
    $encounter = consultationFor($this);
    $order = fn (int $by) => ClinicalOrder::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'encounter_id' => $encounter->id, 'patient_id' => $this->patient->id,
        'ordered_by' => $by, 'order_number' => 'ORD-'.fake()->unique()->numerify('###'), 'order_type' => 'lab', 'status' => 'in_progress',
    ]);
    $item = fn ($order, string $name, string $status) => ClinicalOrderItem::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'clinical_order_id' => $order->id, 'item_name' => $name, 'status' => $status, 'resulted_at' => now()->subDay(),
    ]);
    $mine = $order($this->doctorUser->id);
    $item($mine, 'HbA1c', ClinicalOrderItem::RESULTED);
    $item($mine, 'CBC', 'reviewed');
    $item($order(User::factory()->create()->id), 'Lipid profile', ClinicalOrderItem::RESULTED);

    ($this->asDoctor)()->get('/clinic/consultations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.total', 1)
            ->where('results.rows.0.test', 'HbA1c')
            ->where('results.rows.0.url', route('clinic.orders.show', $mine->id)));
});
