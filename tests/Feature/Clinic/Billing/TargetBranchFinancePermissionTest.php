<?php

use App\BranchStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\PatientBillingAdjustment;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\SecurityAuditLog;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * A financial permission is held at a branch. Refunding or adjusting a bill
 * needs that permission at the bill's own branch: being able to open the
 * branch, or holding the permission at the branch the session is working
 * in, is not enough.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make('target-branch-finance');
    $this->branchB = Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second Branch', 'status' => BranchStatus::Active]);

    // A bill at branch B: 2000 billed, 1500 collected, 500 still due.
    $this->patientB = $this->f->patient('Bee', 'Branch');
    $this->billB = app(BillingService::class)->createBill($this->f->clinic, $this->patientB, $this->branchB, [['description' => 'Crown', 'quantity' => 1, 'unit_price' => 2000]]);
    $this->paymentB = app(PaymentService::class)->recordPayment($this->billB->fresh(), PaymentMethod::Cash, 1500);

    // A clinic administrator who may open every branch, but holds the role only at the main branch.
    [$this->admin, $this->adminSession] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->asAdmin = fn (?Branch $at = null) => $this->actingAs($this->admin)
        ->withSession([...$this->adminSession, ClinicContext::SESSION_BRANCH_ID => ($at ?? $this->f->branch)->id]);
    $this->refund = fn ($as, int $billId) => $as->post("/clinic/billing/bills/{$billId}/refunds", ['amount' => 200, 'reason' => 'Overcharged', 'method' => 'cash']);
    $this->adjust = fn ($as, int $billId) => $as->post("/clinic/billing/bills/{$billId}/adjustments", ['amount' => 100, 'reason' => 'Senior citizen concession']);
});

test('the permission at branch A does not refund or adjust a bill at branch B', function () {
    ($this->refund)(($this->asAdmin)(), $this->billB->id)->assertForbidden();
    ($this->adjust)(($this->asAdmin)(), $this->billB->id)->assertForbidden();

    $bill = $this->billB->fresh();
    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBillingAdjustment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $bill->refunded_amount)->toEqual(0)
        ->and((float) $bill->adjusted_amount)->toEqual(0)
        ->and((float) $bill->outstanding_balance)->toEqual(500)
        ->and(SecurityAuditLog::query()->where('event', 'billing.adjusted')->exists())->toBeFalse();
});

