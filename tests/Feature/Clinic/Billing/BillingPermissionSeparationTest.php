<?php

use App\ClinicPermission;
use App\ClinicRole;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\HealthcareSpecialtyMaster;
use App\Models\PatientBilling;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatment;
use App\Models\SecurityAuditLog;
use App\Models\SessionPackageUsage;
use App\Services\BillingService;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\SessionPackages;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\ReceptionFixture;

/*
 * Approved billing separation (OPD-01 / OPD-02):
 * - a practitioner sees only their own fees (`billing.view_own_fees`), never
 *   the ledger, another practitioner's fees, or totals;
 * - selling a package needs `package.sell`; `billing.create` alone (enough
 *   for the check-in fee) does not sell one. The desk holds `package.sell` by
 *   default since 2026-11-05; a clinic that takes it away keeps the refusal.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    HealthcareSpecialtyMaster::query()->firstOrCreate(['key' => 'physiotherapy'], ['name' => 'Physiotherapy', 'category' => 'rehabilitation', 'category_label' => 'Rehabilitation', 'is_active' => true]);
    $this->f = ReceptionFixture::make();
    $this->f->clinic->update(['specialties' => ['physiotherapy']]);
    [$this->therapistUser, $this->therapistSession] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->me = $this->f->doctor('Ms. Therapist', $this->therapistUser);
    $this->me->practitionerProfile()->withoutGlobalScopes()->update(['specialty' => 'Physiotherapist']);
    $this->patient = $this->f->patient('Asha', 'Rao');
    $this->actingAs($this->therapistUser);
    $this->encounter = app(EncounterWorkspace::class)->startForPatient($this->patient, $this->f->branch, $this->me);
    $this->asTherapist = fn () => $this->actingAs($this->therapistUser)->withSession($this->therapistSession);

    /** `$packageSell`: true gives the role package.sell, false takes it away, null leaves the role as it is. */
    $this->member = function (ClinicRole $role, ?bool $packageSell = null) {
        [$user, $session] = $this->f->memberWithRole($role);
        ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $user->id, 'name' => "{$role->value} member", 'email' => str($role->value)->slug().'@example.com', 'status' => 'active']);
        if ($packageSell !== null) {
            $template = Role::query()->where('name', $role->value)->whereNull('branch_id')->sole();
            $permission = Permission::firstOrCreate(['name' => ClinicPermission::PackageSell->value, 'guard_name' => 'web']);
            $packageSell ? $template->givePermissionTo($permission) : $template->revokePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->treatment = function (): PatientTreatment {
        ($this->asTherapist)()->post("/clinic/consultations/{$this->encounter->id}/physio/plans", ['title' => 'Knee rehab'])->assertSessionHasNoErrors();

        return PatientTreatment::withoutGlobalScopes()->sole();
    };
    $this->sell = fn ($as, PatientTreatment $treatment) => $as()->post("/clinic/patients/{$this->patient->id}/session-packages", [
        'name' => '10 physio sessions', 'sessions_total' => 10, 'price' => 6000, 'patient_treatment_id' => $treatment->id,
    ]);
    $this->bill = function (ClinicEmployee $doctor, float $amount): PatientBilling {
        $procedure = ClinicalProcedure::firstOrCreate(['clinic_id' => $this->f->clinic->id, 'code' => 'PT'], ['name' => 'Physiotherapy session', 'category' => 'Physio', 'is_active' => true]);

        return app(BillingService::class)->createBill($this->f->clinic, $this->patient, $this->f->branch, [['clinical_procedure_id' => $procedure->id, 'unit_price' => $amount]], doctor: $doctor);
    };
});

