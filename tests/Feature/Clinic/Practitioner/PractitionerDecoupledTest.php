<?php

use App\EmploymentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\UserType;

test('practitioner can exist without a user login account', function () {
    $clinic = Clinic::factory()->create();

    // Create a staff/employee record with no user login account
    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'user_id' => null, // No login created
        'name' => 'Dr. Rajesh Sharma',
        'email' => 'rajesh.sharma@example.com',
        'phone' => '9876543210',
        'status' => EmploymentStatus::Active,
    ]);

    // Create practitioner profile linked to employee
    $practitioner = Practitioner::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'name' => 'Dr. Rajesh Sharma',
        'specialty' => 'Physiotherapy',
        'registration_number' => 'PT-2026-9901',
        'qualifications' => 'BPT, MPT (Neuro)',
        'consultation_fee' => 800.00,
        'is_active' => true,
    ]);

    expect($practitioner->id)->toBeGreaterThan(0);
    expect($practitioner->employee)->not->toBeNull();
    expect($practitioner->employee->user_id)->toBeNull();
    expect($practitioner->specialty)->toBe('Physiotherapy');
});

test('staff operator creates appointment on behalf of practitioner with audit trail', function () {
    $clinic = Clinic::factory()->create();

    // Receptionist with User account
    $receptionUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Reception Operator']);

    // Doctor/Practitioner without User account
    $doctorEmployee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'user_id' => null,
        'name' => 'Dr. Anita Deshmukh',
        'email' => 'anita.deshmukh@example.com',
        'status' => EmploymentStatus::Active,
    ]);

    $practitioner = Practitioner::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $doctorEmployee->id,
        'name' => 'Dr. Anita Deshmukh',
        'specialty' => 'Orthodontics',
        'is_active' => true,
    ]);

    $patient = Patient::create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Vikram',
        'last_name' => 'Patel',
        'gender' => 'male',
        'date_of_birth' => '1995-03-15',
    ]);

    // Acting as reception operator
    $this->actingAs($receptionUser);

    $appointment = Appointment::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'practitioner_id' => $doctorEmployee->id,
        'scheduled_at' => now()->addDay(),
        'type' => 'Braces Consultation',
        'status' => 'scheduled',
        'created_by' => $receptionUser->id,
        'updated_by' => $receptionUser->id,
    ]);

    expect($appointment->practitioner_id)->toBe($doctorEmployee->id);
    expect($appointment->created_by)->toBe($receptionUser->id);
    expect($appointment->creator->name)->toBe('Reception Operator');
    expect($appointment->practitioner->name)->toBe('Dr. Anita Deshmukh');
});

test('doctor profile extends practitioner and preserves backward compatibility', function () {
    $clinic = Clinic::factory()->create();

    $employee = ClinicEmployee::create([
        'clinic_id' => $clinic->id,
        'name' => 'Dr. Siddharth Verma',
        'email' => 'siddharth@example.com',
        'status' => EmploymentStatus::Active,
    ]);

    $doctorProfile = DoctorProfile::create([
        'clinic_id' => $clinic->id,
        'clinic_employee_id' => $employee->id,
        'name' => 'Dr. Siddharth Verma',
        'specialty' => 'Dental Implantology',
        'registration_number' => 'DCI-2024-5512',
        'is_active' => true,
    ]);

    expect($doctorProfile)->toBeInstanceOf(Practitioner::class);
    expect($doctorProfile->specialty)->toBe('Dental Implantology');

    $foundAsPractitioner = Practitioner::find($doctorProfile->id);
    expect($foundAsPractitioner)->not->toBeNull();
    expect($foundAsPractitioner->name)->toBe('Dr. Siddharth Verma');
});

test('specialty supports arbitrary extensible healthcare domains without schema change', function () {
    $clinic = Clinic::factory()->create();

    $specialties = [
        'Dental - Endodontics',
        'Physiotherapy - Sports Rehab',
        'General Medicine',
        'Cardiology',
        'Dermatology & Cosmetology',
        'Pediatric Physiotherapy',
    ];

    foreach ($specialties as $index => $specialty) {
        $emp = ClinicEmployee::create([
            'clinic_id' => $clinic->id,
            'name' => 'Specialist '.$index,
            'email' => 'specialist'.$index.'@example.com',
            'status' => EmploymentStatus::Active,
        ]);

        $practitioner = Practitioner::create([
            'clinic_id' => $clinic->id,
            'clinic_employee_id' => $emp->id,
            'name' => 'Specialist '.$index,
            'specialty' => $specialty,
            'is_active' => true,
        ]);

        expect($practitioner->specialty)->toBe($specialty);
    }

    $count = Practitioner::where('clinic_id', $clinic->id)->count();
    expect($count)->toBe(count($specialties));
});
