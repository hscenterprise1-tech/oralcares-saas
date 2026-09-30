<?php

namespace App\Services\Patient;

use Illuminate\Support\Facades\DB;

/**
 * One-time move of `case_numbers` from "one row per visit" to "one row per
 * patient per period", called by the restructure migration and tested
 * directly. Safe to run more than once: every step only touches rows the
 * previous run left unfinished.
 *
 * Nothing is lost. When a patient has several numbers in one period, the
 * earliest (lowest sequence) stays live and the others are copied into
 * `case_number_history` before they leave `case_numbers`; searching still
 * finds them there. Issued numbers are never rewritten — no prefix is added.
 */
class CaseNumberBackfill
{
    /**
     * @return array{visits_linked: int, merged: int, keyed: int, counters: int}
     */
    public function __invoke(): array
    {
        return DB::transaction(fn (): array => [
            'visits_linked' => $this->linkVisits(),
            'merged' => $this->mergeDuplicates(),
            'keyed' => $this->fillKeys(),
            'counters' => $this->seedCounters(),
        ]);
    }

    /**
     * Every number issued before this change was calendar-year based and
     * came from a yearly counter.
     */
    private function fillKeys(): int
    {
        $rows = DB::table('case_numbers')->whereNull('period_key')->get(['id', 'year', 'case_number']);

        foreach ($rows as $row) {
            DB::table('case_numbers')->where('id', $row->id)->update([
                'period_key' => 'CY'.$row->year,
                'counter_key' => 'CY'.$row->year,
                'search_key' => CaseNumberFormatter::searchKey($row->case_number),
            ]);
        }

        return $rows->count();
    }

    private function linkVisits(): int
    {
        $rows = DB::table('case_numbers')
            ->join('patient_visits', 'patient_visits.id', '=', 'case_numbers.patient_visit_id')
            ->whereNull('patient_visits.case_number_id')
            ->get(['case_numbers.id as case_number_id', 'patient_visits.id as visit_id']);

        foreach ($rows as $row) {
            DB::table('patient_visits')->where('id', $row->visit_id)->update(['case_number_id' => $row->case_number_id]);
        }

        return $rows->count();
    }

    /**
     * Runs before keys are filled in, so rows are grouped by the period they
     * *will* get — the unique `(clinic, patient, period_key)` index would
     * otherwise reject keying the second number of a pair.
     */
    private function mergeDuplicates(): int
    {
        $groups = DB::table('case_numbers')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (object $row) => implode('|', [$row->clinic_id, $row->patient_id, $row->period_key ?? 'CY'.$row->year]))
            ->filter(fn ($rows) => $rows->count() > 1);

        $merged = 0;

        foreach ($groups as $rows) {
            $kept = $rows->shift();

            foreach ($rows as $duplicate) {
                DB::table('case_number_history')->insert([
                    'original_id' => $duplicate->id,
                    'clinic_id' => $duplicate->clinic_id,
                    'patient_id' => $duplicate->patient_id,
                    'patient_visit_id' => $duplicate->patient_visit_id,
                    'year' => $duplicate->year,
                    'sequence' => $duplicate->sequence,
                    'case_number' => $duplicate->case_number,
                    'period_key' => $duplicate->period_key ?? 'CY'.$duplicate->year,
                    'counter_key' => $duplicate->counter_key ?? 'CY'.$duplicate->year,
                    'search_key' => $duplicate->search_key ?? CaseNumberFormatter::searchKey($duplicate->case_number),
                    'merged_into_id' => $kept->id,
                    'original_created_at' => $duplicate->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('patient_visits')->where('case_number_id', $duplicate->id)->update(['case_number_id' => $kept->id]);
                DB::table('case_numbers')->where('id', $duplicate->id)->delete();
                $merged++;
            }
        }

        return $merged;
    }

    /**
     * Counters start at the highest sequence already issued — live or merged
     * into history — so no number is ever handed out twice.
     */
    private function seedCounters(): int
    {
        $highest = DB::table('case_numbers')
            ->select('clinic_id', 'counter_key', DB::raw('MAX(sequence) as highest'))
            ->groupBy('clinic_id', 'counter_key')
            ->get()
            ->concat(
                DB::table('case_number_history')
                    ->select('clinic_id', 'counter_key', DB::raw('MAX(sequence) as highest'))
                    ->groupBy('clinic_id', 'counter_key')
                    ->get()
            );

        $seeded = 0;

        foreach ($highest as $row) {
            $counter = DB::table('case_number_sequences')
                ->where('clinic_id', $row->clinic_id)
                ->where('counter_key', $row->counter_key)
                ->first();

            if (! $counter) {
                DB::table('case_number_sequences')->insert([
                    'clinic_id' => $row->clinic_id,
                    'counter_key' => $row->counter_key,
                    'last_sequence' => (int) $row->highest,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $seeded++;
            } elseif ($counter->last_sequence < (int) $row->highest) {
                DB::table('case_number_sequences')->where('id', $counter->id)->update(['last_sequence' => (int) $row->highest]);
                $seeded++;
            }
        }

        return $seeded;
    }
}
