<?php

use App\ClinicRole;
use App\Models\ClinicReportExport;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
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
            ->assertInertia(function (Assert $page) use (&$result) {
                $page->loadDeferredProps(function (Assert $reload) use (&$result) {
                    $result = $reload->toArray()['props']['result'];
                });
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

test('reports are the owner\'s and administrator\'s; billing staff, front desk and clinicians are refused', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);

    $this->actingAs($user)->withSession($session)->get('/clinic/reports')->assertForbidden();
})->with([ClinicRole::BillingStaff, ClinicRole::Receptionist, ClinicRole::Doctor]);

test('an export is queued, written privately, guarded against spreadsheet formulas, and only its owner downloads it', function () {
    $this->f->patient('=HYPERLINK("x")', 'Evil');

    ($this->asOwner)()->post('/clinic/reports/exports', ['report' => 'patients_new', 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertSessionHasNoErrors();
    $export = ClinicReportExport::query()->withoutGlobalScopes()->sole();

    expect($export->status)->toBe('ready')->and($export->rows)->toBe(1);
    expect(Storage::disk('private')->get($export->path))->toContain("'=HYPERLINK");
    ($this->asOwner)()->get("/clinic/reports/exports/{$export->id}")->assertOk();

    [$admin, $session] = $this->f->memberWithRole(ClinicRole::ClinicAdmin);
    $this->actingAs($admin)->withSession($session)->get("/clinic/reports/exports/{$export->id}")->assertNotFound();
});