test('the default roles separate the ledger, own fees, check-in billing and package selling', function () {
    $has = fn (ClinicRole $role, ClinicPermission $permission) => in_array($permission->value, $role->permissions(), true);

    expect($has(ClinicRole::Doctor, ClinicPermission::BillingView))->toBeFalse()
        ->and($has(ClinicRole::Doctor, ClinicPermission::BillingViewOwnFees))->toBeTrue()
        // The treating doctor bills, collects and sells a package from the consultation, and still reads no ledger.
        ->and($has(ClinicRole::Doctor, ClinicPermission::BillingCreate))->toBeTrue()
        ->and($has(ClinicRole::Doctor, ClinicPermission::PaymentCollect))->toBeTrue()
        ->and($has(ClinicRole::Doctor, ClinicPermission::PackageSell))->toBeTrue()
        ->and($has(ClinicRole::Receptionist, ClinicPermission::BillingCreate))->toBeTrue()
        // The desk runs billing in full, as billing staff do (2026-11-05): the ledger, packages and discounts; not refunds.
        ->and($has(ClinicRole::Receptionist, ClinicPermission::PackageSell))->toBeTrue()
        ->and($has(ClinicRole::Receptionist, ClinicPermission::BillingView))->toBeTrue()
        ->and($has(ClinicRole::Receptionist, ClinicPermission::DiscountApply))->toBeTrue()
        ->and($has(ClinicRole::Receptionist, ClinicPermission::RefundProcess))->toBeFalse()
        ->and($has(ClinicRole::BillingStaff, ClinicPermission::PackageSell))->toBeTrue();

    foreach ([ClinicPermission::BillingView, ClinicPermission::BillingViewOwnFees, ClinicPermission::BillingCreate, ClinicPermission::PackageSell] as $permission) {
        expect($has(ClinicRole::ClinicAdmin, $permission))->toBeTrue();
    }
});

test('billing.create alone sells no package, by any route', function () {
    $treatment = ($this->treatment)();
    $reception = ($this->member)(ClinicRole::Receptionist, packageSell: false);

    ($this->sell)($reception, $treatment)->assertForbidden();
    $reception()->postJson("/clinic/treatments/{$treatment->id}/packages", ['name' => 'Pack', 'sessions_total' => 5, 'price' => 2500])->assertForbidden();

    expect(PatientSessionPackage::withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBilling::withoutGlobalScopes()->count())->toBe(0);
});

test('the desk sells a package by default, billed as before', function () {
    $treatment = ($this->treatment)();

    ($this->sell)(($this->member)(ClinicRole::Receptionist), $treatment)->assertRedirect()->assertSessionHasNoErrors();

    $package = PatientSessionPackage::withoutGlobalScopes()->sole();
    expect($package->patient_treatment_id)->toBe($treatment->id)
        ->and((float) PatientBilling::withoutGlobalScopes()->sole()->final_amount)->toBe(6000.0);
});

test('billing staff and the owner keep selling packages', function () {
    $treatment = ($this->treatment)();

    ($this->sell)(($this->member)(ClinicRole::BillingStaff), $treatment)->assertRedirect()->assertSessionHasNoErrors();
    // The seller is recorded as a staff member, so the owner sells as theirs.
    ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'user_id' => $this->f->owner->id, 'name' => 'Clinic Owner', 'email' => 'owner@example.com', 'status' => 'active']);
    ($this->sell)(fn () => $this->actingAs($this->f->owner)->withSession($this->f->session), $treatment)->assertRedirect()->assertSessionHasNoErrors();

    expect(PatientSessionPackage::withoutGlobalScopes()->count())->toBe(2);
});

test('cancelling or adjusting a package needs package.sell; billing.create alone does neither', function () {
    $treatment = ($this->treatment)();
    ($this->sell)(($this->member)(ClinicRole::BillingStaff), $treatment)->assertRedirect();
    $package = PatientSessionPackage::withoutGlobalScopes()->sole();
    $reception = ($this->member)(ClinicRole::Receptionist, packageSell: false);

    $reception()->postJson("/clinic/treatments/{$treatment->id}/packages/{$package->id}/adjust", ['quantity' => 2, 'reason' => 'Goodwill'])->assertForbidden();
    $reception()->post("/clinic/patients/{$this->patient->id}/session-packages/{$package->id}/cancel")->assertForbidden();
    // The service refuses on its own too, whoever calls it without package.sell.
    [$deskUser] = $this->f->memberWithRole(ClinicRole::Receptionist);
    expect(fn () => app(SessionPackages::class)->adjust($package, 2, 'Goodwill', $deskUser))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SessionPackages::class)->cancel($package, $deskUser))->toThrow(AuthorizationException::class)
        ->and($package->fresh()->status)->toBe(PatientSessionPackage::ACTIVE)
        ->and(SessionPackageUsage::withoutGlobalScopes()->where('package_id', $package->id)->count())->toBe(0);
});

