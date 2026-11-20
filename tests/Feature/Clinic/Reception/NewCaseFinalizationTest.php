<?php

use App\AppointmentStatus;
use App\BillingStatus;
use App\ClinicPermission;
use App\ClinicRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicalSpecialty;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\ClinicSettingAudit;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CompanySubscriptionAddon;
use App\Models\Patient;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientVisit;
use App\Models\SessionPackageUsage;
use App\Services\Billing\EncounterCheckout;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\Physio\SessionPackages;
use App\Services\Reception\PatientFlowConfigService;
use App\Services\Treatment\PatientTreatments;
use App\Services\Treatment\TreatmentCatalog;
use App\Support\ClinicAccess\ClinicModules;
use App\Support\Specialty\PracticeAreas;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;
use Tests\Support\TreatmentFixture;

/**
 * New Case finalization: the consultation fee as one canonical charge (at
 * check-in or after the visit), queue as an entitlement-ready module, the
 * catalogue editor's domain rules and history, per-field Quick Care roles
 * and areas, booking detection by mobile, and capability-limited treatments.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->flow = app(PatientFlowConfigService::class);
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);

    $this->practitioner = function (string $name, string $area, ?float $fee = null): ClinicEmployee {
        $employee = $this->f->doctor($name);
        $keyed = ['organization_id' => $this->f->clinic->organization_id, 'practice_area_key' => $area];
        $entry = ClinicalSpecialty::withoutGlobalScopes()->where($keyed)->first()
            ?? ClinicalSpecialty::withoutGlobalScopes()->forceCreate([...$keyed, 'name' => app(PracticeAreas::class)->get($area)['label'], 'is_active' => true]);
        $employee->practitionerProfile()->withoutGlobalScopes()->update(['primary_specialty_id' => $entry->id, 'specialty' => $entry->name, 'consultation_fee' => $fee]);

        return $employee->fresh();
    };
    $this->raj = ($this->practitioner)('Dr. Raj Patel', 'general_dentistry', 300);
    $this->priya = ($this->practitioner)('Dr. Priya Patel', 'orthodontics', 500);
    $this->neha = ($this->practitioner)('Dr. Neha Shah', 'sports_rehabilitation');

    $this->newCase = fn (array $fields = [], $as = null) => ($as ?? ($this->asOwner)())->postJson('/clinic/patients', [
        'surface' => 'new_case', 'first_name' => 'Harsh', 'last_name' => 'Patel', 'phone' => '9811100124', 'gender' => 'male', 'check_in' => true, ...$fields,
    ]);
    $this->checkIn = fn (array $body, $as = null) => ($as ?? ($this->asOwner)())->postJson('/clinic/reception/check-in', ['patient_id' => $this->f->patient()->id, ...$body]);
    $this->chargeAtCheckIn = fn () => $this->flow->updateCheckIn($this->f->clinic, ['payment' => 'at_check_in'], $this->f->owner);
});

// ---------------------------------------------------------------- Consultation fee: one charge

test('by default the fee is not charged at registration: payment after consultation, and a payment sent with the check-in is refused', function () {
    ($this->newCase)(['doctor_id' => $this->priya->id])->assertCreated()
        ->assertJsonPath('billing', null)->assertJsonPath('payment_timing', 'after_consultation');
    // Nothing is due at check-in, so a payment sent with it is refused — and the whole check-in with it.
    ($this->newCase)(['doctor_id' => $this->priya->id, 'phone' => '9811100125', 'payment' => ['method' => 'cash', 'amount' => 500]])
        ->assertStatus(422)->assertJsonValidationErrors('payment');

    expect(Patient::query()->count())->toBe(1)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('charged at check-in: payment first — the payment, the bill, the visit and the queue entry are made together, and checkout never charges the consultation again', function () {
    ($this->chargeAtCheckIn)();

    $paid = ($this->newCase)(['doctor_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'upi', 'amount' => 500, 'reference' => 'UPI-77']])->assertCreated()
        ->assertJsonPath('billing.total', 500)
        ->assertJsonPath('billing.outstanding', 0)
        ->assertJsonPath('billing.lines.0.amount', 500)
        ->assertJsonPath('billing.receipt.method', 'UPI')
        ->assertJsonPath('billing.pay_url', null);
    expect($paid->json('billing.reason'))->toContain('Consultation fee')
        ->and($paid->json('billing.print_url'))->toContain('/print');

    $bill = PatientBilling::query()->withoutGlobalScopes()->sole();
    expect($bill->status)->toBe(BillingStatus::Paid)
        ->and($bill->items()->withoutGlobalScopes()->sole()->source_type)->toBe((new PatientVisit)->getMorphClass())
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->sole()->receipt_number)->toBe($paid->json('billing.receipt.number'))
        ->and(PatientVisit::query()->count())->toBe(1)
        ->and(ClinicQueueItem::query()->count())->toBe(1);

    // The consultation, opened and signed, has nothing left to charge for the consultation.
    $item = ClinicQueueItem::query()->sole();
    $this->t->actAsOwner();
    $encounter = app(EncounterWorkspace::class)->startFromQueue($item, $this->priya);
    expect(collect(app(EncounterCheckout::class)->lines($encounter->fresh()))->pluck('key'))->not->toContain('consultation');
});

test('a part payment leaves the rest outstanding on the same bill; Pay later is an explicit unpaid check-in, and the desk must choose one', function () {
    ($this->chargeAtCheckIn)();

    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'cash', 'amount' => 300]])->assertCreated()
        ->assertJsonPath('billing.paid', 300)->assertJsonPath('billing.outstanding', 200);
    // Pay later: nothing is taken, the bill stays owing, the patient is queued.
    $later = ($this->checkIn)(['practitioner_id' => $this->raj->id, 'expected_charge' => 300, 'pay_later' => true])->assertCreated()
        ->assertJsonPath('billing.receipt', null)->assertJsonPath('billing.outstanding', 300)->json('billing');
    expect(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(ClinicQueueItem::query()->count())->toBe(2);

    // A desk that showed a fee but said neither, or said both, checks nobody in.
    ($this->checkIn)(['practitioner_id' => $this->raj->id, 'expected_charge' => 300])->assertStatus(422)->assertJsonValidationErrors('payment');
    ($this->checkIn)(['practitioner_id' => $this->raj->id, 'expected_charge' => 300, 'pay_later' => true, 'payment' => ['method' => 'cash', 'amount' => 300]])->assertStatus(422)->assertJsonValidationErrors('payment');
    expect(PatientVisit::query()->count())->toBe(2)->and(ClinicQueueItem::query()->count())->toBe(2)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(2);

    // What Pay later left owing is settled afterwards through the billing payment endpoint.
    ($this->asOwner)()->postJson($later['pay_url'], ['method' => 'cash', 'amount' => 300, 'idempotency_key' => 'collect-0002'])->assertCreated()
        ->assertJsonPath('billing.outstanding', 0);
});

test('a refused payment checks nobody in: no patient, visit, queue entry, bill or payment is left behind', function () {
    ($this->chargeAtCheckIn)();

    // New Case: more than the fee, a method that does not exist, and one the desk does not take.
    ($this->newCase)(['doctor_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'cash', 'amount' => 900]])->assertStatus(422)->assertJsonValidationErrors('payment.amount');
    ($this->newCase)(['doctor_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'bitcoin', 'amount' => 500]])->assertStatus(422)->assertJsonValidationErrors('payment.method');
    ($this->newCase)(['doctor_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'gateway', 'amount' => 500]])->assertStatus(422)->assertJsonValidationErrors('payment.method');
    expect(Patient::query()->count())->toBe(0);

    // Repeat Case: the returning patient is not checked in.
    $patient = $this->f->patient();
    ($this->asOwner)()->postJson('/clinic/reception/check-in', ['patient_id' => $patient->id, 'practitioner_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'cash', 'amount' => 900]])
        ->assertStatus(422)->assertJsonValidationErrors('payment.amount');

    expect(PatientVisit::query()->count())->toBe(0)
        ->and(ClinicQueueItem::query()->count())->toBe(0)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('repeated requests never check in, charge or take money twice; a refused attempt frees the key; the amount charged is never taken from the request', function () {
    ($this->chargeAtCheckIn)();
    $body = ['doctor_id' => $this->priya->id, 'expected_charge' => 500, 'idempotency_key' => 'case-key-0001', 'total' => 1, 'unit_price' => 1];

    // Refused first (too much), then sent again with the same key and the right amount.
    ($this->newCase)([...$body, 'payment' => ['method' => 'cash', 'amount' => 900]])->assertStatus(422);
    ($this->newCase)([...$body, 'payment' => ['method' => 'cash', 'amount' => 500]])->assertCreated();
    // A double click or a retry of the same request: the first result, nothing new.
    ($this->newCase)([...$body, 'payment' => ['method' => 'cash', 'amount' => 500]])->assertCreated()->assertJsonPath('replayed', true);

    $repeat = ['patient_id' => $this->f->patient()->id, 'practitioner_id' => $this->raj->id, 'expected_charge' => 300, 'payment' => ['method' => 'cash', 'amount' => 300], 'idempotency_key' => 'case-key-0002'];
    ($this->asOwner)()->postJson('/clinic/reception/check-in', $repeat)->assertCreated();
    ($this->asOwner)()->postJson('/clinic/reception/check-in', $repeat)->assertCreated()->assertJsonPath('replayed', true);

    expect(PatientVisit::query()->count())->toBe(2)
        ->and(ClinicQueueItem::query()->count())->toBe(2)
        ->and(PatientBilling::query()->withoutGlobalScopes()->pluck('final_amount')->map(fn ($amount) => (float) $amount)->sort()->values()->all())->toBe([300.0, 500.0])
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('only a member who may collect money takes a payment at check-in; Pay later needs the billing permission', function () {
    ($this->chargeAtCheckIn)();
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(ClinicPermission::PaymentCollect->value);
    $asReception = fn () => $this->actingAs($receptionist->fresh())->withSession($session);

    // May raise the bill but not take money: the payment is refused and nobody is checked in.
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'expected_charge' => 500, 'payment' => ['method' => 'cash', 'amount' => 500]], $asReception())
        ->assertStatus(422)->assertJsonValidationErrors('payment');
    expect(ClinicQueueItem::query()->count())->toBe(0);
    // Pay later is theirs to choose: checked in, the bill owing.
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'expected_charge' => 500, 'pay_later' => true], $asReception())
        ->assertCreated()->assertJsonPath('billing.outstanding', 500)->assertJsonPath('billing.receipt', null);

    // A member without the billing permission raises no bill at all, so has nothing to leave owing.
    Role::findByName(ClinicRole::Receptionist->value, 'web')->revokePermissionTo(ClinicPermission::BillingCreate->value);
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'expected_charge' => 500, 'pay_later' => true], $this->actingAs($receptionist->fresh())->withSession($session))
        ->assertStatus(422)->assertJsonValidationErrors('expected_charge');

    expect(PatientBilling::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PatientBillingPayment::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a physiotherapy check-in charges nothing and never uses a package session', function () {
    ($this->chargeAtCheckIn)();
    $therapist = ($this->practitioner)('Ms. Anu Rao', 'sports_rehabilitation', 400);
    $patient = $this->f->patient('Meera', 'Joshi');
    $treatment = app(PatientTreatments::class)->startAdHoc($patient, 'physiotherapy', 'Knee Rehabilitation', $therapist, $this->f->branch, $this->t->actAsOwner(), ['sessions_planned' => 6]);
    $package = app(SessionPackages::class)->sell($treatment, $this->f->branch, ['name' => 'Knee 6-pack', 'sessions_total' => 6, 'price' => 3000], $therapist);
    $body = ['patient_id' => $patient->id, 'practitioner_id' => $therapist->id];
    // Selling the package raised its own bill; the check-in must add none.
    $bills = PatientBilling::query()->withoutGlobalScopes()->count();

    // Nothing is due, so a payment is refused (and checks nobody in).
    ($this->asOwner)()->postJson('/clinic/reception/check-in', [...$body, 'expected_charge' => 0, 'payment' => ['method' => 'cash', 'amount' => 400]])->assertStatus(422);
    ($this->asOwner)()->postJson('/clinic/reception/check-in', [...$body, 'expected_charge' => 0])->assertCreated()->assertJsonPath('billing', null);

    expect(ClinicQueueItem::query()->count())->toBe(1)
        ->and(PatientBilling::query()->withoutGlobalScopes()->count())->toBe($bills)
        ->and(app(SessionPackages::class)->balance($package->fresh()))->toBe(6)
        ->and(SessionPackageUsage::query()->withoutGlobalScopes()->where('entry_type', SessionPackageUsage::CONSUME)->count())->toBe(0);
});

// ---------------------------------------------------------------- Queue as a module

test('queue availability is data: default, plan feature, add-on and company override, without code', function () {
    $modules = app(ClinicModules::class);
    expect($modules->enabled($this->f->clinic, 'queue'))->toBeTrue();

    config(['clinic_modules.queue.default_included' => false]);
    expect($modules->enabled($this->f->clinic, 'queue'))->toBeFalse();

    $plan = CompanyPlan::factory()->create();
    $plan->features()->sync([(CompanyFeature::query()->where('key', 'patients')->first() ?? CompanyFeature::factory()->create(['key' => 'patients']))->id]);
    $subscription = CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id, 'company_plan_id' => $plan->id]);
    expect($modules->enabled($this->f->clinic, 'queue'))->toBeFalse();

    CompanySubscriptionAddon::query()->create(['company_subscription_id' => $subscription->id, 'addon_type' => 'module:queue', 'quantity' => 1, 'price' => 0, 'is_active' => true]);
    expect($modules->enabled($this->f->clinic, 'queue'))->toBeTrue();

    $modules->override($this->f->clinic, 'queue', false, $this->f->owner, 'Paused for the clinic');
    expect($modules->enabled($this->f->clinic, 'queue'))->toBeFalse();
    $this->artisan('clinic:module-override', ['clinic' => $this->f->clinic->id, 'module' => 'queue', '--clear' => true])->assertSuccessful();
    expect($modules->enabled($this->f->clinic, 'queue'))->toBeTrue();
});

test('without the queue module, check-in still works and shows no position', function () {
    app(ClinicModules::class)->override($this->f->clinic, 'queue', false, $this->f->owner);

    ($this->checkIn)(['practitioner_id' => $this->raj->id])->assertCreated()->assertJsonPath('check_in.queue', false)->assertJsonPath('check_in.position', null);
    ($this->asOwner)()->getJson('/clinic/reception/intake')->assertJsonPath('check_in.queue', false);
});

// ---------------------------------------------------------------- Catalogue editor

test('owners add, rename, reorder and switch off reasons; history keeps what was recorded', function () {
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'braces_consultation'])->assertCreated();
    $visit = PatientVisit::query()->latest('id')->first();

    ($this->asOwner)()->putJson('/clinic/admin/settings/catalogue/visit_reason/braces_consultation', ['name' => 'Braces – first visit', 'position' => 1])->assertOk();
    ($this->asOwner)()->putJson('/clinic/admin/settings/catalogue/visit_reason/braces_consultation', ['enabled' => false])->assertOk();

    expect($visit->fresh()->only(['visit_reason_key', 'visit_reason', 'practice_area_key']))->toBe(['visit_reason_key' => 'braces_consultation', 'visit_reason' => 'Braces consultation', 'practice_area_key' => 'orthodontics']);
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => 'braces_consultation'])->assertStatus(422)->assertJsonValidationErrors('visit_reason_key');

    $key = ($this->asOwner)()->postJson('/clinic/admin/settings/catalogue/visit_reason', [
        'name' => 'Clear aligner review', 'specialty_keys' => ['dental', 'orthodontics'], 'practice_area_keys' => ['orthodontics'], 'category' => 'review',
    ])->assertCreated()->json('key');
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'visit_reason_key' => $key])->assertCreated();
    ($this->checkIn)(['practitioner_id' => $this->raj->id, 'visit_reason_key' => $key])->assertStatus(422);

    expect(ClinicSettingAudit::query()->where('section', 'clinical_catalogue')->count())->toBeGreaterThanOrEqual(3);
});

test('a reason cannot be moved into another specialty or given another specialty\'s area', function () {
    ($this->asOwner)()->putJson('/clinic/admin/settings/catalogue/visit_reason/braces_consultation', ['specialty_keys' => ['physiotherapy']])->assertStatus(422);
    ($this->asOwner)()->postJson('/clinic/admin/settings/catalogue/visit_reason', ['name' => 'Odd', 'specialty_keys' => ['physiotherapy'], 'practice_area_keys' => ['orthodontics']])->assertStatus(422);
    ($this->asOwner)()->postJson('/clinic/admin/settings/catalogue/visit_reason', ['name' => 'Odd', 'specialty_keys' => ['dental'], 'category' => 'made_up'])->assertStatus(422);

    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionist)->withSession($session)->putJson('/clinic/admin/settings/catalogue/visit_reason/knee_pain', ['enabled' => false])->assertForbidden();
});

// ---------------------------------------------------------------- Quick Care per field

// Vitals were removed from the product. A clinic that had configured them
// (even as required) registers and checks in without them, and is never
// offered them again; its old settings stay stored and are ignored.
test('old vitals settings, even required ones, neither appear nor block a check-in', function () {
    $this->f->clinic->update(['patient_flow_config' => [...($this->f->clinic->patient_flow_config ?? []), 'quick_care' => [
        'vitals' => ['pulse' => ['state' => 'required'], 'pain_score' => ['state' => 'required', 'areas' => ['sports_rehabilitation']]],
        'temperature_unit' => 'c', 'thresholds' => ['pulse' => ['low' => 50, 'high' => 110]],
    ]]]);

    ($this->checkIn)(['practitioner_id' => $this->neha->id])->assertCreated();
    ($this->checkIn)(['practitioner_id' => $this->priya->id, 'vitals' => ['pulse' => 72]])->assertCreated();
    ($this->newCase)(['doctor_id' => $this->raj->id])->assertCreated();

    $intake = ($this->asOwner)()->getJson('/clinic/reception/intake')->assertOk()->json();
    expect(array_keys($intake['quick_care']))->toBe(['allergy_status'])
        ->and($intake['can'])->not->toHaveKey('record_vitals')
        ->and(PatientVisit::query()->whereNotNull('intake_vitals')->count())->toBe(0)
        // Saving Quick Care keeps the old keys as stored (no destructive rewrite), and still ignores them.
        ->and(tap($this->flow)->updateQuickCare($this->f->clinic, ['alerts' => false], $this->f->owner)->quickCare($this->f->clinic->fresh()))->toBe(['allergy_status' => 'optional', 'alerts' => false, 'alert_roles' => null, 'conditions_from_catalogue' => false])
        ->and($this->f->clinic->fresh()->patient_flow_config['quick_care']['vitals']['pulse']['state'])->toBe('required');
});

test('alerts follow their roles setting', function () {
    $this->flow->updateQuickCare($this->f->clinic, ['alert_roles' => [ClinicRole::Doctor->value]], $this->f->owner);
    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);

    ($this->newCase)(['doctor_id' => $this->raj->id, 'alert_keys' => ['fall_risk']], $this->actingAs($receptionist)->withSession($session))->assertStatus(422)->assertJsonValidationErrors('alert_keys');
    $this->actingAs($receptionist)->withSession($session)->getJson('/clinic/reception/intake')->assertJsonPath('catalogue.alerts', []);
});

test('quick care settings refuse unknown roles', function () {
    ($this->asOwner)()->put('/clinic/admin/settings/quick-care', ['alert_roles' => ['Janitor']])->assertSessionHasErrors('alert_roles.0');
});

// ---------------------------------------------------------------- Bookings found by mobile

test('a booking made with the same mobile is found here, safely', function () {
    $book = fn (array $attributes) => Appointment::create([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'practitioner_id' => $this->priya->id,
        'prospect_name' => 'Harsh Patel', 'prospect_phone' => '+91 98111 00124', 'scheduled_at' => CarbonImmutable::parse('2026-10-10 11:00'), 'duration_minutes' => 15, 'status' => AppointmentStatus::Scheduled, ...$attributes,
    ]);
    $match = fn (string $phone) => ($this->asOwner)()->getJson('/clinic/reception/bookings/match?phone='.$phone)->assertOk()->json('bookings');

    expect($match('9811100124'))->toBe([]);
    $booking = $book([]);
    expect($match('98111-00124'))->toHaveCount(1)
        ->and($match('98111-00124')[0])->toMatchArray(['id' => $booking->id, 'time' => '11:00', 'doctor' => ['id' => $this->priya->id, 'name' => 'Dr. Priya Patel', 'practice' => 'Dental · Orthodontics']])
        ->and($match('9811100999'))->toBe([]);

    $book(['practitioner_id' => $this->raj->id, 'scheduled_at' => CarbonImmutable::parse('2026-10-10 16:00')]);
    expect($match('9811100124'))->toHaveCount(2);

    $book(['branch_id' => Branch::factory()->create(['clinic_id' => $this->f->clinic->id])->id]);
    $book(['patient_id' => $this->f->patient()->id]);
    $book(['scheduled_at' => CarbonImmutable::parse('2026-10-11 11:00')]);
    expect($match('9811100124'))->toHaveCount(2);

    $other = TreatmentFixture::make('other-clinic');
    Appointment::create(['clinic_id' => $other->f->clinic->id, 'branch_id' => $other->f->branch->id, 'practitioner_id' => $other->f->doctorA->id, 'prospect_name' => 'Elsewhere', 'prospect_phone' => '9811100124', 'scheduled_at' => CarbonImmutable::parse('2026-10-10 12:00'), 'duration_minutes' => 15, 'status' => AppointmentStatus::Scheduled]);
    expect(collect($match('9811100124'))->pluck('name')->unique()->all())->toBe(['Harsh Patel']);

    // Using the found booking links it, once.
    ($this->newCase)(['appointment_id' => $booking->id])->assertCreated()->assertJsonPath('check_in.context.source', 'appointment');
    expect($booking->fresh()->patient_id)->not->toBeNull();
    ($this->newCase)(['appointment_id' => $booking->id, 'phone' => '9811100888', 'first_name' => 'Second'])->assertNotFound();
});

// ---------------------------------------------------------------- Capability-limited treatments

test('a treatment limited to an area capability is started only by a practitioner of that area, whatever their degree', function () {
    $version = $this->t->rct('upfront', false);
    $owner = $this->t->actAsOwner();
    $catalog = app(TreatmentCatalog::class);
    $treatment = $version->treatment()->withoutGlobalScopes()->first();

    expect(fn () => $catalog->setRequiredCapability($treatment, 'exercise_prescription', $owner))->toThrow(DomainException::class)
        ->and(fn () => $catalog->setRequiredCapability($treatment, 'made_up', $owner))->toThrow(DomainException::class);
    ($this->asOwner)()->putJson("/clinic/admin/catalog/treatments/{$treatment->id}/capability", ['required_capability' => 'braces_treatment'])->assertOk();

    $patient = $this->f->patient('Brace', 'Face');
    $start = fn (ClinicEmployee $doctor) => fn () => app(PatientTreatments::class)->start($patient, $version, $doctor, $this->f->branch, $owner);

    // MDS (Orthodontics) on paper, but practising General Dentistry: the qualification grants nothing.
    $this->raj->practitionerProfile()->withoutGlobalScopes()->first()->qualificationRecords()->create(['clinic_id' => $this->f->clinic->id, 'qualification' => 'MDS', 'degree_type' => 'master', 'specialization' => 'Orthodontics']);
    expect($start($this->raj->fresh()))->toThrow(AuthorizationException::class, 'does not practise the area this treatment needs');
    expect($start($this->priya)()->exists)->toBeTrue();

    ($this->asOwner)()->putJson("/clinic/admin/catalog/treatments/{$treatment->id}/capability", ['required_capability' => null])->assertOk();
    expect($start($this->raj)()->exists)->toBeTrue();

    [$receptionist, $session] = $this->f->memberWithRole(ClinicRole::Receptionist);
    $this->actingAs($receptionist)->withSession($session)->putJson("/clinic/admin/catalog/treatments/{$treatment->id}/capability", ['required_capability' => 'braces_treatment'])->assertForbidden();
});

test('capability enforcement applies when starting from the catalogue and recording an ad hoc of the same specialty stays unaffected', function () {
    $owner = $this->t->actAsOwner();
    $patient = $this->f->patient('Ortho', 'Patient');
    // Ad hoc treatments carry no capability: same-specialty practitioners may start them, as before.
    $treatment = app(PatientTreatments::class)->startAdHoc($patient, 'dental', 'Scaling', $this->raj, $this->f->branch, $owner);
    expect($treatment->exists)->toBeTrue();
});