test('opening branch B without the permission there is refused, whichever branch the session is in', function () {
    ($this->refund)(($this->asAdmin)($this->branchB), $this->billB->id)->assertForbidden();
    ($this->adjust)(($this->asAdmin)($this->branchB), $this->billB->id)->assertForbidden();

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBillingAdjustment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('the permission held at branch B refunds and adjusts the bill at branch B', function () {
    app(ClinicRoleAssignmentService::class)->syncRoles($this->admin, [ClinicRole::ClinicAdmin->value], [$this->f->branch, $this->branchB]);

    ($this->refund)(($this->asAdmin)(), $this->billB->id)->assertSessionHasNoErrors()->assertRedirect();
    ($this->adjust)(($this->asAdmin)(), $this->billB->id)->assertSessionHasNoErrors()->assertRedirect();

    $bill = $this->billB->fresh();
    expect(PatientBillingRefund::query()->withoutGlobalScopes()->sole()->branch_id)->toBe($this->branchB->id)
        ->and((float) $bill->refunded_amount)->toEqual(200)
        ->and((float) $bill->adjusted_amount)->toEqual(100)
        ->and((float) $bill->outstanding_balance)->toEqual(400)
        ->and(SecurityAuditLog::query()->where('event', 'billing.adjusted')->count())->toBe(1);
});

test('the owner still refunds and adjusts at every branch of the clinic', function () {
    ($this->refund)($this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session)), $this->billB->id)->assertSessionHasNoErrors();
    ($this->adjust)($this->actingAs($this->f->owner)->withSession(withOwnerMode($this->f->session)), $this->billB->id)->assertSessionHasNoErrors();

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientBillingAdjustment::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('another clinic\'s bill is not found through a changed id, and nothing is written', function () {
    $other = ReceptionFixture::make('target-branch-finance-other');
    $theirs = app(BillingService::class)->createBill($other->clinic, $other->patient('Their', 'Patient'), $other->branch, [['description' => 'Scaling', 'quantity' => 1, 'unit_price' => 900]]);
    app(PaymentService::class)->recordPayment($theirs->fresh(), PaymentMethod::Cash, 900);

    ($this->refund)($this->actingAs($this->f->owner)->withSession($this->f->session), $theirs->id)->assertNotFound();
    ($this->adjust)(($this->asAdmin)(), $theirs->id)->assertNotFound();

    expect(PatientBillingRefund::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and((float) $theirs->fresh()->refunded_amount)->toEqual(0);
});

test('checking a permission at another branch leaves the request\'s own permission team as it was', function () {
    $membership = ClinicMembership::query()->withoutGlobalScopes()->where('user_id', $this->admin->id)->sole();
    $context = app(ClinicContext::class);
    $context->setUser($this->admin);
    $context->setMembership($membership);
    setPermissionsTeamId($this->f->branch->id);

    expect($this->admin->hasClinicPermissionAt(ClinicPermission::RefundProcess, $this->branchB))->toBeFalse()
        ->and(getPermissionsTeamId())->toBe($this->f->branch->id)
        ->and($this->admin->hasClinicPermissionAt(ClinicPermission::RefundProcess, $this->f->branch))->toBeTrue()
        ->and($this->admin->hasClinicPermission(ClinicPermission::RefundProcess))->toBeTrue()
        ->and(getPermissionsTeamId())->toBe($this->f->branch->id);
});

test('collecting a payment, or resending its messages, needs the permission at the bill\'s branch', function () {
    $collect = fn ($as) => $as->post("/clinic/billing/bills/{$this->billB->id}/payments", ['method' => 'cash', 'amount' => 100]);
    $retry = fn ($as) => $as->postJson("/clinic/billing/payments/{$this->paymentB->id}/messages/retry");

    $collect(($this->asAdmin)())->assertForbidden();
    $retry(($this->asAdmin)())->assertForbidden();
    expect(PatientBillingPayment::query()->withoutGlobalScopes()->where('patient_billing_id', $this->billB->id)->count())->toBe(1)
        ->and((float) $this->billB->fresh()->outstanding_balance)->toEqual(500);

    app(ClinicRoleAssignmentService::class)->syncRoles($this->admin, [ClinicRole::ClinicAdmin->value], [$this->f->branch, $this->branchB]);

    $collect(($this->asAdmin)())->assertSessionHasNoErrors()->assertRedirect();
    $retry(($this->asAdmin)())->assertOk();
    expect(PatientBillingPayment::query()->withoutGlobalScopes()->where('patient_billing_id', $this->billB->id)->count())->toBe(2)
        ->and((float) $this->billB->fresh()->outstanding_balance)->toEqual(400);
});

test('Patient 360 offers collecting and refunding only where the server will allow it', function () {
    $actions = fn ($as) => $as->getJson("/clinic/patients/{$this->patientB->id}/360/bills/{$this->billB->id}")->assertOk()->json('actions');

    // The role at branch A only: neither is offered for branch B's bill.
    expect($actions(($this->asAdmin)()))->toMatchArray(['pay_url' => null, 'refund_url' => null]);

    // An owner who does not treat patients: collecting and refunding, with no mode to enter.
    expect($actions($this->actingAs($this->f->owner)->withSession($this->f->session)))
        ->toMatchArray(['pay_url' => route('clinic.billing.payments.store', $this->billB), 'refund_url' => route('clinic.billing.refunds.store', $this->billB)]);

    app(ClinicRoleAssignmentService::class)->syncRoles($this->admin, [ClinicRole::ClinicAdmin->value], [$this->f->branch, $this->branchB]);
    expect($actions(($this->asAdmin)()))->toMatchArray([
        'pay_url' => route('clinic.billing.payments.store', $this->billB),
        'refund_url' => route('clinic.billing.refunds.store', $this->billB),
    ]);
});
