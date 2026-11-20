<?php

use App\Models\Patient;
use App\Models\PatientCase;
use App\Models\PatientVisit;
use Carbon\CarbonImmutable;
use Tests\Support\ReceptionFixture;

/**
 * The visit → case backfill (OD-4): a visit with no case is linked only to
 * the one case that can be its own; none or several leave it unlinked, a
 * dry run writes nothing, and a visit already linked is never moved.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Kolkata'));
    $this->f = ReceptionFixture::make();
    $this->visit = fn (Patient $patient, string $date, array $attributes = []) => PatientVisit::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id,
        'visit_date' => $date, 'visit_status' => 'completed', 'registration_type' => 'repeat', ...$attributes,
    ]);
    $this->case = fn (Patient $patient, string $opened, ?string $closed = null) => PatientCase::query()->withoutGlobalScopes()->forceCreate([
        'clinic_id' => $this->f->clinic->id, 'branch_id' => $this->f->branch->id, 'patient_id' => $patient->id, 'case_number' => 'C-'.fake()->unique()->numerify('####'),
        'specialty' => 'dental', 'title' => 'Care', 'chief_complaint' => 'Pain', 'lead_practitioner_id' => $this->f->doctorA->id, 'status' => $closed ? PatientCase::CLOSED : PatientCase::ACTIVE,
        'opened_at' => $opened, 'closed_at' => $closed,
    ]);
    $this->run = fn (bool $dryRun = false) => $this->artisan('patients:backfill-visit-cases', ['--clinic' => [$this->f->clinic->id], '--dry-run' => $dryRun])->assertSuccessful();
});

test('a visit is linked only to the one case open on its day; none or several leave it unlinked', function () {
    $one = $this->f->patient('One', 'Case');
    $case = ($this->case)($one, '2026-09-01');
    $linked = ($this->visit)($one, '2026-09-10');
    $before = ($this->visit)($one, '2026-08-01');

    $two = $this->f->patient('Two', 'Cases');
    ($this->case)($two, '2026-09-01');
    ($this->case)($two, '2026-09-05');
    $ambiguous = ($this->visit)($two, '2026-09-10');

    $closed = $this->f->patient('Closed', 'Case');
    ($this->case)($closed, '2026-01-01', '2026-02-01');
    $afterClosing = ($this->visit)($closed, '2026-09-10');

    ($this->run)();

    expect($linked->fresh()->case_id)->toBe($case->id)
        ->and($before->fresh()->case_id)->toBeNull()
        ->and($ambiguous->fresh()->case_id)->toBeNull()
        ->and($afterClosing->fresh()->case_id)->toBeNull();
});

test('a dry run writes nothing, and a visit linked by hand is never moved', function () {
    $patient = $this->f->patient('Hand', 'Linked');
    $case = ($this->case)($patient, '2026-09-01');
    $other = ($this->case)($this->f->patient('Someone', 'Else'), '2026-09-01');
    $unlinked = ($this->visit)($patient, '2026-09-10');
    $byHand = ($this->visit)($patient, '2026-09-11', ['case_id' => $other->id]);

    ($this->run)(true);
    expect($unlinked->fresh()->case_id)->toBeNull();

    ($this->run)();
    expect($unlinked->fresh()->case_id)->toBe($case->id)
        ->and($byHand->fresh()->case_id)->toBe($other->id);
});
