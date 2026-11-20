<?php

use App\AppointmentStatus;
use App\ClinicRole;
use App\Models\AttachedDocument;
use App\Models\ClinicalProcedure;
use App\Models\ClinicEmployee;
use App\Models\PatientDoctorAssignment;
use App\Models\PatientVisit;
use App\Models\SecurityAuditLog;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReceptionFixture;

/**
 * Patient 360 Phase B (the reference header and Overview): the registration
 * branch, medical notes, patient information, the appointment and financial
 * summaries and the latest documents — all from real records and only for
 * the roles that may see them — and the header's working actions: Edit,
 * Print and Assign doctor.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->patient = $this->f->patient('Meera', 'Iyer', ['phone' => '9811122334']);
    $this->patient->profile()->create([
        'clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id,
        'address' => '12 Lake Road', 'city' => 'Pune', 'emergency_contact_name' => 'Ravi Iyer', 'emergency_contact_phone' => '9822233445', 'preferred_channel' => 'whatsapp',
    ]);
    $this->patient->medicalHistory()->create(['clinic_id' => $this->f->clinic->id, 'organization_id' => $this->f->clinic->organization_id, 'notes' => 'Anxious about injections']);
    app(PatientBranchActivityRecorder::class)->record($this->f->clinic->id, $this->patient->id, $this->f->branch->id, now(), 'registration');
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->asRole = function (ClinicRole $role) {
        [$user, $session] = $this->f->memberWithRole($role);

        return fn () => $this->actingAs($user)->withSession($session);
    };
    $this->props = function ($as): array {
        $props = [];
        $as()->get("/clinic/patients/{$this->patient->id}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    };
    $this->overview = function ($as): array {
        $overview = [];
        $as()->get("/clinic/patients/{$this->patient->id}")->assertInertia(function (Assert $page) use (&$overview) {
            $overview = $page->toArray()['props']['overview'];
        });

        return $overview;
    };
});

test('the header names where the patient was registered, and medical notes go to clinical roles only', function () {
    $owner = ($this->props)($this->asOwner);
    expect($owner['patient']['registered_branch'])->toBe('Main Branch')
        ->and($owner['context']['medical_notes'])->toBe('Anxious about injections')
        ->and(($this->props)(($this->asRole)(ClinicRole::Doctor))['context']['medical_notes'])->toBe('Anxious about injections')
        ->and(($this->props)(($this->asRole)(ClinicRole::Receptionist))['context']['medical_notes'])->toBeNull();
});

test('patient information follows the contact rule: the desk sees the address and full numbers, billing does not', function () {
    $desk = ($this->props)(($this->asRole)(ClinicRole::Receptionist))['profile'];
    expect($desk)->toMatchArray(['address' => '12 Lake Road, Pune', 'preferred_channel' => 'whatsapp', 'contact_visible' => true])
        ->and($desk['emergency_contact'])->toBe(['name' => 'Ravi Iyer', 'phone' => '9822233445', 'phone_last_digits' => '3445']);

    $billing = ($this->props)(($this->asRole)(ClinicRole::BillingStaff))['profile'];
    expect($billing['address'])->toBeNull()->and($billing['contact_visible'])->toBeFalse()
        ->and($billing['emergency_contact'])->toBe(['name' => 'Ravi Iyer', 'phone' => null, 'phone_last_digits' => '3445']);
});

test('the appointment and financial summaries count real records, and only for the roles that may see them', function () {
    $this->f->appointment($this->patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-29 11:00'), AppointmentStatus::Scheduled);
    $this->f->appointment($this->patient, $this->f->doctorA, CarbonImmutable::parse('2026-10-10 11:00'), AppointmentStatus::NoShow);
    PatientVisit::withoutGlobalScopes()->create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id,
        'registration_type' => 'new', 'visit_date' => '2026-10-20', 'visit_status' => 'completed', 'queue_status' => 'completed',
    ]);
    $procedure = ClinicalProcedure::create(['clinic_id' => $this->f->clinic->id, 'code' => 'CON', 'name' => 'Consultation', 'category' => 'General', 'is_active' => true]);
    $billing = app(BillingService::class);
    $paid = $billing->createBill($this->f->clinic, $this->patient, $this->f->branch, [['clinical_procedure_id' => $procedure->id, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($paid, PaymentMethod::Cash, 400);
    $cancelled = $billing->createBill($this->f->clinic, $this->patient, $this->f->branch, [['clinical_procedure_id' => $procedure->id, 'unit_price' => 900]]);
    $cancelled->update(['status' => 'cancelled']);

    $owner = ($this->overview)($this->asOwner);
    expect($owner['summary']['appointments'])->toBe(['upcoming' => 1, 'attended' => 1, 'missed' => 1])
        ->and($owner['summary']['finance'])->toEqual(['billed' => 1000.0, 'paid' => 400.0, 'outstanding' => 600.0, 'bills' => 1]);

    expect(($this->overview)(($this->asRole)(ClinicRole::BillingStaff))['summary']['appointments'])->toBeNull()
        // A practitioner holds `billing.view_own_fees`, not the patient's whole ledger.
        ->and(($this->overview)(($this->asRole)(ClinicRole::Doctor))['summary']['finance'])->toBeNull()
        // The desk reads the patient's ledger since 2026-11-05 (full billing at reception).
        ->and(($this->overview)(($this->asRole)(ClinicRole::Receptionist))['summary']['finance'])->toEqual($owner['summary']['finance']);
});

test('the latest documents are the ones the member may open, each through its authorised route', function () {
    $make = fn (string $type, string $name) => AttachedDocument::create([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'uploaded_by' => $this->f->owner->id,
        'file_name' => $name, 'file_path' => "docs/{$name}", 'file_size_bytes' => 4, 'document_type' => $type, 'scan_status' => AttachedDocument::SCAN_CLEAN,
    ]);
    $id = $make('id_proof', 'aadhaar.pdf');
    $make('xray', 'opg.pdf');

    $desk = ($this->overview)(($this->asRole)(ClinicRole::Receptionist))['documents'];
    expect(collect($desk)->pluck('name')->all())->toBe(['aadhaar.pdf'])
        ->and($desk[0]['download_url'])->toBe(route('clinic.patients.documents.download', [$this->patient->id, $id->id]))
        ->and(collect(($this->overview)(($this->asRole)(ClinicRole::Doctor))['documents'])->pluck('name')->sort()->values()->all())->toBe(['aadhaar.pdf', 'opg.pdf'])
        // Billing may not open documents at all.
        ->and(($this->overview)(($this->asRole)(ClinicRole::BillingStaff))['documents'])->toBeNull();
});

test('Edit, Print and Assign doctor are offered where they work, and Print shows only what the member may see', function () {
    $available = fn (array $props) => collect($props['actions'])->where('available', true)->pluck('key')->sort()->values()->all();
    expect($available(($this->props)(($this->asRole)(ClinicRole::Receptionist))))->toBe(['check_in', 'communication', 'edit', 'follow_up', 'print', 'transfer'])
        ->and($available(($this->props)(($this->asRole)(ClinicRole::BillingStaff))))->toBe(['print']);

    $owner = ($this->asOwner)()->get(route('clinic.patients.print', $this->patient))->assertOk();
    $owner->assertSee('Meera Iyer')->assertSee('9811122334')->assertSee('Anxious about injections');
    expect(SecurityAuditLog::where('event', 'patient.summary_printed')->count())->toBe(1);

    $billing = ($this->asRole)(ClinicRole::BillingStaff)()->get(route('clinic.patients.print', $this->patient))->assertOk();
    $billing->assertDontSee('9811122334')->assertSee('2334')->assertDontSee('Anxious about injections')->assertDontSee('12 Lake Road');

    $other = ReceptionFixture::make('other-clinic');
    $this->actingAs($other->owner)->withSession($other->session)->get(route('clinic.patients.print', $this->patient))->assertForbidden();
});

test('the Edit page opens for members who may edit and saves back to the patient\'s record', function () {
    $desk = ($this->asRole)(ClinicRole::Receptionist);
    $desk()->get(route('clinic.patients.edit', $this->patient))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('ClinicPortal/Patients/Edit')->where('patient.emergency_contact_name', 'Ravi Iyer'));

    $desk()->put(route('clinic.patients.update', $this->patient), ['first_name' => 'Meera', 'last_name' => 'Iyer-Rao', 'phone' => '9811122334', 'gender' => 'female', 'city' => 'Mumbai'])
        ->assertRedirect(route('clinic.patients.show', $this->patient));
    expect($this->patient->fresh()->last_name)->toBe('Iyer-Rao')->and($this->patient->fresh()->profile->city)->toBe('Mumbai');

    ($this->asRole)(ClinicRole::BillingStaff)()->get(route('clinic.patients.edit', $this->patient))->assertForbidden();
});

test('a patient\'s doctor can only be one of the clinic\'s practitioners', function () {
    $desk = ($this->asRole)(ClinicRole::Receptionist);
    $clerk = ClinicEmployee::create(['clinic_id' => $this->f->clinic->id, 'name' => 'Desk Clerk', 'email' => 'clerk@example.com', 'status' => 'active']);

    $desk()->post(route('clinic.patients.transfer', $this->patient), ['to_practitioner_id' => $clerk->id])->assertSessionHasErrors('to_practitioner_id');
    expect($this->patient->fresh()->current_practitioner_id)->toBeNull();

    $desk()->post(route('clinic.patients.transfer', $this->patient), ['to_practitioner_id' => $this->f->doctorA->id, 'reason' => 'Prefers mornings'])->assertSessionHasNoErrors();
    expect($this->patient->fresh()->current_practitioner_id)->toBe($this->f->doctorA->id)
        ->and(PatientDoctorAssignment::where('patient_id', $this->patient->id)->value('reason'))->toBe('Prefers mornings');
});
