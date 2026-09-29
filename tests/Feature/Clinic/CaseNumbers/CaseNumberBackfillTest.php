<?php

use App\Models\Branch;
use App\Models\CaseNumber;
use App\Models\CaseNumberHistory;
use App\Models\CaseNumberSequence;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Services\Patient\CaseNumberBackfill;
use Illuminate\Support\Facades\DB;

/**
 * Inserts a row the way the old per-visit generator left it: no period,
 * counter or search key.
 */
function legacyCaseNumber(Clinic $clinic, Patient $patient, PatientVisit $visit, int $sequence): int
{
    return DB::table('case_numbers')->insertGetId([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'patient_visit_id' => $visit->id,
        'year' => 2026,
        'sequence' => $sequence,
        'case_number' => sprintf('2026-%06d', $sequence),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->patient = Patient::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->other = Patient::factory()->create(['clinic_id' => $this->clinic->id]);

    $visitFor = fn (Patient $patient) => PatientVisit::factory()->create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
    ]);

    $this->visits = collect([1, 2, 4])->map(fn () => $visitFor($this->patient));
    $this->keptId = legacyCaseNumber($this->clinic, $this->patient, $this->visits[0], 1);
    legacyCaseNumber($this->clinic, $this->patient, $this->visits[1], 2);
    legacyCaseNumber($this->clinic, $this->patient, $this->visits[2], 4);
    legacyCaseNumber($this->clinic, $this->other, $visitFor($this->other), 3);
});

test('a patient with several numbers in one year keeps the earliest and the rest move to history', function () {
    $counts = app(CaseNumberBackfill::class)();

    expect($counts['merged'])->toBe(2)
        ->and(CaseNumber::where('patient_id', $this->patient->id)->pluck('case_number')->all())->toBe(['2026-000001'])
        ->and(CaseNumberHistory::orderBy('sequence')->pluck('case_number')->all())->toBe(['2026-000002', '2026-000004'])
        ->and(CaseNumberHistory::pluck('merged_into_id')->unique()->all())->toBe([$this->keptId]);
});

test('no number is lost and none is rewritten', function () {
    app(CaseNumberBackfill::class)();

    $allNumbers = CaseNumber::pluck('case_number')->merge(CaseNumberHistory::pluck('case_number'))->sort()->values()->all();

    expect($allNumbers)->toBe(['2026-000001', '2026-000002', '2026-000003', '2026-000004']);
});

test('every visit points at the number its patient kept', function () {
    app(CaseNumberBackfill::class)();

    expect(PatientVisit::whereIn('id', $this->visits->pluck('id'))->pluck('case_number_id')->unique()->all())->toBe([$this->keptId]);
});

test('the counter starts above the highest number ever issued, merged ones included', function () {
    app(CaseNumberBackfill::class)();

    expect(CaseNumberSequence::where('clinic_id', $this->clinic->id)->where('counter_key', 'CY2026')->value('last_sequence'))->toBe(4);
});

test('existing numbers get period, counter and search keys', function () {
    app(CaseNumberBackfill::class)();

    expect(CaseNumber::where('id', $this->keptId)->first(['period_key', 'counter_key', 'search_key'])->toArray())
        ->toBe(['period_key' => 'CY2026', 'counter_key' => 'CY2026', 'search_key' => '2026000001']);
});

test('running the backfill a second time changes nothing', function () {
    app(CaseNumberBackfill::class)();

    $second = app(CaseNumberBackfill::class)();

    expect($second)->toBe(['visits_linked' => 0, 'merged' => 0, 'keyed' => 0, 'counters' => 0])
        ->and(CaseNumberHistory::count())->toBe(2);
});
