<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\CatalogPriceVersion;
use App\Models\CatalogTreatment;
use App\Models\CatalogTreatmentVersion;
use App\Models\ClinicalProcedure;
use App\Models\ClinicMembership;
use App\Models\PatientTreatment;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\OwnerMode\OwnerMode;
use App\Services\Treatment\PatientTreatments;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Support\TreatmentFixture;

/**
 * The Phase 4B treatment API: catalog (T1–T4), pricing (T5), specialties
 * (T6), start (T7), detail and execution (T8), the board (T9) — each one
 * authorised on the server, tenant- and branch-bound.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->patient = $this->f->patient('Rajesh', 'Patel');

    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asOwnerInOwnerMode = fn () => $this->actingAs($this->f->owner)->withSession([...$this->f->session, OwnerMode::SESSION_KEY => [
        'user_id' => $this->f->owner->id, 'clinic_id' => $this->f->clinic->id, 'membership_id' => $this->f->session[ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID],
        'method' => 'password', 'activated_at' => now()->getTimestamp(), 'last_seen_at' => now()->getTimestamp(),
    ]]);
    $this->as = function (ClinicRole $role, $employee = null) {
        [$user, $session] = $this->f->memberWithRole($role);
        $employee?->update(['user_id' => $user->id]);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->catalog = '/clinic/admin/catalog';
    $this->stages = [
        ['name' => 'Access opening', 'procedures' => [['clinical_procedure_id' => $this->t->procedures['ACC']->id]]],
        ['name' => 'Obturation', 'procedures' => [['clinical_procedure_id' => $this->t->procedures['OBT']->id]]],
    ];
});

test('the catalog is built over the API: draft, edit, price, publish, new version — every write audited', function () {
    $id = ($this->asOwner)()->postJson("{$this->catalog}/treatments", ['specialty_key' => 'dental', 'code' => 'RCT', 'name' => 'Root Canal Treatment'])
        ->assertCreated()->json('data.id');

    $draft = ($this->asOwner)()->postJson("{$this->catalog}/treatments/{$id}/versions", ['treatment_type' => 'multi_stage', 'charging_model' => 'upfront', 'targets' => ['tooth'], 'stages' => $this->stages])
        ->assertCreated()->json('data.id');
    ($this->asOwner)()->putJson("{$this->catalog}/treatments/{$id}/versions/{$draft}", ['treatment_type' => 'multi_stage', 'charging_model' => 'upfront', 'targets' => ['tooth'], 'stages' => [...$this->stages, ['name' => 'Crown prep']]])
        ->assertOk();
    ($this->asOwner)()->postJson("{$this->catalog}/prices", ['target' => 'catalog_treatment_version_id', 'target_id' => $draft, 'amount' => 8000])->assertCreated();
    ($this->asOwner)()->postJson("{$this->catalog}/treatments/{$id}/versions/{$draft}/publish")->assertOk()->assertJsonPath('data.status', 'published');

    // A published version is frozen; a change is a new version.
    ($this->asOwner)()->putJson("{$this->catalog}/treatments/{$id}/versions/{$draft}", ['treatment_type' => 'single', 'charging_model' => 'upfront', 'stages' => $this->stages])
        ->assertUnprocessable();
    $v2 = ($this->asOwner)()->postJson("{$this->catalog}/treatments/{$id}/versions", ['from_version_id' => $draft])->assertCreated()->json('data.id');

    ($this->asOwner)()->getJson("{$this->catalog}/treatments/{$id}")->assertOk()
        ->assertJsonPath('data.current_version_id', $draft)
        ->assertJsonPath('data.versions.0.targets', ['tooth'])
        ->assertJsonPath('data.versions.0.price.amount', '8000.00')
        ->assertJsonCount(3, 'data.versions.0.stages')
        ->assertJsonPath('data.versions.1.id', $v2)
        ->assertJsonPath('data.versions.1.status', 'draft');

    expect(SecurityAuditLog::whereIn('event', ['catalog.treatment.created', 'catalog.price.set'])->count())->toBe(2);
});

test('targets come from the specialty extension: dental may name a tooth, physiotherapy may not', function () {
    $physio = ($this->asOwner)()->postJson("{$this->catalog}/treatments", ['specialty_key' => 'physiotherapy', 'code' => 'LBR', 'name' => 'Back rehab'])->json('data.id');

    ($this->asOwner)()->postJson("{$this->catalog}/treatments/{$physio}/versions", [
        'treatment_type' => 'session_based', 'charging_model' => 'per_procedure', 'targets' => ['tooth'],
        'stages' => [['name' => 'Sessions', 'procedures' => [['clinical_procedure_id' => $this->t->procedures['PHY']->id]]]],
    ])->assertUnprocessable();
    ($this->asOwner)()->postJson("{$this->catalog}/procedures", ['code' => 'MOB', 'name' => 'Mobilisation', 'category' => 'Manual therapy', 'specialty_key' => 'physiotherapy', 'targets' => ['tooth']])
        ->assertUnprocessable();

    $procedure = ($this->asOwner)()->postJson("{$this->catalog}/procedures", ['code' => 'CRN', 'name' => 'Crown', 'category' => 'Prosthodontic', 'specialty_key' => 'dental', 'targets' => ['tooth']])
        ->assertCreated()->assertJsonPath('data.targets', ['tooth'])->json('data.id');

    expect(ClinicalProcedure::find($procedure)->metadata['targets'])->toBe(['tooth']);

    // Versions migrated from the old flag carry the same declaration.
    $version = $this->t->rct();
    expect($version->targets())->toBe(['tooth'])
        ->and($this->t->rct(requiresTooth: false)->targets())->toBe([]);
});

test('procedures are edited and retired, never deleted; the list is keyset-paged and prefix-searched', function () {
    $id = $this->t->procedures['FIL']->id;
    ($this->asOwner)()->putJson("{$this->catalog}/procedures/{$id}", ['code' => 'FIL', 'name' => 'Composite filling (2 surfaces)', 'specialty_key' => 'dental'])->assertOk();
    ($this->asOwner)()->postJson("{$this->catalog}/procedures/{$id}/active", ['active' => false])->assertOk();

    expect(ClinicalProcedure::find($id))->name->toBe('Composite filling (2 surfaces)')->is_active->toBeFalse();

    ($this->asOwner)()->getJson("{$this->catalog}/procedures?q=Comp")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    ($this->asOwner)()->getJson("{$this->catalog}/procedures?q=filling")->assertOk()->assertJsonCount(0, 'data');
    ($this->asOwner)()->getJson("{$this->catalog}/procedures?active=1")->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('next_after_id', null);
});

test('a price is never edited: a change closes the open version, cannot start in the past, and needs catalog.manage', function () {
    $procedure = $this->t->procedures['FIL']->id;
    $set = fn ($as, array $extra = []) => $as()->postJson("{$this->catalog}/prices", ['target' => 'clinical_procedure_id', 'target_id' => $procedure, 'amount' => 1500, ...$extra]);

    $set($this->asOwner)->assertCreated();
    $set($this->asOwner, ['amount' => 1800])->assertCreated();
    $set($this->asOwner, ['amount' => 2000, 'effective_from' => '2026-09-01'])->assertUnprocessable();
    $set(($this->as)(ClinicRole::Receptionist))->assertForbidden();
    $set(($this->as)(ClinicRole::Doctor))->assertForbidden();

    ($this->asOwner)()->getJson("{$this->catalog}/prices?target=clinical_procedure_id&target_id={$procedure}")->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.amount', '1800.00')->assertJsonPath('data.0.effective_to', null)
        ->assertJsonPath('data.1.amount', '1500.00');
    expect(CatalogPriceVersion::whereNull('effective_to')->where('clinical_procedure_id', $procedure)->count())->toBe(1);
});

test('only the owner, in Owner Mode, changes the clinic specialties; deactivating keeps history', function () {
    // The owner works here as a doctor too: Doctor Mode until they enter Owner Mode.
    $this->f->doctor('Dr. Owner', $this->f->owner);
    ($this->as)(ClinicRole::ClinicAdmin)()->putJson('/clinic/admin/specialties', ['specialties' => ['dental']])->assertForbidden();
    ($this->asOwner)()->putJson('/clinic/admin/specialties', ['specialties' => ['dental']])->assertStatus(403);
    ($this->asOwnerInOwnerMode)()->putJson('/clinic/admin/specialties', ['specialties' => ['unknown-thing']])->assertUnprocessable();

    ($this->asOwnerInOwnerMode)()->putJson('/clinic/admin/specialties', ['specialties' => ['dental']])->assertOk()->assertJsonPath('data.active', ['dental']);

    ($this->asOwner)()->getJson('/clinic/admin/specialties')->assertOk()
        ->assertJsonFragment(['key' => 'dental', 'active' => true, 'targets' => ['tooth']])
        ->assertJsonFragment(['key' => 'physiotherapy', 'status' => 'inactive', 'active' => false]);
});

test('catalog and specialty endpoints are closed to staff without catalog.manage', function () {
    $reception = ($this->as)(ClinicRole::Receptionist);
    $reception()->getJson("{$this->catalog}/treatments")->assertForbidden();
    $reception()->postJson("{$this->catalog}/treatments", ['specialty_key' => 'dental', 'code' => 'X', 'name' => 'X'])->assertForbidden();
    $reception()->getJson("{$this->catalog}/procedures")->assertForbidden();
    $reception()->getJson('/clinic/admin/specialties')->assertForbidden();
});

test('a practitioner starts treatment from what they practise; the declared target is required', function () {
    $this->t->rct();
    $this->t->backRehab();
    $dentist = ($this->as)(ClinicRole::Doctor, $this->f->doctorA);
    $version = CatalogTreatmentVersion::query()->whereHas('treatment', fn ($q) => $q->where('code', 'RCT'))->value('id');

    $dentist()->getJson("/clinic/patients/{$this->patient->id}/treatments/options")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Root Canal Treatment')
        ->assertJsonPath('data.0.targets.0.key', 'tooth')
        // The catalogue price list is the ledger's; a practitioner holds only their own fees.
        ->assertJsonMissingPath('data.0.price');
    $ownerOptions = collect(($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/treatments/options")->assertOk()->json('data'))->keyBy('name');
    expect($ownerOptions['Root Canal Treatment']['price'])->toEqual(8000);

    $dentist()->postJson("/clinic/patients/{$this->patient->id}/treatments", ['version_id' => $version])
        ->assertUnprocessable()->assertJsonPath('message', 'Which tooth is this treatment for?');
    $id = $dentist()->postJson("/clinic/patients/{$this->patient->id}/treatments", ['version_id' => $version, 'targets' => ['tooth' => [['tooth_number' => 46]]]])
        ->assertCreated()
        ->assertJsonPath('data.targets.tooth.0.tooth_number', 46)
        ->assertJsonPath('data.practitioner.id', $this->f->doctorA->id)
        ->json('data.id');

    expect(PatientTreatment::find($id))->branch_id->toBe($this->f->branch->id)->encounter_id->toBeNull();

    // The physiotherapist is offered only physiotherapy and cannot start a dental treatment.
    $physio = ($this->as)(ClinicRole::Doctor, $this->t->physio);
    $physio()->getJson("/clinic/patients/{$this->patient->id}/treatments/options")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.specialty', 'physiotherapy');
    $physio()->postJson("/clinic/patients/{$this->patient->id}/treatments", ['version_id' => $version, 'targets' => ['tooth' => [['tooth_number' => 46]]]])->assertForbidden();

    // Reception cannot start treatment at all.
    ($this->as)(ClinicRole::Receptionist)()->postJson("/clinic/patients/{$this->patient->id}/treatments", ['version_id' => $version])->assertForbidden();
});

test('treatment is done over the API by a practitioner; reception reads it but cannot record it', function () {
    $dentist = ($this->as)(ClinicRole::Doctor, $this->f->doctorA);
    $treatment = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    [$first, $second] = $treatment->stages->all();
    $base = "/clinic/treatments/{$treatment->id}";

    $dentist()->postJson("{$base}/stages/{$second->id}/complete")->assertUnprocessable();
    $dentist()->postJson("{$base}/stages/{$first->id}/start")->assertOk()->assertJsonPath('data.stages.0.status', 'in_progress');
    $dentist()->postJson("{$base}/stages/{$first->id}/complete")->assertOk()->assertJsonPath('data.progress.done', 1);
    $dentist()->postJson("{$base}/pause", ['reason' => 'Patient travelling'])->assertOk()->assertJsonPath('data.status', 'paused');
    $dentist()->postJson("{$base}/resume")->assertOk()->assertJsonPath('data.status', 'in_progress');

    $reception = ($this->as)(ClinicRole::Receptionist);
    $reception()->getJson($base)->assertOk()->assertJsonPath('data.title', 'Root Canal Treatment');
    $reception()->postJson("{$base}/stages/{$second->id}/start")->assertForbidden();
    $reception()->postJson("{$base}/cancel", ['reason' => 'Changed mind'])->assertForbidden();

    // A stage of another treatment is not reachable through this one.
    $other = app(PatientTreatments::class)->start($this->f->patient('Meena', 'Shah'), $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 11]]]]);
    $dentist()->postJson("{$base}/stages/{$other->stages->first()->id}/start")->assertNotFound();
});

test('amounts are shown only to members who may see billing', function () {
    $treatment = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 16]]]]);

    ($this->as)(ClinicRole::BillingStaff)()->getJson("/clinic/treatments/{$treatment->id}")->assertOk()->assertJsonPath('data.agreed_amount', 8000);

    // Every built-in role may see billing; a clinic that takes it away from Reception hides the amounts.
    $reception = ($this->as)(ClinicRole::Receptionist);
    Role::findByName(ClinicRole::Receptionist->value)->revokePermissionTo('billing.view');
    $reception()->getJson("/clinic/treatments/{$treatment->id}")->assertOk()->assertJsonMissingPath('data.agreed_amount')->assertJsonMissingPath('data.stages.0.amount');
});

test('a practitioner sees the agreed estimate of their own treatments only, never another\'s, a charge or a catalogue price', function () {
    $owner = $this->t->actAsOwner();
    $mine = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    $theirs = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorB, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 26]]]]);
    $dentist = ($this->as)(ClinicRole::Doctor, $this->f->doctorA);

    $dentist()->getJson("/clinic/treatments/{$mine->id}")->assertOk()
        ->assertJsonPath('data.agreed_amount', 8000)
        ->assertJsonPath('data.amount_scope', 'own_estimate')
        ->assertJsonMissingPath('data.procedures.0.charged_amount');
    $dentist()->getJson("/clinic/treatments/{$theirs->id}")->assertOk()
        ->assertJsonMissingPath('data.agreed_amount')->assertJsonMissingPath('data.stages.0.amount')->assertJsonMissingPath('data.amount_scope');

    $list = collect($dentist()->getJson("/clinic/patients/{$this->patient->id}/treatments")->assertOk()->json('data'))->keyBy('id');
    expect($list[$mine->id]['agreed_amount'])->toEqual(8000)
        ->and($list[$theirs->id])->not->toHaveKey('agreed_amount');

    $board = collect($dentist()->getJson('/clinic/treatments')->assertOk()->json('data'))->keyBy('id');
    expect($board[$mine->id]['agreed_amount'])->toEqual(8000)
        ->and($board[$theirs->id])->not->toHaveKey('agreed_amount');

    // The ledger's readers keep seeing every amount, unmarked.
    ($this->as)(ClinicRole::BillingStaff)()->getJson("/clinic/treatments/{$theirs->id}")->assertOk()
        ->assertJsonPath('data.agreed_amount', 8000)->assertJsonMissingPath('data.amount_scope');
});

test('the board is keyset-paged and limits a clinician to the specialties they practise', function () {
    $owner = $this->t->actAsOwner();
    $starts = app(PatientTreatments::class);
    $rct = $this->t->rct();
    $rehab = $this->t->backRehab();
    $starts->start($this->patient, $rct, $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    $starts->start($this->f->patient('Meena', 'Shah'), $rct, $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 21]]]]);
    $starts->start($this->f->patient('Anil', 'Kumar'), $rehab, $this->t->physio, $this->f->branch, $owner);

    $first = ($this->asOwner)()->getJson('/clinic/treatments?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    ($this->asOwner)()->getJson('/clinic/treatments?per_page=2&cursor='.$first->json('next_cursor'))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('next_cursor', null)->assertJsonPath('data.0.patient.name', 'Rajesh Patel');

    ($this->as)(ClinicRole::Doctor, $this->t->physio)()->getJson('/clinic/treatments')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.specialty', 'physiotherapy')->assertJsonPath('data.0.patient.name', 'Anil Kumar');
    ($this->as)(ClinicRole::Doctor, $this->f->doctorA)()->getJson('/clinic/treatments?specialty=physiotherapy')->assertOk()->assertJsonCount(0, 'data');
});

test('a member limited to one branch sees and works only that branch\'s treatments', function () {
    $owner = $this->t->actAsOwner();
    $north = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'North', 'is_main' => false, 'status' => BranchStatus::Active]);
    $treatment = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => 16]]]]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'has_all_branches_access' => false, 'status' => ClinicMembershipStatus::Active]);
    DB::table('branch_clinic_membership')->insert(['clinic_id' => $this->f->clinic->id, 'clinic_membership_id' => $membership->id, 'branch_id' => $north->id]);
    $this->f->memberWithRole(ClinicRole::Receptionist); // creates the role template
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [ClinicRole::Receptionist->value], [$north]);
    $asNorth = fn () => $this->actingAs($user)->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id, ClinicContext::SESSION_BRANCH_ID => $north->id]);

    $asNorth()->getJson('/clinic/treatments')->assertOk()->assertJsonCount(0, 'data');
    $asNorth()->getJson("/clinic/treatments/{$treatment->id}")->assertForbidden();
    $asNorth()->getJson("/clinic/patients/{$this->patient->id}/treatment-charges")->assertForbidden();
});

test('another clinic\'s catalog, treatments and patients are not found', function () {
    $treatment = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    $catalogId = CatalogTreatment::value('id');

    $other = TreatmentFixture::make('other-clinic');
    $asOther = fn () => $this->actingAs($other->f->owner)->withSession($other->f->session);

    $asOther()->getJson("/clinic/treatments/{$treatment->id}")->assertNotFound();
    $asOther()->postJson("/clinic/treatments/{$treatment->id}/pause", ['reason' => 'Not mine'])->assertNotFound();
    $asOther()->getJson("{$this->catalog}/treatments/{$catalogId}")->assertNotFound();
    $asOther()->putJson("{$this->catalog}/procedures/{$this->t->procedures['FIL']->id}", ['code' => 'FIL', 'name' => 'Hijack'])->assertNotFound();
    $asOther()->getJson("/clinic/patients/{$this->patient->id}/treatments")->assertNotFound();
    $asOther()->getJson('/clinic/treatments')->assertOk()->assertJsonCount(0, 'data');
    $asOther()->postJson("{$this->catalog}/prices", ['target' => 'clinical_procedure_id', 'target_id' => $this->t->procedures['FIL']->id, 'amount' => 1])->assertUnprocessable();
});

test('the dental tooth panel lists the engine treatments naming that tooth, beside the legacy proposal lines', function () {
    $treatment = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    app(PatientTreatments::class)->start($this->f->patient('Meena', 'Shah'), $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 16]]]]);
    $tooth = fn (int $n) => ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/dental/teeth/{$n}")->assertOk();

    $tooth(16)->assertJsonCount(1, 'treatments')->assertJsonPath('treatments.0.id', $treatment->id)->assertJsonPath('planned', []);
    $tooth(21)->assertJsonCount(0, 'treatments');
});

test('Patient 360 active care and the treatment section read patient treatments', function () {
    $treatment = app(PatientTreatments::class)->start($this->patient, $this->t->rct(), $this->f->doctorA, $this->f->branch, $this->t->actAsOwner(), ['targets' => ['tooth' => [['tooth_number' => 16]]]]);

    ($this->asOwner)()->get("/clinic/patients/{$this->patient->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.current.active_care.0.id', $treatment->id)
            ->where('overview.current.active_care.0.targets.tooth.0.tooth_number', 16)
            ->where('overview.current.active_care.0.progress.total', 3));

    ($this->asOwner)()->getJson("/clinic/patients/{$this->patient->id}/360/treatment")->assertOk()
        ->assertJsonPath('treatments.0.id', $treatment->id)
        ->assertJsonPath('treatments.0.agreed_amount', 8000);
});

test('the board, active care and charge lookup cost the same queries for 2 rows as for 10: no per-row lookups', function () {
    $owner = $this->t->actAsOwner();
    $version = $this->t->rct();
    $few = $this->f->patient('Few', 'Rows');
    $many = $this->f->patient('Many', 'Rows');
    foreach ([[$few, 2], [$many, 10]] as [$patient, $n]) {
        foreach (range(1, $n) as $i) {
            app(PatientTreatments::class)->start($patient, $version, $this->f->doctorA, $this->f->branch, $owner, ['targets' => ['tooth' => [['tooth_number' => [11, 12, 13, 14, 15, 16, 17, 21, 22, 23][$i - 1]]]]]);
        }
    }
    $count = function (callable $call): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $as = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $as()->getJson('/clinic/treatments?per_page=1')->assertOk(); // warm per-process caches

    expect($count(fn () => $as()->getJson('/clinic/treatments?per_page=10')->assertOk()->assertJsonCount(10, 'data')))
        ->toBe($count(fn () => $as()->getJson('/clinic/treatments?per_page=2')->assertOk()->assertJsonCount(2, 'data')))
        ->and($count(fn () => $as()->getJson("/clinic/patients/{$many->id}/treatments")->assertOk()->assertJsonCount(10, 'data')))
        ->toBe($count(fn () => $as()->getJson("/clinic/patients/{$few->id}/treatments")->assertOk()->assertJsonCount(2, 'data')))
        ->and($count(fn () => $as()->getJson("/clinic/patients/{$many->id}/treatment-charges")->assertOk()->assertJsonCount(10, 'data')))
        ->toBe($count(fn () => $as()->getJson("/clinic/patients/{$few->id}/treatment-charges")->assertOk()->assertJsonCount(2, 'data')));
});
