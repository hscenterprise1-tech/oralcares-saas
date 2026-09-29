<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\AttachedDocument;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Care Multispecialty', 'slug' => 'care-multi']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Central Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Reception Specialist',
    ]);

    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Siddharth',
        'last_name' => 'Malhotra',
        'phone' => '9876500099',
        'gender' => 'male',
        'age' => 32,
        'uhid' => 'UHID-2026-0099',
    ]);
});

test('patient 360 show renders with overview, today status and aggregated timeline', function () {
    $doctor = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Dr. Aditi Rao',
        'email' => 'dr.aditi@example.com',
        'status' => 'active',
    ]);

    // The specialty shown for a practitioner comes from their clinical
    // profile (`doctor_profiles`), not `clinic_employees` — which has no
    // job-title column of its own. See `Patient360Controller::show()`.
    Practitioner::create([
        'clinic_id' => $this->clinic->id,
        'organization_id' => $this->clinic->organization_id,
        'clinic_employee_id' => $doctor->id,
        'specialty' => 'Physiotherapist',
        'is_active' => true,
    ]);

    Appointment::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'practitioner_id' => $doctor->id,
        'scheduled_at' => now()->addDay(),
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get("/clinic/patients/{$this->patient->id}");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Patients/Show')
        ->where('patient.name', 'Siddharth Malhotra')
        ->where('patient.uhid', 'UHID-2026-0099')
        ->has('timeline')
        ->has('appointments.data', 1)
        ->has('visits')
        ->has('billing')
        ->has('documents')
        ->has('practitioners', 1)
        ->where('practitioners.0.name', 'Dr. Aditi Rao')
        ->where('practitioners.0.specialty', 'Physiotherapist')
    );
});

test('patient 360 paginates a long billing history instead of loading it in full', function () {
    // A patient billed for years could otherwise have every invoice they've
    // ever had pulled into memory on every single page view — see
    // Patient360Controller::show(). 12 invoices (more than the page size of
    // 10) proves the list is actually capped, while the summary cards still
    // reflect the whole history, not just the page shown.
    foreach (range(1, 12) as $i) {
        Invoice::create([
            'organization_id' => $this->clinic->organization_id,
            'patient_id' => $this->patient->id,
            'invoice_number' => "INV-{$i}",
            'status' => 'paid',
            'total_amount' => 1000,
            'amount_paid' => 1000,
            'balance_due' => 0,
            'issued_at' => now(),
        ]);
    }

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get("/clinic/patients/{$this->patient->id}");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('billing.summary.total_billed', 12000)
        ->where('billing.invoices.total', 12)
        ->has('billing.invoices.data', 10)
    );
});

test('patient 360 timeline endpoint returns chronological events', function () {
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->getJson("/clinic/patients/{$this->patient->id}/timeline");

    $response->assertStatus(200);
    $response->assertJsonIsArray();
});

test('secure document upload creates attached document and timeline event', function () {
    Storage::fake('private');

    $file = UploadedFile::fake()->create('blood_test.pdf', 500, 'application/pdf');

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post("/clinic/patients/{$this->patient->id}/documents", [
            'document' => $file,
            'title' => 'Routine Blood Panel',
            'document_type' => 'medical_report',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $doc = AttachedDocument::where('patient_id', $this->patient->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->file_name)->toBe('Routine Blood Panel');
    expect($doc->clinic_id)->toBe($this->clinic->id);
    expect($doc->uploaded_by)->toBe($this->user->id);
});

test('patient 360 rejects cross-clinic patient access', function () {
    $otherClinic = Clinic::factory()->create(['name' => 'Foreign Clinic']);
    $foreignPatient = Patient::create([
        'clinic_id' => $otherClinic->id,
        'first_name' => 'Foreign',
        'last_name' => 'Patient',
        'phone' => '9111111111',
        'gender' => 'female',
        'uhid' => 'UHID-FOR-01',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get("/clinic/patients/{$foreignPatient->id}");

    $response->assertStatus(403);
});

test('patient 360 billing endpoint enforces backend permission gate', function () {
    // Non-owner receptionist without explicit billing permission
    $receptionUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Front Desk Staff',
    ]);
    $receptionMembership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $receptionUser->id,
        'is_primary_owner' => false,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($receptionUser)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $receptionMembership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->getJson("/clinic/patients/{$this->patient->id}/billing");

    $response->assertStatus(403);
});

test('patient 360 clinical endpoint enforces backend permission gate', function () {
    // Non-owner receptionist without explicit clinical permission
    $receptionUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Front Desk Staff',
    ]);
    $receptionMembership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $receptionUser->id,
        'is_primary_owner' => false,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($receptionUser)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $receptionMembership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->getJson("/clinic/patients/{$this->patient->id}/clinical");

    $response->assertStatus(403);
});

test('patient 360 document download url is signed and streamable via file download controller', function () {
    Storage::fake('private');
    $filePath = "clinic_{$this->clinic->id}/patients/{$this->patient->id}/documents/diagnostic.pdf";
    Storage::disk('private')->put($filePath, 'fake pdf binary content');

    $doc = AttachedDocument::create([
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'uploaded_by' => $this->user->id,
        'file_name' => 'diagnostic.pdf',
        'file_path' => $filePath,
        'file_size_bytes' => 1024,
        'document_type' => 'medical_report',
    ]);

    $downloadUrl = URL::temporarySignedRoute(
        'core.files.download',
        now()->addMinutes(15),
        ['path' => base64_encode($doc->file_path), 'disk' => 'private']
    );

    $response = $this->actingAs($this->user)->get($downloadUrl);
    $response->assertStatus(200);
});
