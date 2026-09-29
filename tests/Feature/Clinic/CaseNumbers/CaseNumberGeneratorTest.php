<?php

use App\Models\Branch;
use App\Models\CaseNumber;
use App\Models\CaseNumberHistory;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\Patient\CaseNumberGenerator;
use App\Services\Patient\PatientSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @param  array<string, mixed>  $overrides
 */
function useCaseNumberFormat(Clinic $clinic, array $overrides = []): void
{
    $clinic->update(['case_number_config' => [
        'active' => array_merge([
            'prefix' => 'OPD',
            'show_year' => true,
            'year_style' => 'YYYY',
            'separator' => '-',
            'digits' => 6,
            'start_number' => 1,
            'reset_yearly' => true,
        ], $overrides),
        'pending' => null,
    ]]);
}

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->generator = app(CaseNumberGenerator::class);
});

test('a patient keeps one case number across every visit in the same year', function () {
    $numbers = collect(['2026-02-03', '2026-06-15', '2026-12-31'])->map(function (string $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 10:00", 'Asia/Kolkata'));
        $visit = PatientVisit::factory()->create([
            'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id,
        ]);

        return $this->generator->forVisit($this->clinic, $visit);
    });

    expect($numbers->pluck('case_number')->unique()->all())->toBe(['OPD-2026-000001'])
        ->and(PatientVisit::where('patient_id', $this->patient->id)->pluck('case_number_id')->unique()->all())->toBe([$numbers->first()->id])
        ->and(CaseNumber::count())->toBe(1);
});

test('a new year gives the patient a new case number', function () {
    $first = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 12, 31));
    $second = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 1, 1));

    expect($first->case_number)->toBe('OPD-2026-000001')
        ->and($second->case_number)->toBe('OPD-2027-000001');
});

test('each clinic numbers independently in its own format', function () {
    $otherClinic = Clinic::factory()->create();
    useCaseNumberFormat($otherClinic, ['prefix' => 'DC', 'separator' => '/', 'year_style' => 'FY_SHORT']);
    $otherPatient = Patient::factory()->create(['clinic_id' => $otherClinic->id]);
    $date = CarbonImmutable::create(2026, 6, 1);

    $here = $this->generator->forPatient($this->clinic, $this->patient, $date);
    $there = $this->generator->forPatient($otherClinic, $otherPatient, $date);

    expect($here->case_number)->toBe('OPD-2026-000001')
        ->and($there->case_number)->toBe('DC/26-27/000001');
});

test('changing the format never rewrites numbers already issued', function () {
    $date = CarbonImmutable::create(2026, 6, 1);
    $issued = $this->generator->forPatient($this->clinic, $this->patient, $date);

    useCaseNumberFormat($this->clinic, ['prefix' => 'DC', 'separator' => '/', 'digits' => 4]);
    $newPatient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    $next = $this->generator->forPatient($this->clinic, $newPatient, $date);
    $reused = $this->generator->forPatient($this->clinic, $this->patient, $date);

    expect($issued->fresh()->case_number)->toBe('OPD-2026-000001')
        ->and($reused->case_number)->toBe('OPD-2026-000001')
        ->and($next->case_number)->toBe('DC/2026/0002');
});

test('a new counter starts at the configured start number', function () {
    useCaseNumberFormat($this->clinic, ['start_number' => 500]);

    $caseNumber = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    expect($caseNumber->case_number)->toBe('OPD-2026-000500');
});

test('with yearly reset on, each year starts again from the start number', function () {
    $second = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));
    $this->generator->forPatient($this->clinic, $second, CarbonImmutable::create(2026, 6, 2));

    $nextYear = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 1, 5));

    expect($nextYear->case_number)->toBe('OPD-2027-000001');
});