test('a member with package.sell adjusts and cancels, with the history kept; a refund still needs refund.process', function () {
    $treatment = ($this->treatment)();
    $billing = ($this->member)(ClinicRole::BillingStaff);
    ($this->sell)($billing, $treatment)->assertRedirect();
    $package = PatientSessionPackage::withoutGlobalScopes()->sole();

    $billing()->postJson("/clinic/treatments/{$treatment->id}/packages/{$package->id}/adjust", ['quantity' => 2, 'reason' => 'Goodwill session'])->assertOk();
    expect(SessionPackageUsage::withoutGlobalScopes()->where('package_id', $package->id)->where('entry_type', SessionPackageUsage::ADJUSTMENT)->sum('quantity'))->toEqual(2)
        ->and(SecurityAuditLog::where('event', 'package.adjusted')->exists())->toBeTrue();

    $billing()->post("/clinic/patients/{$this->patient->id}/session-packages/{$package->id}/cancel")->assertRedirect()->assertSessionHasNoErrors();
    expect($package->fresh()->status)->toBe(PatientSessionPackage::CANCELLED)
        ->and(SessionPackageUsage::withoutGlobalScopes()->where('package_id', $package->id)->count())->toBe(1);

    // Cancelling returns no money: the bill stays, and a refund on it is the refund permission's (Billing Staff hold none by default).
    $bill = PatientBilling::withoutGlobalScopes()->sole();
    $billing()->post("/clinic/billing/bills/{$bill->id}/refunds", ['amount' => 100, 'reason' => 'Package cancelled'])->assertForbidden();
    expect((float) $bill->fresh()->final_amount)->toBe(6000.0);
});

test('a practitioner sees only their own fee lines: no other practitioner\'s, no totals, payments or balance', function () {
    $mine = ($this->bill)($this->me, 500);
    ($this->bill)($this->f->doctorB, 900);
    // A line on my bill charged to another practitioner is theirs, not mine.
    $mine->items()->create([
        'clinic_id' => $this->f->clinic->id, 'item_type' => 'procedure', 'description' => 'Their procedure', 'quantity' => 1,
        'unit_price' => 700, 'discount_amount' => 0, 'tax_amount' => 0, 'final_amount' => 700, 'doctor_id' => $this->f->doctorB->id,
    ]);

    $section = ($this->asTherapist)()->getJson("/clinic/patients/{$this->patient->id}/360/billing")->assertOk();

    expect($section->json('scope'))->toBe('own_fees')
        ->and($section->json('summary'))->toBeNull()
        ->and($section->json('payments'))->toBe([])
        ->and($section->json('refunds'))->toBe([])
        ->and($section->json('data'))->toHaveCount(1)
        ->and($section->json('data.0.number'))->toBe($mine->billing_number)
        ->and($section->json('data.0.total'))->toEqual(500)
        ->and($section->json('data.0.paid'))->toBeNull()
        ->and($section->json('data.0.outstanding'))->toBeNull()
        ->and($section->json('data.0.detail_url'))->toBeNull()
        ->and(collect($section->json('data.0.items'))->pluck('description')->all())->not->toContain('Their procedure');
});

test('a practitioner cannot open a whole bill, the billing pages or patient balances directly', function () {
    $mine = ($this->bill)($this->me, 500);
    $theirs = ($this->bill)($this->f->doctorB, 900);

    ($this->asTherapist)()->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$mine->id}")->assertForbidden();
    ($this->asTherapist)()->getJson("/clinic/patients/{$this->patient->id}/360/bills/{$theirs->id}")->assertForbidden();
    ($this->asTherapist)()->get('/clinic/billing')->assertForbidden();
    ($this->asTherapist)()->get("/clinic/billing/bills/{$theirs->id}")->assertForbidden();

    expect(($this->asTherapist)()->getJson('/clinic/patients/directory')->assertOk()->json('data.0.balance'))->toBeNull();
});

test('billing staff still see the whole ledger of the patient', function () {
    ($this->bill)($this->me, 500);
    ($this->bill)($this->f->doctorB, 900);

    $section = (($this->member)(ClinicRole::BillingStaff))()->getJson("/clinic/patients/{$this->patient->id}/360/billing")->assertOk();

    expect($section->json('scope'))->toBeNull()
        ->and($section->json('data'))->toHaveCount(2)
        ->and($section->json('summary.billed'))->toEqual(1400);
});

test('own fees never reach across clinics', function () {
    $elsewhere = ReceptionFixture::make('elsewhere-clinic');
    $theirPatient = $elsewhere->patient('Other', 'Clinic');

    $status = ($this->asTherapist)()->getJson("/clinic/patients/{$theirPatient->id}/360/billing")->status();

    expect($status)->toBeIn([403, 404]);
});
