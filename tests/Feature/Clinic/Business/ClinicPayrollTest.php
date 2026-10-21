<?php

use App\ClinicRole;
use App\Models\ClinicPayrollEntry;
use App\Models\ClinicPayrollEntryLine;
use App\Models\ClinicPayrollPeriod;
use App\Models\ClinicSalaryAdvance;
use App\Models\Leave;
use App\Models\SecurityAuditLog;
use App\PaymentMethod;
use App\Services\BillingService;
use App\Services\Business\ClinicPayroll;
use App\Services\Clinical\EncounterWorkspace;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\TreatmentFixture;

/**
 * Clinic payroll: pay worked out from the clinic's own records (salary,
 * completed sessions, collections on the practitioner's bills, unpaid
 * leave), the team's additions, a lock, payslips and payment with advance
 * recovery. Salaries are seen only by those allowed to.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'));
    $this->t = TreatmentFixture::make();
    $this->f = $this->t->f;
    $this->therapist = $this->t->physio;
    $this->asOwner = fn () => $this->actingAs($this->f->owner)->withSession($this->f->session);
    $this->t->actAsOwner();
    $this->payroll = app(ClinicPayroll::class);
    $this->payroll->setSalary($this->therapist, ['salary_type' => 'monthly', 'base_amount' => 31000, 'per_session_rate' => 100, 'commission_percent' => 10, 'effective_from' => '2026-01-01'], $this->f->owner);

    // October: 3 completed sessions, ₹5,000 collected on the therapist's bills, 2 days' unpaid leave.
    $patient = $this->f->patient('Pay', 'Roll');
    $encounter = app(EncounterWorkspace::class)->startForPatient($patient, $this->f->branch, $this->therapist, null, 'physiotherapy');
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $i => $day) {
        DB::table('physio_sessions')->insert(['clinic_id' => $this->f->clinic->id, 'patient_id' => $patient->id, 'encounter_id' => $encounter->id, 'session_number' => $i + 1,
            'status' => 'completed', 'completed_at' => "{$day} 11:00:00", 'therapist_id' => $this->therapist->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $bill = app(BillingService::class)->createBill($this->f->clinic, $patient, $this->f->branch, [['description' => 'Sessions', 'quantity' => 1, 'unit_price' => 5000]], doctor: $this->therapist);
    app(PaymentService::class)->recordPayment($bill->fresh(), PaymentMethod::Cash, 5000);
    Leave::query()->withoutGlobalScopes()->create(['clinic_id' => $this->f->clinic->id, 'employee_id' => $this->therapist->id, 'leave_type' => 'unpaid', 'status' => Leave::APPROVED, 'start_date' => '2026-10-12', 'end_date' => '2026-10-13']);
});

test('a month\'s pay is worked out from salary, completed sessions, collections and unpaid leave', function () {
    $period = $this->payroll->open($this->f->clinic, '2026-10', $this->f->owner);
    $entry = ClinicPayrollEntry::query()->withoutGlobalScopes()->where('payroll_period_id', $period->id)->where('clinic_employee_id', $this->therapist->id)->sole();
    $lines = ClinicPayrollEntryLine::query()->where('payroll_entry_id', $entry->id)->pluck('amount', 'kind')->map(fn ($a) => (float) $a);

    expect($lines->all())->toEqual(['base' => 31000.0, 'unpaid_leave' => -2000.0, 'session_pay' => 300.0, 'commission' => 500.0])
        ->and((float) $entry->gross)->toEqual(31800.0)->and((float) $entry->deductions)->toEqual(2000.0)->and((float) $entry->net)->toEqual(29800.0);
    // Nobody without a salary gets an entry.
    expect(ClinicPayrollEntry::query()->withoutGlobalScopes()->where('payroll_period_id', $period->id)->count())->toBe(1);
});

test('additions and advance recovery, then a lock, payslips, payment and the advance recovered', function () {
    $advance = $this->payroll->giveAdvance($this->therapist, 3000, '2026-10-02', 'cash', null, $this->f->owner);
    $period = $this->payroll->open($this->f->clinic, '2026-10', $this->f->owner);
    $entry = ClinicPayrollEntry::query()->withoutGlobalScopes()->where('payroll_period_id', $period->id)->sole();

    ($this->asOwner)()->post("/clinic/payroll/entries/{$entry->id}/lines", ['kind' => 'incentive', 'description' => 'Diwali bonus', 'amount' => 1000])->assertSessionHasNoErrors();
    ($this->asOwner)()->post("/clinic/payroll/entries/{$entry->id}/lines", ['kind' => 'advance_recovery', 'amount' => 3500, 'salary_advance_id' => $advance->id])->assertSessionHasErrors('payroll');
    ($this->asOwner)()->post("/clinic/payroll/entries/{$entry->id}/lines", ['kind' => 'advance_recovery', 'amount' => 3000, 'salary_advance_id' => $advance->id])->assertSessionHasNoErrors();
    expect((float) $entry->fresh()->net)->toEqual(29800.0 + 1000 - 3000);

    ($this->asOwner)()->post("/clinic/payroll/periods/{$period->id}/finalise")->assertSessionHasNoErrors();
    expect($entry->fresh())->status->toBe('approved')->payslip_number->toBe('PS-202610-0001');
    ($this->asOwner)()->post("/clinic/payroll/entries/{$entry->id}/lines", ['kind' => 'incentive', 'amount' => 50])->assertSessionHasErrors('payroll');

    ($this->asOwner)()->post("/clinic/payroll/entries/{$entry->id}/pay", ['method' => 'bank', 'reference' => 'NEFT-9'])->assertSessionHasNoErrors();
    expect($entry->fresh()->status)->toBe('paid')
        ->and($advance->fresh()->status)->toBe(ClinicSalaryAdvance::RECOVERED)
        ->and(ClinicPayrollPeriod::query()->withoutGlobalScopes()->find($period->id)->status)->toBe('paid');
    ($this->asOwner)()->get("/clinic/payroll/entries/{$entry->id}/payslip")->assertOk()->assertSee('PS-202610-0001')->assertSee('₹27,800.00')->assertSee('Diwali bonus');
});

test('salaries are hidden from front-desk, clinical and billing staff, and kept out of the audit trail', function (ClinicRole $role) {
    [$user, $session] = $this->f->memberWithRole($role);

    $this->actingAs($user)->withSession($session)->get('/clinic/payroll')->assertForbidden();
    expect(SecurityAuditLog::query()->where('event', 'payroll.salary_set')->get()->every(fn ($log) => ! str_contains(json_encode($log->toArray()), '31000')))->toBeTrue();
})->with([ClinicRole::Receptionist, ClinicRole::Doctor, ClinicRole::BillingStaff]);

test('another clinic\'s employee or pay entry cannot be reached', function () {
    $other = TreatmentFixture::make('payroll-other');
    $theirs = $other->physio;

    ($this->asOwner)()->put("/clinic/payroll/salaries/{$theirs->id}", ['salary_type' => 'monthly', 'base_amount' => 1, 'effective_from' => '2026-10-01'])->assertNotFound();
    $this->t->actAsOwner();
    $period = $this->payroll->open($this->f->clinic, '2026-10', $this->f->owner);
    $entry = ClinicPayrollEntry::query()->withoutGlobalScopes()->where('payroll_period_id', $period->id)->sole();
    $other->actAsOwner();
    $this->actingAs($other->f->owner)->withSession($other->f->session)->post("/clinic/payroll/entries/{$entry->id}/pay", ['method' => 'cash'])->assertNotFound();
});
