<?php

namespace App\Services\Patient;

use App\Models\CaseNumber;
use App\Models\CaseNumberHistory;
use App\Models\CaseNumberSequence;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Support\ClinicClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues case numbers: one per patient per period, in each clinic's own
 * format (`CaseNumberConfigService`). Every later visit in the same period
 * reuses the patient's number; a new period gets a new one.
 *
 * Race safety: the patient row is locked first (two check-ins of the same
 * patient can't both issue a number), then the clinic's counter row (two
 * different patients can't draw the same sequence). The unique constraints
 * on `(clinic, patient, period_key)`, `(clinic, counter_key, sequence)` and
 * `(clinic, case_number)` are the backstop if a lock is ever bypassed.
 */
class CaseNumberGenerator
{
    /**
     * How many already-taken numbers to step over before giving up. A
     * collision only happens after a format change lands a new number on an
     * old spelling, so this is never reached in practice.
     */
    private const MAX_COLLISION_SKIPS = 100;

    /**
     * Laravel re-runs the transaction if MySQL still picks it as a deadlock
     * victim. Only effective when this is the outermost transaction; inside a
     * caller's transaction the caller has to retry.
     */
    private const DEADLOCK_ATTEMPTS = 5;

    public function __construct(protected CaseNumberConfigService $configService) {}

    public function forPatient(Clinic $clinic, Patient $patient, ?CarbonInterface $date = null): CaseNumber
    {
        $date ??= ClinicClock::now();
        $format = $this->configService->resolve($clinic, $date);
        $periodKey = CaseNumberFormatter::periodKey($format['year_style'], $date);

        return DB::transaction(function () use ($clinic, $patient, $date, $format, $periodKey): CaseNumber {
            Patient::withoutGlobalScopes()->whereKey($patient->id)->lockForUpdate()->first();

            $existing = CaseNumber::where('clinic_id', $clinic->id)
                ->where('patient_id', $patient->id)
                ->where('period_key', $periodKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $counterKey = CaseNumberFormatter::counterKey($format, $periodKey);
            $counter = $this->lockCounter($clinic, $counterKey, $format);
            $startYear = CaseNumberFormatter::periodStartYear($format['year_style'], $date);

            $sequence = $counter->last_sequence;
            for ($skips = 0; $skips <= self::MAX_COLLISION_SKIPS; $skips++) {
                $sequence++;
                $number = CaseNumberFormatter::format($format, $startYear, $sequence);

                if (! $this->isTaken($clinic, $counterKey, $sequence, $number)) {
                    $counter->update(['last_sequence' => $sequence]);

                    return CaseNumber::create([
                        'clinic_id' => $clinic->id,
                        'patient_id' => $patient->id,
                        'year' => $startYear,
                        'period_key' => $periodKey,
                        'counter_key' => $counterKey,
                        'sequence' => $sequence,
                        'case_number' => $number,
                    ]);
                }
            }

            throw new RuntimeException("Could not find a free case number for clinic {$clinic->id} on counter {$counterKey}.");
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Attaches the patient's number for the visit's period to the visit.
     */
    public function forVisit(Clinic $clinic, PatientVisit $visit): CaseNumber
    {
        $patient = Patient::withoutGlobalScopes()->findOrFail($visit->patient_id);
        $caseNumber = $this->forPatient($clinic, $patient);

        if ($caseNumber->patient_visit_id === null) {
            $caseNumber->update(['patient_visit_id' => $visit->id]);
        }

        $visit->update(['case_number_id' => $caseNumber->id]);

        return $caseNumber;
    }

    /**
     * @param  array{start_number: int, reset_yearly: bool}  $format
     */
    private function lockCounter(Clinic $clinic, string $counterKey, array $format): CaseNumberSequence
    {
        $initial = $format['start_number'] - 1;
        if ($counterKey === CaseNumberFormatter::LIFETIME_COUNTER) {
            $initial = max($initial, $this->configService->highestSequenceEver($clinic));
        }

        $existing = $this->lockExistingCounter($clinic, $counterKey);
        if ($existing) {
            return $existing;
        }

        // First number on this counter. Only here does `INSERT IGNORE` run:
        // on a row that already exists it takes a *shared* lock, and two
        // transactions upgrading shared locks to `FOR UPDATE` deadlock each
        // other (found by `case-numbers:race-check`).
        DB::table('case_number_sequences')->insertOrIgnore([
            'clinic_id' => $clinic->id,
            'counter_key' => $counterKey,
            'last_sequence' => $initial,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->lockExistingCounter($clinic, $counterKey) ?? throw new RuntimeException("Case number counter {$counterKey} could not be created.");
    }

    private function lockExistingCounter(Clinic $clinic, string $counterKey): ?CaseNumberSequence
    {
        return CaseNumberSequence::where('clinic_id', $clinic->id)
            ->where('counter_key', $counterKey)
            ->lockForUpdate()
            ->first();
    }

    private function isTaken(Clinic $clinic, string $counterKey, int $sequence, string $number): bool
    {
        return CaseNumber::where('clinic_id', $clinic->id)
            ->where(fn ($q) => $q->where('case_number', $number)
                ->orWhere(fn ($q) => $q->where('counter_key', $counterKey)->where('sequence', $sequence)))
            ->exists()
            || CaseNumberHistory::where('clinic_id', $clinic->id)->where('case_number', $number)->exists();
    }
}
