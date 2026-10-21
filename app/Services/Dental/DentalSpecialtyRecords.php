<?php

namespace App\Services\Dental;

use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\EndodonticRecord;
use App\Models\ImplantRecord;
use App\Models\OrthodonticCase;
use App\Models\OrthodonticVisit;
use App\Models\PatientTimelineEvent;
use App\Services\DentalChartService;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The writer of the dental specialty records, on Patient → Encounter:
 * endodontic (root canal) records, implant records, and orthodontic cases
 * with their adjustment visits.
 *
 * Every write happens in an open consultation the caller may write to, is
 * checked against the vocabulary here, keeps which consultation last changed
 * the record, leaves a patient-timeline entry, and is audited with the
 * fields that changed. Nothing is deleted: a record that should not exist
 * is voided through owner-approved governance.
 */
class DentalSpecialtyRecords
{
    public const PULP = ['normal', 'reversible_pulpitis', 'irreversible_pulpitis_symptomatic', 'irreversible_pulpitis_asymptomatic', 'necrotic', 'previously_treated', 'previously_initiated'];

    public const PERIAPICAL = ['normal', 'symptomatic_apical_periodontitis', 'asymptomatic_apical_periodontitis', 'acute_apical_abscess', 'chronic_apical_abscess', 'condensing_osteitis'];

    public function __construct(
        protected DentalChartService $fdi,
        protected SecurityAudit $audit,
    ) {}

