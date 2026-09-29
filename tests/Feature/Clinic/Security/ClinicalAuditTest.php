<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicMembership;
use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientBillingRefund;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

function auditedEvents(string $prefix): array
{
    return SecurityAuditLog::where('event', 'like', "{$prefix}%")->orderBy('id')->pluck('event')->all();
}

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->clinic = Clinic::factory()->create(['organization_id' => $this->organization->id]);
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id]);
    $this->patient = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $this->clinic->id,
        'organization_id' => $this->organization->id,
        'uhid' => 'UH-'.Str::upper(Str::random(8)),
        'first_name' => 'Asha',
        'last_name' => 'Rao',
        'name' => 'Asha Rao',
        'phone' => '9876501234',
        'gender' => 'female',
    ]);
});

it('records patient changes by field name, never the personal values', function () {
    $this->patient->update(['phone' => '9000011111', 'first_name' => 'Asha M']);

    $entry = SecurityAuditLog::where('event', 'patient.updated')->sole();
    $serialized = json_encode($entry->context);

    expect($entry->clinic_id)->toBe($this->clinic->id)
        ->and($entry->subject_id)->toBe($this->patient->id)
        ->and($entry->context['personal_fields_changed'])->toEqualCanonicalizing(['phone', 'first_name'])
        ->and($serialized)->not->toContain('9000011111')->not->toContain('9876501234')->not->toContain('Asha M');
    expect(auditedEvents('patient.'))->toBe(['patient.created', 'patient.updated']);
});

it('records who opened a patient record', function () {
    $branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true, 'is_primary_owner' => true,
    ]);

    $this->actingAs($user)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $branch->id,
    ])->get("/clinic/patients/{$this->patient->id}")->assertOk();

    expect(SecurityAuditLog::where('event', 'patient.viewed')->where('actor_user_id', $user->id)->where('subject_id', $this->patient->id)->exists())->toBeTrue();
});

it('records an encounter being completed, and any change after that as an amendment', function () {
    $encounter = ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $this->organization->id,
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'encounter_number' => 'ENC-1',
        'status' => 'in_consultation',
        'started_at' => now(),
    ]);

    $encounter->update(['status' => 'completed']);
    $encounter->update(['ended_at' => now()]);

    expect(auditedEvents('encounter.'))->toBe(['encounter.created', 'encounter.updated', 'encounter.completed', 'encounter.updated', 'encounter.amended'])
        ->and(SecurityAuditLog::where('event', 'encounter.completed')->sole()->context)
        ->toMatchArray(['from' => ['status' => 'in_consultation'], 'to' => ['status' => 'completed'], 'patient_id' => $this->patient->id]);
});

it('records invoice cancellation, adjustments, payments and refunds with amounts', function () {
    $bill = PatientBilling::withoutGlobalScopes()->create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id, 'billing_number' => 'B-1', 'status' => 'pending',
        'total_amount' => 1000, 'discount_amount' => 0, 'final_amount' => 1000, 'outstanding_balance' => 1000,
    ]);

    $bill->update(['discount_amount' => 100, 'final_amount' => 900]);
    PatientBillingPayment::create(['patient_billing_id' => $bill->id, 'payment_method' => 'upi', 'amount' => 400]);
    PatientBillingRefund::create(['patient_billing_id' => $bill->id, 'amount' => 50, 'reason' => 'overcharge']);
    $bill->update(['status' => 'cancelled']);

    expect(auditedEvents('billing.'))->toContain('billing.invoice.adjusted', 'billing.payment.created', 'billing.refund.created', 'billing.invoice.cancelled');

    $payment = SecurityAuditLog::where('event', 'billing.payment.created')->sole();
    expect($payment->clinic_id)->toBe($this->clinic->id)
        ->and($payment->context)->toMatchArray(['payment_method' => 'upi', 'patient_id' => $this->patient->id]);
    expect(SecurityAuditLog::where('event', 'billing.invoice.adjusted')->sole()->context['to'])->toMatchArray(['discount_amount' => 100]);
});

it('records consent being approved and revoked', function () {
    $consent = ConsentRecord::create(['patient_id' => $this->patient->id, 'consent_type' => 'treatment', 'status' => 'pending']);

    $consent->update(['status' => 'granted']);
    $consent->update(['status' => 'revoked']);

    expect(auditedEvents('consent.'))->toContain('consent.created', 'consent.approved', 'consent.revoked')
        ->and(SecurityAuditLog::where('event', 'consent.revoked')->sole()->clinic_id)->toBe($this->clinic->id);
});

it('records role and branch access changes for a staff member', function () {
    $branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->organization->id]);
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    setPermissionsTeamId(null);
    Role::create(['name' => 'Receptionist', 'guard_name' => 'web', 'branch_id' => null]);

    app(ClinicRoleAssignmentService::class)->syncRoles($user, ['Receptionist'], [$branch]);

    $membership = ClinicMembership::factory()->create(['clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active]);
    $membership->assignBranch($branch);

    expect(SecurityAuditLog::where('event', 'access.roles_changed')->sole()->context)
        ->toMatchArray(['member_user_id' => $user->id, 'from' => [], 'to' => ['Receptionist'], 'branch_id' => $branch->id])
        ->and(SecurityAuditLog::where('event', 'access.branches_changed')->sole()->context)
        ->toMatchArray(['member_user_id' => $user->id, 'granted' => [$branch->id]]);
});