test('with yearly reset off, the counter continues but the patient still gets a new number each year', function () {
    useCaseNumberFormat($this->clinic, ['reset_yearly' => false]);
    $second = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));
    $this->generator->forPatient($this->clinic, $second, CarbonImmutable::create(2026, 6, 2));

    $nextYear = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 1, 5));

    expect($nextYear->case_number)->toBe('OPD-2027-000003')
        ->and(CaseNumber::where('patient_id', $this->patient->id)->count())->toBe(2);
});

test('a lifetime counter starts above every number the clinic has ever issued', function () {
    $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));
    useCaseNumberFormat($this->clinic, ['reset_yearly' => false]);

    $next = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 1, 5));

    expect($next->sequence)->toBe(2);
});

test('the financial year turns over between 31 March and 1 April', function () {
    useCaseNumberFormat($this->clinic, ['prefix' => 'DC', 'separator' => '/', 'year_style' => 'FY_SHORT']);

    $march = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 3, 31));
    $april = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2027, 4, 1));

    expect($march->case_number)->toBe('DC/26-27/000001')
        ->and($march->period_key)->toBe('FY2026')
        ->and($april->case_number)->toBe('DC/27-28/000001')
        ->and($april->period_key)->toBe('FY2027');
});

test('the full number finds the patient however its separators are typed', function (string $typed) {
    useCaseNumberFormat($this->clinic, ['prefix' => 'DC', 'separator' => '/', 'year_style' => 'FY_SHORT', 'start_number' => 124]);
    $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    $found = app(PatientSearchService::class)->search($this->clinic, $typed, 'case_number');

    expect($found->pluck('id')->all())->toBe([$this->patient->id]);
})->with(['DC/26-27/000124', 'DC2627000124', 'dc 26-27 000124']);

test('the last four digits alone do not find a patient', function () {
    $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    $found = app(PatientSearchService::class)->search($this->clinic, '0001', 'case_number');

    expect($found)->toBeEmpty();
});

test('a merged number in history still finds its patient', function () {
    $kept = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));
    CaseNumberHistory::create([
        'original_id' => 999, 'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id,
        'year' => 2026, 'sequence' => 7, 'case_number' => '2026-000007', 'period_key' => 'CY2026',
        'counter_key' => 'CY2026', 'search_key' => '2026000007', 'merged_into_id' => $kept->id,
    ]);

    $found = app(PatientSearchService::class)->search($this->clinic, '2026-000007', 'case_number');

    expect($found->pluck('id')->all())->toBe([$this->patient->id]);
});

test('another clinic cannot find a patient by case number', function () {
    $caseNumber = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    $found = app(PatientSearchService::class)->search(Clinic::factory()->create(), $caseNumber->case_number, 'case_number');

    expect($found)->toBeEmpty();
});

test('two patients checked in back to back get different numbers', function () {
    $second = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    $date = CarbonImmutable::create(2026, 6, 1);

    $first = $this->generator->forPatient($this->clinic, $this->patient, $date);
    $other = $this->generator->forPatient($this->clinic, $second, $date);

    expect($first->case_number)->not->toBe($other->case_number)
        ->and([$first->sequence, $other->sequence])->toBe([1, 2]);
});

test('the database refuses a second number for the same patient and period', function () {
    $issued = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));

    CaseNumber::create([
        'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'year' => 2026,
        'period_key' => $issued->period_key, 'counter_key' => $issued->counter_key, 'sequence' => 50,
        'case_number' => 'OPD-2026-000050', 'search_key' => 'OPD2026000050',
    ]);
})->throws(UniqueConstraintViolationException::class);

test('the database refuses the same sequence twice on one counter', function () {
    $issued = $this->generator->forPatient($this->clinic, $this->patient, CarbonImmutable::create(2026, 6, 1));
    $other = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

    CaseNumber::create([
        'clinic_id' => $this->clinic->id, 'patient_id' => $other->id, 'year' => 2026,
        'period_key' => $issued->period_key, 'counter_key' => $issued->counter_key, 'sequence' => $issued->sequence,
        'case_number' => 'X-1', 'search_key' => 'X1',
    ]);
})->throws(UniqueConstraintViolationException::class);