    /**
     * Start (no record) or update a root canal record.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveEndodontic(ClinicalEncounter $encounter, ?EndodonticRecord $record, array $data, ClinicEmployee $by): EndodonticRecord
    {
        return DB::transaction(function () use ($encounter, $record, $data, $by) {
            $encounter = $this->lockOpen($encounter);
            $record = $record ? $this->lockOwned($record, $encounter) : new EndodonticRecord([
                'clinic_id' => $encounter->clinic_id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id,
                'tooth_number' => $this->tooth($data['tooth_number'] ?? null), 'created_by' => $by->id,
            ]);
            if ($record->exists && $record->status === 'completed' && ($data['status'] ?? 'completed') === 'completed' && array_diff(array_keys($data), ['review_on', 'notes', 'status']) !== []) {
                throw new DomainException('This root canal treatment is completed. Only the review date and notes can still change.');
            }

            $status = $this->choice($data['status'] ?? $record->status ?? 'in_progress', EndodonticRecord::STATUSES, 'status');
            $record->fill([
                'last_encounter_id' => $encounter->id,
                'status' => $status,
                'stage' => $this->choice($data['stage'] ?? $record->stage ?? 'diagnosis', EndodonticRecord::STAGES, 'stage'),
                'pulp_status' => $this->choiceOrNull($data['pulp_status'] ?? $record->pulp_status, self::PULP, 'pulp status'),
                'periapical_status' => $this->choiceOrNull($data['periapical_status'] ?? $record->periapical_status, self::PERIAPICAL, 'periapical status'),
                'diagnosis' => $this->text($data, 'diagnosis', $record, 500),
                'canals' => array_key_exists('canals', $data) ? $this->canals($data['canals']) : $record->canals,
                'instrumentation_notes' => $this->text($data, 'instrumentation_notes', $record, 5000),
                'obturation_technique' => $this->text($data, 'obturation_technique', $record, 100),
                'obturation_material' => $this->text($data, 'obturation_material', $record, 100),
                'obturated_on' => $this->date($data, 'obturated_on', $record),
                'temporary_restoration' => $this->text($data, 'temporary_restoration', $record, 150),
                'permanent_restoration' => $this->text($data, 'permanent_restoration', $record, 150),
                'review_on' => $this->date($data, 'review_on', $record),
                'notes' => $this->text($data, 'notes', $record, 5000),
                'completed_at' => $status === 'completed' ? ($record->completed_at ?? now()) : null,
            ]);

            return $this->persist($record, 'endodontic', "Tooth {$record->tooth_number} root canal", $encounter);
        });
    }

    /**
     * Plan (no record) or update an implant.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveImplant(ClinicalEncounter $encounter, ?ImplantRecord $record, array $data, ClinicEmployee $by): ImplantRecord
    {
        return DB::transaction(function () use ($encounter, $record, $data, $by) {
            $encounter = $this->lockOpen($encounter);
            $record = $record ? $this->lockOwned($record, $encounter) : new ImplantRecord([
                'clinic_id' => $encounter->clinic_id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id,
                'tooth_number' => $this->tooth($data['tooth_number'] ?? null), 'created_by' => $by->id,
            ]);

            $record->fill([
                'last_encounter_id' => $encounter->id,
                'status' => $this->choice($data['status'] ?? $record->status ?? 'planned', ImplantRecord::STATUSES, 'status'),
                'system' => $this->text($data, 'system', $record, 120),
                'diameter_mm' => $this->number($data, 'diameter_mm', $record, 2.0, 8.0),
                'length_mm' => $this->number($data, 'length_mm', $record, 4.0, 25.0),
                'lot_number' => $this->text($data, 'lot_number', $record, 60),
                'placed_on' => $this->date($data, 'placed_on', $record),
                'insertion_torque_ncm' => $this->number($data, 'insertion_torque_ncm', $record, 5, 100, true),
                'integration_status' => $this->choiceOrNull(array_key_exists('integration_status', $data) ? $data['integration_status'] : $record->integration_status, ImplantRecord::INTEGRATION, 'integration status'),
                'abutment' => $this->text($data, 'abutment', $record, 120),
                'abutment_on' => $this->date($data, 'abutment_on', $record),
                'crown_status' => $this->choiceOrNull(array_key_exists('crown_status', $data) ? $data['crown_status'] : $record->crown_status, ImplantRecord::CROWN, 'crown status'),
                'crown_on' => $this->date($data, 'crown_on', $record),
                'review_on' => $this->date($data, 'review_on', $record),
                'complications' => $this->text($data, 'complications', $record, 5000),
                'notes' => $this->text($data, 'notes', $record, 5000),
            ]);
            if ($record->placed_on === null && in_array($record->status, ['placed', 'integrating', 'restored'], true)) {
                throw new InvalidArgumentException('Record the placement date for a placed implant.');
            }

            return $this->persist($record, 'implant', "Implant at {$record->tooth_number}", $encounter);
        });
    }

    /**
     * Open (no case) or update an orthodontic case.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveOrthodonticCase(ClinicalEncounter $encounter, ?OrthodonticCase $case, array $data, ClinicEmployee $by): OrthodonticCase
    {
        return DB::transaction(function () use ($encounter, $case, $data, $by) {
            $encounter = $this->lockOpen($encounter);
            if (! $case && OrthodonticCase::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)
                ->where('patient_id', $encounter->patient_id)->where('status', 'active')->whereNull('voided_at')->lockForUpdate()->exists()) {
                throw new DomainException('The patient already has an active orthodontic case.');
            }
            $case = $case ? $this->lockOwned($case, $encounter) : new OrthodonticCase([
                'clinic_id' => $encounter->clinic_id, 'patient_id' => $encounter->patient_id, 'encounter_id' => $encounter->id,
                'created_by' => $by->id, 'started_on' => now()->toDateString(),
            ]);

            $phase = $this->choice($data['phase'] ?? $case->phase ?? 'planning', OrthodonticCase::PHASES, 'phase');
            $case->fill([
                'arches' => $this->choice($data['arches'] ?? $case->arches ?? 'both', ['upper', 'lower', 'both'], 'arches'),
                'appliance' => $this->choice($data['appliance'] ?? $case->appliance ?? '', array_keys(OrthodonticCase::APPLIANCES), 'appliance'),
                'bracket_system' => $this->text($data, 'bracket_system', $case, 120),
                'phase' => $phase,
                'status' => $phase === 'completed' ? 'completed' : 'active',
                'completed_at' => $phase === 'completed' ? ($case->completed_at ?? now()) : null,
                'diagnosis' => $this->text($data, 'diagnosis', $case, 500),
                'started_on' => $this->date($data, 'started_on', $case),
                'estimated_months' => $this->number($data, 'estimated_months', $case, 1, 72, true),
                'bracketed_teeth' => array_key_exists('bracketed_teeth', $data) ? $this->teeth((array) $data['bracketed_teeth']) : $case->bracketed_teeth,
                'notes' => $this->text($data, 'notes', $case, 5000),
            ]);

            return $this->persist($case, 'orthodontic', 'Orthodontic case', $encounter);
        });
    }

    /**
     * Record (or, while the consultation is open, re-record) this visit's adjustment.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordOrthodonticVisit(ClinicalEncounter $encounter, OrthodonticCase $case, array $data, ClinicEmployee $by): OrthodonticVisit
    {
        return DB::transaction(function () use ($encounter, $case, $data, $by) {
            $encounter = $this->lockOpen($encounter);
            $case = $this->lockOwned($case, $encounter);
            if ($case->status !== 'active') {
                throw new DomainException('Adjustments are recorded on an active case.');
            }

            $visit = OrthodonticVisit::query()->withoutGlobalScopes()->where('encounter_id', $encounter->id)->first()
                ?? new OrthodonticVisit(['orthodontic_case_id' => $case->id, 'clinic_id' => $case->clinic_id, 'encounter_id' => $encounter->id]);
            $first = ! $visit->exists;
            $visit->fill([
                'phase' => $this->choiceOrNull($data['phase'] ?? $case->phase, OrthodonticCase::PHASES, 'phase'),
                'upper_wire' => $this->text($data, 'upper_wire', $visit, 80),
                'lower_wire' => $this->text($data, 'lower_wire', $visit, 80),
                'adjustments' => $this->text($data, 'adjustments', $visit, 5000),
                'elastics' => $this->text($data, 'elastics', $visit, 150),
                'notes' => $this->text($data, 'notes', $visit, 5000),
                'next_visit_weeks' => $this->number($data, 'next_visit_weeks', $visit, 1, 52, true),
                'recorded_by' => $by->id,
            ])->save();

            if ($visit->phase && $visit->phase !== $case->phase) {
                $case->update(['phase' => $visit->phase, 'status' => $visit->phase === 'completed' ? 'completed' : 'active', 'completed_at' => $visit->phase === 'completed' ? now() : null]);
            }
            if ($first) {
                $this->timeline($case->clinic_id, $case->patient_id, 'orthodontic_visit_recorded', ['encounter_number' => $encounter->encounter_number, 'phase' => $visit->phase]);
            }
            $this->audit->record('dental.orthodontic.visit', $visit, ['patient_id' => $case->patient_id, 'case_id' => $case->id, 'first' => $first], ['clinic_id' => $case->clinic_id]);

            return $visit;
        });
    }

    /**
     * Save, then leave a timeline entry (on creation and on status changes)
     * and an audit entry naming the fields that changed.
     *
     * @template T of Model
     *
     * @param  T  $record
     * @return T
     */
    private function persist(Model $record, string $kind, string $label, ClinicalEncounter $encounter): Model
    {
        $creating = ! $record->exists;
        $changed = array_keys($record->getDirty());
        $statusChanged = $record->isDirty('status') && ! $creating;
        $record->save();

        if ($creating || $statusChanged) {
            $this->timeline($record->clinic_id, $record->patient_id, $creating ? "{$kind}_started" : "{$kind}_status_changed", [
                'label' => $label, 'status' => $record->status, 'tooth_number' => $record->tooth_number ?? null, 'encounter_number' => $encounter->encounter_number,
            ]);
        }
        $this->audit->record("dental.{$kind}.".($creating ? 'created' : 'updated'), $record, [
            'patient_id' => $record->patient_id, 'tooth_number' => $record->tooth_number ?? null, 'fields' => array_values(array_diff($changed, ['updated_at', 'created_at', 'last_encounter_id'])),
        ], ['clinic_id' => $record->clinic_id]);

        return $record;
    }

