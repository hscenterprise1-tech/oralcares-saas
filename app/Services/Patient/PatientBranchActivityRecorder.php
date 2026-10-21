<?php

namespace App\Services\Patient;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `patient_branch_activity`: notes that a patient was
 * seen at a branch (registered, visited, booked, consulted, billed). Insert-
 * or-ignore plus two conditional updates, so concurrent writers and the
 * backfill are safe to overlap and the dates only ever widen.
 */
class PatientBranchActivityRecorder
{
    /** The sources a branch activity can first come from. */
    public const SOURCES = ['registration', 'visit', 'appointment', 'encounter', 'billing', 'backfill'];

    public function record(int $clinicId, int $patientId, int $branchId, CarbonInterface $at, string $source): void
    {
        $now = now();
        $table = DB::table('patient_branch_activity');

        $table->insertOrIgnore([
            'clinic_id' => $clinicId, 'patient_id' => $patientId, 'branch_id' => $branchId,
            'first_seen_at' => $at, 'last_seen_at' => $at, 'first_source' => $source,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $row = fn () => DB::table('patient_branch_activity')->where('clinic_id', $clinicId)->where('patient_id', $patientId)->where('branch_id', $branchId);
        $row()->where('last_seen_at', '<', $at)->update(['last_seen_at' => $at, 'updated_at' => $now]);
        $row()->where('first_seen_at', '>', $at)->update(['first_seen_at' => $at, 'first_source' => $source, 'updated_at' => $now]);
    }

    /**
     * The same rule for many rows in one statement (backfills): each row's
     * dates only widen, and the source of the earliest sighting is kept.
     *
     * @param  list<array{patient_id: int, branch_id: int, first_at: CarbonInterface|string, last_at: CarbonInterface|string}>  $rows
     */
    public function recordMany(int $clinicId, array $rows, string $source): void
    {
        if ($rows === []) {
            return;
        }
        $now = now();
        $values = array_map(fn (array $row) => [
            'clinic_id' => $clinicId, 'patient_id' => $row['patient_id'], 'branch_id' => $row['branch_id'],
            'first_seen_at' => $row['first_at'], 'last_seen_at' => $row['last_at'], 'first_source' => $source,
            'created_at' => $now, 'updated_at' => $now,
        ], $rows);

        // `first_source` is compared before `first_seen_at` changes (MySQL applies assignments in order).
        $new = fn (string $column) => DB::getDriverName() === 'sqlite' ? "excluded.{$column}" : "values({$column})";
        [$least, $greatest] = DB::getDriverName() === 'sqlite' ? ['min', 'max'] : ['least', 'greatest'];
        foreach (array_chunk($values, 1000) as $chunk) {
            DB::table('patient_branch_activity')->upsert($chunk, ['clinic_id', 'patient_id', 'branch_id'], [
                'first_source' => DB::raw("case when {$new('first_seen_at')} < patient_branch_activity.first_seen_at then {$new('first_source')} else patient_branch_activity.first_source end"),
                'first_seen_at' => DB::raw("{$least}(patient_branch_activity.first_seen_at, {$new('first_seen_at')})"),
                'last_seen_at' => DB::raw("{$greatest}(patient_branch_activity.last_seen_at, {$new('last_seen_at')})"),
                'updated_at' => $now,
            ]);
        }
    }
}
