<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingItem;
use App\Models\PatientBillingPayment;
use App\Models\PatientCase;
use App\Models\PatientClinicalAccessAudit;
use App\Models\PatientProfile;
use App\Models\PatientVisit;
use App\Models\User;
use App\Policies\PatientClinicalAccessAuditPolicy;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->user = User::factory()->create();
    $this->employee = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'name' => 'Dr. Meera Nambiar',
        'email' => 'dr.meera@example.com',
        'designation' => 'Consultant',
        'status' => 'active',
    ]);
    $this->patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
});

test('patient cases can be created with valid references and enforce clinic case number uniqueness', function () {
    $case = PatientCase::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'case_number' => 'CASE-2026-000001',
        'specialty' => 'dental',
        'title' => 'Initial Full Mouth Rehab',
        'chief_complaint' => 'Pain in lower right quadrant',
        'primary_diagnosis' => 'Irreversible pulpitis #46',
        'lead_practitioner_id' => $this->employee->id,
        'status' => 'active',
        'opened_at' => now(),
    ]);

    expect($case->id)->toBeGreaterThan(0)
        ->and($case->clinic->id)->toBe($this->clinic->id)
        ->and($case->branch->id)->toBe($this->branch->id)
        ->and($case->patient->id)->toBe($this->patient->id)
        ->and($case->leadPractitioner->id)->toBe($this->employee->id);

    // Assert unique clinic_id + case_number
    expect(function () {
        PatientCase::create([
            'clinic_id' => $this->clinic->id,
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'case_number' => 'CASE-2026-000001',
            'specialty' => 'dental',
            'title' => 'Duplicate case',
            'chief_complaint' => 'Pain',
            'lead_practitioner_id' => $this->employee->id,
            'opened_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

test('patient visit links to appointment and case, and permits multiple null walk-ins', function () {
    $case = PatientCase::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'case_number' => 'CASE-2026-000002',
        'specialty' => 'dental',
        'title' => 'Follow up Case',
        'chief_complaint' => 'Review',
        'lead_practitioner_id' => $this->employee->id,
        'opened_at' => now(),
    ]);

    $appointment = Appointment::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'practitioner_id' => $this->employee->id,
        'scheduled_at' => now(),
        'status' => 'scheduled',
    ]);

    $visit1 = PatientVisit::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'case_id' => $case->id,
        'appointment_id' => $appointment->id,
        'visit_date' => now()->toDateString(),
        'registration_type' => 'new',
        'visit_status' => 'registered',
        'queue_status' => 'not_queued',
    ]);

    expect($visit1->case->id)->toBe($case->id)
        ->and($visit1->appointment->id)->toBe($appointment->id);

    // Duplicate appointment_id must be rejected
    expect(function () use ($appointment) {
        PatientVisit::create([
            'clinic_id' => $this->clinic->id,
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'appointment_id' => $appointment->id,
            'visit_date' => now()->toDateString(),
            'registration_type' => 'repeat',
            'visit_status' => 'registered',
            'queue_status' => 'not_queued',
        ]);
    })->toThrow(QueryException::class);

    // Multiple walk-in visits with appointment_id = NULL are valid
    $walkin1 = PatientVisit::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'appointment_id' => null,
        'visit_date' => now()->toDateString(),
        'registration_type' => 'repeat',
        'visit_status' => 'registered',
        'queue_status' => 'not_queued',
    ]);

    $walkin2 = PatientVisit::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'appointment_id' => null,
        'visit_date' => now()->toDateString(),
        'registration_type' => 'repeat',
        'visit_status' => 'registered',
        'queue_status' => 'not_queued',
    ]);

    expect($walkin1->id)->toBeGreaterThan(0)
        ->and($walkin2->id)->toBeGreaterThan(0);
});

test('patient profiles enforce 1:1 patient_id uniqueness while preserving pincode column', function () {
    $profile = PatientProfile::create([
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'postal_code' => '411001',
        'pincode' => '411001',
    ]);

    expect($profile->patient_id)->toBe($this->patient->id)
        ->and($profile->pincode)->toBe('411001');

    // Duplicate profile for same patient must fail unique constraint
    expect(function () {
        PatientProfile::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'postal_code' => '411002',
        ]);
    })->toThrow(QueryException::class);
});

test('patient clinical access audits are strictly immutable and protected by policy', function () {
    $audit = PatientClinicalAccessAudit::create([
        'clinic_id' => $this->clinic->id,
        'actor_user_id' => $this->user->id,
        'patient_id' => $this->patient->id,
        'source_branch_id' => $this->branch->id,
        'accessing_branch_id' => $this->branch->id,
        'resource_type' => 'clinical_encounter',
        'resource_id' => 999,
        'access_reason' => 'active_attendance',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit/Pest',
    ]);

    expect($audit->id)->toBeGreaterThan(0)
        ->and($audit->actor->id)->toBe($this->user->id)
        ->and($audit->patient->id)->toBe($this->patient->id);

    // Updating must throw LogicException
    expect(function () use ($audit) {
        $audit->update(['access_reason' => 'emergency_care']);
    })->toThrow(LogicException::class);

    // Deleting must throw LogicException
    expect(function () use ($audit) {
        $audit->delete();
    })->toThrow(LogicException::class);

    // Policy check
    $policy = new PatientClinicalAccessAuditPolicy;
    expect($policy->update($this->user, $audit))->toBeFalse()
        ->and($policy->delete($this->user, $audit))->toBeFalse();
});

test('clinical encounter enforces 1 -> 0..1 relationship with patient visit', function () {
    $visit = PatientVisit::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'visit_date' => now()->toDateString(),
        'registration_type' => 'new',
        'visit_status' => 'registered',
        'queue_status' => 'not_queued',
    ]);

    $enc1 = ClinicalEncounter::factory()->create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'patient_visit_id' => $visit->id,
    ]);

    expect($enc1->id)->toBeGreaterThan(0);

    // Second encounter pointing to the same patient_visit_id must be rejected by unique constraint
    expect(function () use ($visit) {
        ClinicalEncounter::factory()->create([
            'clinic_id' => $this->clinic->id,
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'patient_visit_id' => $visit->id,
        ]);
    })->toThrow(QueryException::class);
});

test('patient billing items support doctor_id and billing payments have default status successful', function () {
    $billing = PatientBilling::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'billing_number' => 'BILL-2026-0001',
        'status' => 'pending',
        'total_amount' => 3500.00,
        'final_amount' => 3500.00,
        'outstanding_balance' => 3500.00,
    ]);

    $item = PatientBillingItem::create([
        'patient_billing_id' => $billing->id,
        'clinic_id' => $this->clinic->id,
        'item_type' => 'service',
        'description' => 'Root Canal Treatment',
        'quantity' => 1,
        'unit_price' => 3500.00,
        'discount_amount' => 0.00,
        'tax_rate' => 0.00,
        'tax_amount' => 0.00,
        'final_amount' => 3500.00,
        'doctor_id' => $this->employee->id,
    ]);

    expect($item->id)->toBeGreaterThan(0)
        ->and($item->doctor_id)->toBe($this->employee->id);

    $payment = PatientBillingPayment::create([
        'patient_billing_id' => $billing->id,
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'payment_method' => 'cash',
        'amount' => 3500.00,
    ]);

    expect($payment->status)->toBe('successful')
        ->and($payment->fresh()->status)->toBe('successful');
});