    private function lockOpen(ClinicalEncounter $encounter): ClinicalEncounter
    {
        /** @var ClinicalEncounter $locked */
        $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isEditable()) {
            throw new DomainException('This consultation is signed. Record dental treatment in an open consultation.');
        }

        return $locked;
    }

    /**
     * @template T of Model
     *
     * @param  T  $record
     * @return T
     */
    private function lockOwned(Model $record, ClinicalEncounter $encounter): Model
    {
        $locked = $record->newQueryWithoutScopes()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->clinic_id !== $encounter->clinic_id || $locked->patient_id !== $encounter->patient_id) {
            throw new InvalidArgumentException('That record belongs to a different patient.');
        }
        if ($locked->voided_at !== null) {
            throw new DomainException('This record is void and can no longer change.');
        }

        return $locked;
    }

    private function tooth(mixed $value): int
    {
        $tooth = (int) $value;
        if (! $this->fdi->isValidFdiToothNumber($tooth)) {
            throw new InvalidArgumentException('Choose an FDI tooth number.');
        }

        return $tooth;
    }

    /**
     * @param  array<int, mixed>  $teeth
     * @return list<int>|null
     */
    private function teeth(array $teeth): ?array
    {
        $clean = array_values(array_unique(array_map(fn ($t) => $this->tooth($t), $teeth)));
        sort($clean);

        return $clean === [] ? null : $clean;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function canals(mixed $rows): ?array
    {
        $clean = [];
        foreach ((array) $rows as $row) {
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $length = isset($row['working_length']) && $row['working_length'] !== '' ? (float) $row['working_length'] : null;
            if ($length !== null && ($length < 5 || $length > 40)) {
                throw new InvalidArgumentException('Working length is between 5 and 40 mm.');
            }
            $clean[] = array_filter([
                'name' => mb_substr(trim((string) $row['name']), 0, 20),
                'working_length' => $length,
                'reference' => isset($row['reference']) ? mb_substr(trim((string) $row['reference']), 0, 40) : null,
                'master_file' => isset($row['master_file']) ? mb_substr(trim((string) $row['master_file']), 0, 40) : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $clean === [] ? null : array_slice($clean, 0, 6);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(mixed $value, array $allowed, string $label): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Unknown {$label}.");
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choiceOrNull(mixed $value, array $allowed, string $label): ?string
    {
        return $value === null || $value === '' ? null : $this->choice($value, $allowed, $label);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key, Model $record, int $max): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $record->{$key};
        }
        $value = is_string($data[$key]) ? trim($data[$key]) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function date(array $data, string $key, Model $record): ?string
    {
        if (! array_key_exists($key, $data)) {
            return $record->{$key}?->toDateString();
        }
        $value = $data[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! strtotime($value)) {
            throw new InvalidArgumentException("The {$key} must be a date.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function number(array $data, string $key, Model $record, float $min, float $max, bool $integer = false): int|float|null
    {
        if (! array_key_exists($key, $data)) {
            return $record->{$key};
        }
        $value = $data[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new InvalidArgumentException(str_replace('_', ' ', ucfirst($key))." is between {$min} and {$max}.");
        }

        return $integer ? (int) $value : (float) $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function timeline(int $clinicId, int $patientId, string $event, array $context): void
    {
        PatientTimelineEvent::create([
            'organization_id' => Clinic::query()->whereKey($clinicId)->value('organization_id'),
            'clinic_id' => $clinicId,
            'patient_id' => $patientId,
            'actor_id' => auth()->id(),
            'event_type' => $event,
            'context' => $context,
        ]);
    }
}
