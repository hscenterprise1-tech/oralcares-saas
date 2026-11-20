<?php

use App\ClinicRole;
use App\Models\ClinicalEncounter;
use App\Models\ClinicReportExport;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\PatientVisit;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\PaymentService;
use App\Services\Physio\SessionPackages;
use App\Services\Reports\ClinicReports;
use App\Services\Treatment\PatientTreatments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TreatmentFixture;

/**
 * Reports: every report builds from the clinic's own records, filtered on
 * the server; specialty reports exist only where the clinic has that
 * specialty; money, payroll and expense reports need their permission; an
 * export is queued and only its owner downloads it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'));
    Storage::fake('private');
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->t->actAsOwner();
    $this->report = function (string $key, array $query = [], ?callable $as = null): array {
        $result = null;
        ($as ?? $this->asOwner)()->get('/clinic/reports?'.http_build_query(['report' => $key, 'from' => '2026-10-01', 'to' => '2026-10-31', ...$query]))->assertOk()
            // The report comes with the page: no deferred load.
            ->assertInertia(function (Assert $page) use (&$result) {
                $result = $page->toArray()['props']['result'];
            });

        return $result;
    };
});

test('every report builds for a dental and physiotherapy clinic', function () {
    $keys = array_keys(app(ClinicReports::class)->available($this->f->clinic, $this->f->owner));

    expect($keys)->toContain('dental_procedures', 'physio_packages', 'finance_revenue', 'staff_payroll', 'expenses', 'whatsapp');
    foreach ($keys as $key) {
        $result = ($this->report)($key);
        expect($result)->toHaveKeys(['summary', 'columns', 'rows', 'truncated']);
    }
});

test('revenue, collection by method and refunds come from the clinic\'s bills and payments', function () {
    $patient = $this->f->patient('Rev', 'Enue');
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 600);

    $result = ($this->report)('finance_revenue');
    $summary = collect($result['summary'])->pluck('value', 'label');
    expect($summary['Billed'])->toEqual(1000)->and($summary['Collected'])->toEqual(600)
        ->and($result['rows'])->toEqual([['method' => 'Upi', 'payments' => 1, 'amount' => 600.0]]);

    $outstanding = ($this->report)('finance_outstanding');
    expect(collect($outstanding['summary'])->pluck('value', 'label')['Outstanding'])->toEqual(400);
});

test('package figures come from the package ledger', function () {
    $patient = $this->f->patient('Pack', 'Age');
    $treatment = app(PatientTreatments::class)->startAdHoc($patient, 'physiotherapy', 'Knee', $this->t->physio, $this->f->branch, $this->f->owner);
    app(SessionPackages::class)->sell($treatment, $this->f->branch, ['name' => 'Knee 10', 'sessions_total' => 10], $this->t->physio);

    $summary = collect(($this->report)('physio_packages')['summary'])->pluck('value', 'label');
    expect($summary['Packages sold'])->toBe(1)->and($summary['Sessions sold'])->toBe(10)->and($summary['Sessions left on active packages'])->toBe(10);
});

test('a dental clinic has no physiotherapy reports, even by address', function () {
    $plan = CompanyPlan::factory()->create();
    $plan->features()->sync(collect(['patients', 'billing', 'appointments', 'dental'])->map(fn ($k) => (CompanyFeature::where('key', $k)->first() ?? CompanyFeature::factory()->create(['key' => $k]))->id));
    CompanySubscription::factory()->create(['clinic_id' => $this->f->clinic->id, 'company_plan_id' => $plan->id]);

    ($this->asOwner)()->get('/clinic/reports')->assertInertia(fn (Assert $page) => $page
        ->where('reports', fn ($reports) => collect($reports)->pluck('key')->contains('dental_procedures') && collect($reports)->pluck('key')->doesntContain('physio_packages')));
    ($this->asOwner)()->get('/clinic/reports?report=physio_packages')->assertNotFound();
});

test('the clinic\'s reports are the owner\'s and administrator\'s; billing staff, front desk and a doctor with no practitioner profile are refused', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);

    $this->actingAs($user)->withSession($session)->get('/clinic/reports')->assertForbidden();
})->with([ClinicRole::BillingStaff, ClinicRole::Receptionist, ClinicRole::Doctor]);

test('an export is queued, written privately, guarded against spreadsheet formulas, and only its owner downloads it', function () {
    $this->f->patient('=HYPERLINK("x")', 'Evil');

    ($this->asOwner)()->post('/clinic/reports/exports', ['report' => 'patients_list', 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertSessionHasNoErrors();
    $export = ClinicReportExport::query()->withoutGlobalScopes()->sole();

    expect($export->status)->toBe('ready')->and($export->rows)->toBe(1);
    expect(Storage::disk('private')->get($export->path))->toContain("'=HYPERLINK");
    ($this->asOwner)()->get("/clinic/reports/exports/{$export->id}")->assertOk();

    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->actingAs($admin)->withSession($session)->get("/clinic/reports/exports/{$export->id}")->assertNotFound();
});

test('a doctor gets their own reports only: their consultations and treatments, never anyone else\'s, whatever is asked', function () {
    [$doctorUser, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $me = $this->f->doctor('Dr. Mine', $doctorUser);
    $patient = $this->f->patient('Report', 'Patient');
    $signed = fn ($doctor) => ClinicalEncounter::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('######'), 'status' => 'completed', 'started_at' => now()->subDays(2), 'finalized_at' => now()->subDays(2), 'chief_complaint' => 'Pain',
    ]);
    $signed($me);
    $signed($this->f->doctorA);
    $asDoctor = fn () => $this->actingAs($doctorUser)->withSession($session);

    $asDoctor()->get('/clinic/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'mine')
        ->where('reports', fn ($reports) => collect($reports)->pluck('key')->contains('clinical_consultations')
            && collect($reports)->pluck('key')->doesntContain('finance_revenue')
            && collect($reports)->pluck('key')->doesntContain('staff_workload')
            && collect($reports)->every(fn ($report) => ! in_array('practitioner', $report['filters'], true))));

    // Asking for another practitioner still returns only their own.
    $rows = ($this->report)('clinical_consultations', ['practitioner_id' => $this->f->doctorA->id], $asDoctor)['rows'];
    expect($rows)->toHaveCount(1)->and($rows[0]['practitioner'])->toBe('Dr. Mine');
    $asDoctor()->get('/clinic/reports?report=finance_revenue')->assertNotFound();

    // The owner sees both, and may choose the practitioner.
    expect(($this->report)('clinical_consultations')['rows'])->toHaveCount(2)
        ->and(($this->report)('clinical_consultations', ['practitioner_id' => $this->f->doctorA->id])['rows'])->toHaveCount(1);
});

test('the treatments report lists each treatment started, with its status and sessions', function () {
    $patient = $this->f->patient('Treat', 'Ment');
    app(PatientTreatments::class)->startAdHoc($patient, 'physiotherapy', 'Knee rehab', $this->t->physio, $this->f->branch, $this->f->owner, ['sessions_planned' => 8]);

    $result = ($this->report)('clinical_treatments');
    expect(collect($result['summary'])->pluck('value', 'label')['Treatments started'])->toBe(1)
        ->and($result['rows'][0])->toMatchArray(['treatment' => 'Knee rehab', 'status' => 'In progress', 'sessions_done' => 0, 'sessions_planned' => 8]);
});

test('the patients report shows each patient\'s whole picture and narrows by new or repeat, treatment and payment', function () {
    $visit = fn ($patient, string $date) => PatientVisit::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'doctor_id' => $this->f->doctorA->id,
        'visit_date' => $date, 'visit_status' => 'completed', 'registration_type' => 'new',
    ]);
    $newcomer = $this->f->patient('New', 'Comer');
    $visit($newcomer, '2026-10-15');
    $regular = $this->f->patient('Old', 'Regular');
    $visit($regular, '2026-08-01');
    $visit($regular, '2026-10-16');
    app(PatientTreatments::class)->startAdHoc($regular, 'physiotherapy', 'Knee rehab', $this->t->physio, $this->f->branch, $this->f->owner);
    $bill = app(BillingService::class)->createBill($this->f->clinic, $regular, $this->f->branch, [['description' => 'Session', 'quantity' => 1, 'unit_price' => 1000]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 400);

    $names = fn (array $query = []) => collect(($this->report)('patients_list', $query)['rows'])->pluck('patient')->sort()->values()->all();
    $rows = collect(($this->report)('patients_list')['rows'])->keyBy('patient');

    expect($rows['Old Regular'])->toMatchArray(['type' => 'Repeat', 'visits' => 1, 'treatments' => 'Knee rehab', 'treatment_state' => '1 under way', 'billed' => 1000.0, 'paid' => 400.0, 'owed' => 600.0])
        ->and($rows['New Comer']['type'])->toBe('New')
        ->and($names(['patient_type' => 'new']))->toContain('New Comer')->not->toContain('Old Regular')
        ->and($names(['patient_type' => 'repeat']))->toBe(['Old Regular'])
        ->and($names(['treatment_name' => 'Knee rehab']))->toBe(['Old Regular'])
        ->and($names(['treatment' => 'none']))->not->toContain('Old Regular')
        ->and($names(['payment' => 'balance']))->toBe(['Old Regular'])
        // A value the report does not offer is ignored, not trusted.
        ->and($names(['payment' => 'anything']))->toContain('Old Regular', 'New Comer');
});

test('what was billed lists every bill line with its treatment and the share of its bill paid', function () {
    $patient = $this->f->patient('Line', 'Items');
    $treatment = app(PatientTreatments::class)->startAdHoc($patient, 'physiotherapy', 'Back rehab', $this->t->physio, $this->f->branch, $this->f->owner);
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Session', 'quantity' => 1, 'unit_price' => 800, 'patient_treatment_id' => $treatment->id]]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 400);

    $result = ($this->report)('finance_items');
    expect($result['rows'][0])->toMatchArray(['patient' => 'Line Items', 'item' => 'Session', 'amount' => 800.0, 'paid' => 400.0])
        ->and(collect($result['summary'])->pluck('value', 'label')['Balance'])->toEqual(400);
});

test('revenue by doctor groups the billed lines by the doctor they were billed under, with paid and balance', function () {
    $patient = $this->f->patient('Doc', 'Wise');
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [
        ['description' => 'Root canal', 'quantity' => 1, 'unit_price' => 6000],
        ['description' => 'Filling', 'quantity' => 1, 'unit_price' => 2000],
    ], doctor: $this->t->physio);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 4000);

    $result = ($this->report)('finance_by_doctor');
    $row = collect($result['rows'])->firstWhere('doctor', $this->t->physio->name);

    expect($row)->toMatchArray(['lines' => 2, 'patients' => 1, 'billed' => 8000.0, 'paid' => 4000.0, 'balance' => 4000.0])
        ->and(collect($result['summary'])->pluck('value', 'label')['Billed'])->toEqual(8000);
});

test('revenue by service groups the billed lines by what they were for, most revenue first', function () {
    $patient = $this->f->patient('Service', 'Wise');
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [
        ['description' => 'Root canal', 'quantity' => 1, 'unit_price' => 6000],
        ['description' => 'Filling', 'quantity' => 2, 'unit_price' => 1000],
    ]);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Upi, 8000);

    $rows = collect(($this->report)('finance_by_service')['rows']);

    expect($rows->pluck('service')->first())->toBe('Root canal')
        ->and($rows->firstWhere('service', 'Filling'))->toMatchArray(['quantity' => 2, 'billed' => 2000.0, 'paid' => 2000.0, 'balance' => 0.0]);
});

test('the two revenue reports need the billing permission', function () {
    [$doctor, $session] = $this->f->memberWithRole(ClinicRole::Doctor);
    $this->f->doctor('Dr. Reports', $doctor);
    $keys = [];
    $this->actingAs($doctor)->withSession($session)->get('/clinic/reports')->assertOk()->assertInertia(function (Assert $page) use (&$keys) {
        $keys = collect($page->toArray()['props']['reports'])->pluck('key')->all();
    });

    expect($keys)->not->toContain('finance_by_doctor')->and($keys)->not->toContain('finance_by_service')->and($keys)->not->toContain('finance_items');
});

test('a member limited to one branch reads only that branch\'s figures, whatever branch they ask for', function () {
    $other = \App\Models\Branch::factory()->create(['clinic_id' => $this->f->clinic->id, 'name' => 'Second Branch', 'status' => \App\BranchStatus::Active]);
    $patient = $this->f->patient('Branch', 'Wise');
    $billing = app(BillingService::class);
    $billing->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Main branch filling', 'quantity' => 1, 'unit_price' => 1000]]);
    $billing->createBill($this->f->clinic, $patient, $other, [['description' => 'Second branch crown', 'quantity' => 1, 'unit_price' => 5000]]);

    [$member, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $membership = \App\Models\ClinicMembership::query()->where('user_id', $member->id)->firstOrFail();
    $membership->forceFill(['has_all_branches_access' => false])->save();
    $membership->syncAssignedBranches(collect([$this->f->branch]));
    $as = fn () => $this->actingAs($member)->withSession($session);

    $services = fn (array $query) => collect(($this->report)('finance_by_service', $query, $as)['rows'])->pluck('service')->all();

    // No branch asked, the other branch asked: both read the member's own branch only.
    expect($services([]))->toBe(['Main branch filling'])
        ->and($services(['branch_id' => $other->id]))->toBe(['Main branch filling']);

    // They are offered only their own branch to choose from.
    $as()->get('/clinic/reports?report=finance_by_service')->assertInertia(fn (Assert $page) => $page->has('options.branches', 1)->where('options.branches.0.id', $this->f->branch->id));

    // The owner still sees the whole clinic.
    expect(collect(($this->report)('finance_by_service')['rows'])->pluck('service')->all())->toContain('Main branch filling', 'Second branch crown');
});
