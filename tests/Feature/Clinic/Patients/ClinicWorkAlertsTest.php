<?php

use App\LabCaseStatus;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\ClinicalOrderItem;
use App\Models\LabOrder;
use App\Models\Notification;
use App\Models\PatientCase;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\Patient\ClinicWorkAlerts;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\UserType;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * Staff work alerts: what is still open is told once, to the person who owns
 * it, and a clinic can turn the alerts off. Nothing is changed by an alert.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 19:30', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->doctorUser = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $this->doctor = $this->f->doctor('Dr. Kiran Rao', $this->doctorUser);
    $this->patient = $this->f->patient('Meera', 'Iyer');
    $this->alerts = app(ClinicWorkAlerts::class);
    $this->inbox = fn (User $user) => EnterpriseNotification::query()->where('notifiable_id', $user->id)->pluck('title')->all();
    $this->encounter = fn (array $attributes = []) => ClinicalEncounter::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('######'), 'status' => 'in_progress', 'started_at' => now()->subHours(5), ...$attributes,
    ]);
});

test('a doctor is told once about consultations left unsigned, and not about signed ones', function () {
    ($this->encounter)();
    ($this->encounter)();
    ($this->encounter)(['finalized_at' => now()->subHour()]);

    expect($this->alerts->unsignedConsultations($this->f->clinic))->toBe(1)
        ->and(($this->inbox)($this->doctorUser))->toBe(['2 consultations are not signed'])
        ->and($this->alerts->unsignedConsultations($this->f->clinic))->toBe(0);
});

test('a result waiting a day for review is told to whoever ordered it', function () {
    $encounter = ($this->encounter)();
    $order = ClinicalOrder::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'encounter_id' => $encounter->id, 'patient_id' => $this->patient->id,
        'ordered_by' => $this->doctorUser->id, 'order_number' => 'ORD-1', 'order_type' => 'lab', 'status' => 'in_progress',
    ]);
    ClinicalOrderItem::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'clinical_order_id' => $order->id, 'item_name' => 'HbA1c', 'status' => ClinicalOrderItem::RESULTED, 'resulted_at' => now()->subDays(2),
    ]);
    ClinicalOrderItem::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'clinical_order_id' => $order->id, 'item_name' => 'CBC', 'status' => ClinicalOrderItem::RESULTED, 'resulted_at' => now()->subHours(3),
    ]);

    expect($this->alerts->resultsAwaitingReview($this->f->clinic))->toBe(1)
        ->and(($this->inbox)($this->doctorUser))->toBe(['A result is waiting for your review']);
});

test('an overdue lab case is told to the requesting doctor; one back in the clinic is not', function () {
    $lab = fn (LabCaseStatus $status) => LabOrder::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'requesting_doctor_id' => $this->doctor->id,
        'lab_order_number' => 'LAB-'.fake()->unique()->numerify('###'), 'status' => $status, 'delivery_due_date' => '2026-10-20',
    ]);
    $lab(LabCaseStatus::InProduction);
    $lab(LabCaseStatus::Delivered);

    expect($this->alerts->overdueLabCases($this->f->clinic))->toBe(1)
        ->and(($this->inbox)($this->doctorUser))->toBe(['A lab case is overdue']);
});

test('a dental plan with no work for a month nudges its doctor and asks the patient to book, once that month', function () {
    $encounter = ($this->encounter)(['finalized_at' => now()->subDays(40)]);
    TreatmentPlan::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'patient_id' => $this->patient->id, 'encounter_id' => $encounter->id, 'title' => 'Crowns', 'status' => TreatmentPlan::ACCEPTED, 'accepted_at' => now()->subDays(40),
    ]);

    expect($this->alerts->stalledPlans($this->f->clinic))->toBe(1)
        ->and(($this->inbox)($this->doctorUser))->toBe(['A treatment plan has stalled'])
        ->and(Notification::query()->where('event_key', 'like', 'dental_plan_waiting:%')->count())->toBe(1)
        ->and($this->alerts->stalledPlans($this->f->clinic))->toBe(0);
});

test('a case with no visit for 45 days suggests completion to its lead, and is not closed', function () {
    $case = PatientCase::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $this->patient->id, 'case_number' => 'C-1', 'specialty' => 'dental',
        'title' => 'Root canal', 'chief_complaint' => 'Toothache', 'lead_practitioner_id' => $this->doctor->id, 'status' => PatientCase::ACTIVE, 'opened_at' => now()->subDays(90),
    ]);

    expect($this->alerts->quietCases($this->f->clinic))->toBe(1)
        ->and(($this->inbox)($this->doctorUser))->toBe(['Is this case complete?'])
        ->and($case->fresh()->status)->toBe(PatientCase::ACTIVE);
});

test('a clinic that turned staff alerts off gets none', function () {
    ($this->encounter)();
    $this->f->clinic->update(['communication_config' => ['staff_alerts' => false]]);

    expect(array_sum($this->alerts->run($this->f->clinic->fresh())))->toBe(0)
        ->and(($this->inbox)($this->doctorUser))->toBe([]);
});
