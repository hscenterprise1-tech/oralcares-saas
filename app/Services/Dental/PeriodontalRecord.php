<?php

namespace App\Services\Dental;

use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PeriodontalExam;
use App\Services\DentalChartService;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Periodontal charting (part of the `odontogram` extension), on
 * Patient → Encounter: one exam per consultation, written while the
 * consultation is open (re-recording replaces that exam's teeth), one row per
 * tooth with the six standard sites.
 *
 * The whole-mouth figures (BOP %, plaque %, mean probing depth, sites ≥4 and
 * ≥6 mm) are computed here from the sites recorded, never typed in.
 */
class PeriodontalRecord
{
    public function __construct(
        protected DentalChartService $fdi,
        protected SecurityAudit $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $teeth
     * @param  array{stage?: ?string, grade?: ?string, extent?: ?string, diagnosis?: ?string, notes?: ?string}  $summary
     */
    public function recordExam(ClinicalEncounter $encounter, array $teeth, array $summary, ClinicEmployee $examiner): PeriodontalExam
    {
        $rows = $this->rows($teeth);
        if ($rows === []) {
            throw new InvalidArgumentException('Record at least one tooth.');
        }
        $this->assertSummary($summary);

        return DB::transaction(function () use ($encounter, $rows, $summary, $examiner) {
            /** @var ClinicalEncounter $locked */
            $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw new DomainException('This consultation is signed. Chart in an open consultation.');
            }

            $exam = PeriodontalExam::query()->withoutGlobalScopes()->where('encounter_id', $locked->id)->first();
            $first = $exam === null;
            $exam ??= new PeriodontalExam(['clinic_id' => $locked->clinic_id, 'patient_id' => $locked->patient_id, 'encounter_id' => $locked->id]);

            $exam->fill([
                ...$this->figures($rows),
                'examined_by' => $examiner->id,
                'stage' => $summary['stage'] ?? null,
                'grade' => $summary['grade'] ?? null,
                'extent' => $summary['extent'] ?? null,
                'diagnosis' => $this->text($summary['diagnosis'] ?? null, 500),
                'notes' => $this->text($summary['notes'] ?? null, 5000),
            ])->save();

            $exam->teeth()->delete();
            foreach ($rows as $row) {
                $exam->teeth()->create(['clinic_id' => $exam->clinic_id, ...$row]);
            }

            if ($first) {
                PatientTimelineEvent::create([
                    'organization_id' => Clinic::query()->whereKey($exam->clinic_id)->value('organization_id'),
                    'clinic_id' => $exam->clinic_id,
                    'patient_id' => $exam->patient_id,
                    'actor_id' => auth()->id(),
                    'event_type' => 'periodontal_exam_recorded',
                    'context' => ['encounter_number' => $locked->encounter_number, 'bop_percent' => $exam->bop_percent, 'sites_4mm_plus' => $exam->sites_4mm_plus],
                ]);
            }
            $this->audit->record('dental.periodontal.recorded', $exam, ['patient_id' => $exam->patient_id, 'teeth' => count($rows), 'first' => $first], ['clinic_id' => $exam->clinic_id]);

            return $exam->load('teeth');
        });
    }

    /**
     * The patient's exams, newest first.
     *
     * @return Collection<int, PeriodontalExam>
     */
    public function history(Patient $patient, int $limit = 10): Collection
    {
        return PeriodontalExam::query()->withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->with(['examiner:id,name', 'encounter:id,encounter_number,branch_id'])
            ->latest('id')->limit($limit)->get();
    }

    /**
     * @param  list<array<string, mixed>>  $teeth
     * @return list<array<string, mixed>>
     */
    private function rows(array $teeth): array
    {
        $rows = [];
        foreach ($teeth as $tooth) {
            if (! is_array($tooth)) {
                continue;
            }
            $number = (int) ($tooth['tooth'] ?? 0);
            if (! $this->fdi->isValidFdiToothNumber($number)) {
                throw new InvalidArgumentException("Tooth {$number} is not an FDI tooth number.");
            }
            if (isset($rows[$number])) {
                throw new InvalidArgumentException("Tooth {$number} is listed twice.");
            }

            $missing = (bool) ($tooth['missing'] ?? false);
            $rows[$number] = [
                'tooth_number' => $number,
                'missing' => $missing,
                'probing_depths' => $missing ? null : $this->sites($tooth['probing_depths'] ?? [], 0, 15, 'Probing depth'),
                'gingival_margins' => $missing ? null : $this->sites($tooth['gingival_margins'] ?? [], -10, 15, 'Gingival margin'),
                'bleeding' => $missing ? null : $this->flags($tooth['bleeding'] ?? []),
                'plaque' => $missing ? null : $this->flags($tooth['plaque'] ?? []),
                'mobility' => $missing ? null : $this->grade($tooth['mobility'] ?? null, 3, 'Mobility'),
                'furcation' => $missing ? null : $this->grade($tooth['furcation'] ?? null, 3, 'Furcation'),
            ];
        }
        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function figures(array $rows): array
    {
        $present = array_filter($rows, fn (array $row) => ! $row['missing']);
        $depths = collect($present)->flatMap(fn (array $row) => $row['probing_depths'] ?? [])->filter(fn ($v) => $v !== null)->values();
        $bleedingSites = collect($present)->flatMap(fn (array $row) => $row['bleeding'] ?? []);
        $plaqueSites = collect($present)->flatMap(fn (array $row) => $row['plaque'] ?? []);
        $sitesExamined = max(1, count($present) * 6);

        return [
            'teeth_examined' => count($present),
            'bop_percent' => $present === [] ? null : round($bleedingSites->filter()->count() / $sitesExamined * 100, 1),
            'plaque_percent' => $present === [] ? null : round($plaqueSites->filter()->count() / $sitesExamined * 100, 1),
            'mean_probing_depth' => $depths->isEmpty() ? null : round($depths->avg(), 1),
            'sites_4mm_plus' => $depths->filter(fn ($v) => $v >= 4)->count(),
            'sites_6mm_plus' => $depths->filter(fn ($v) => $v >= 6)->count(),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<?int>
     */
    private function sites(array $values, int $min, int $max, string $label): array
    {
        $values = array_values($values);
        if (count($values) > 6) {
            throw new InvalidArgumentException("{$label} has six sites per tooth.");
        }
        $sites = [];
        for ($i = 0; $i < 6; $i++) {
            $value = $values[$i] ?? null;
            if ($value === null || $value === '') {
                $sites[] = null;

                continue;
            }
            if (! is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
                throw new InvalidArgumentException("{$label} is a whole number of mm from {$min} to {$max}.");
            }
            $sites[] = (int) $value;
        }

        return $sites;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<bool>
     */
    private function flags(array $values): array
    {
        $values = array_values($values);

        return array_map(fn (int $i) => filter_var($values[$i] ?? false, FILTER_VALIDATE_BOOL), range(0, 5));
    }

    private function grade(mixed $value, int $max, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > $max) {
            throw new InvalidArgumentException("{$label} is graded 0 to {$max}.");
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function assertSummary(array $summary): void
    {
        $vocabulary = config('clinic_dental.periodontal');
        foreach (['stage' => $vocabulary['stages'], 'grade' => $vocabulary['grades'], 'extent' => array_keys($vocabulary['extents'])] as $key => $allowed) {
            if (! empty($summary[$key]) && ! in_array($summary[$key], $allowed, true)) {
                throw new InvalidArgumentException("Unknown periodontal {$key}.");
            }
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
