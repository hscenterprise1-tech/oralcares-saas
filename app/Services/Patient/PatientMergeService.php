<?php

namespace App\Services\Patient;

use App\Models\DeletionRequest;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\PatientStatus;
use App\Services\Communication\PatientMessageEligibility;
use App\Services\Communication\PatientMessaging;
use App\Services\Security\SecurityAudit;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Carries out an approved patient merge (governed action `patient.merge`):
 * a duplicate record's history moves to the patient kept, and the duplicate
 * becomes `merged` — kept, never deleted, pointing at its survivor.
 *
 * - Event and record rows (visits, encounters, treatments, bills, documents,
 *   consents, …) move: their `patient_id` is re-pointed. Every moved id is
 *   kept in the `patient_merges` ledger, so the merge is auditable and can
 *   be reversed.
 * - One-per-patient facts are never overwritten. Medical history is unioned
 *   (an allergy is never lost); a profile, a case number for the same period
 *   or a charted tooth that both patients have stays with the duplicate and
 *   is recorded as a conflict for review.
 * - Specialty enrolments and branch activity are copied onto the survivor.
 * - Governance history (requests) and earlier data conflicts stay with the
 *   duplicate: they are about that record.
 *
 * Runs inside the approval transaction; both patients are locked first.
 */
class PatientMergeService
{
    /** Tables whose rows simply move to the survivor. All carry `clinic_id`. */
    public const MOVED = [
        'appointment_series', 'appointments', 'attached_documents', 'clinical_diagnoses', 'clinical_encounters',
        'clinic_whatsapp_messages', 'clinical_notes', 'clinical_orders', 'consent_records', 'diagnostic_results', 'endodontic_records',
        'implant_records', 'lab_orders', 'orthodontic_cases', 'patient_alerts', 'patient_billings',
        'patient_doctor_assignments', 'patient_follow_ups', 'patient_procedures', 'patient_session_packages',
        'patient_timeline_events', 'patient_tooth_status_histories', 'patient_treatments', 'patient_visits',
        'periodontal_exams', 'physio_assessments', 'physio_care_plans', 'physio_sessions', 'prescriptions',
        'treatment_plans', 'treatment_records', 'xray_records', 'invoices',
    ];

    /**
     * Legacy tables without `clinic_id`. A patient belongs to one clinic, so
     * the patient id alone identifies the rows.
     */
    public const MOVED_BY_PATIENT = ['billing_references', 'queue_entries'];

    /** One-per-patient tables reconciled row by row (see the methods below). */
    public const RECONCILED = ['patient_medical_histories', 'patient_profiles', 'case_numbers', 'case_number_history', 'patient_dental_charts', 'patient_specialties', 'patient_branch_activity', 'patient_clinical_entries'];

    /** Rows about the duplicate record itself: they stay with it. */
    public const STAYS = ['deletion_requests', 'patient_data_conflicts', 'patient_merges'];

    /** Medical-history list fields that are unioned; flags are OR-ed. */
    private const HISTORY_LISTS = ['allergies', 'medical_conditions', 'current_medications', 'previous_surgeries', 'lifestyle_factors', 'chronic_conditions'];

    private const HISTORY_FLAGS = ['pregnancy_status', 'blood_disorder', 'diabetes', 'hypertension', 'cardiac_condition', 'tobacco_use', 'alcohol_use'];

    public function __construct(
        protected PatientBranchActivityRecorder $branchActivity,
        protected SecurityAudit $audit,
    ) {}

    /**
     * Whether two patients can be merged now.
     *
     * @throws DomainException
     */
    public function assertMergeable(Patient $source, Patient $survivor): void
    {
        if ($source->id === $survivor->id) {
            throw new DomainException('Choose a different patient to keep.');
        }
        if ($source->clinic_id !== $survivor->clinic_id) {
            throw new DomainException('Patients of different clinics cannot be merged.');
        }
        foreach ([$source, $survivor] as $patient) {
            if (! in_array($patient->status?->value, PatientStatus::working(), true)) {
                throw new DomainException("{$patient->full_name} is {$patient->status?->value} and cannot be merged.");
            }
        }
    }

    public function merge(Patient $source, Patient $survivor, ?DeletionRequest $request, User $by): PatientMerge
    {
        return DB::transaction(function () use ($source, $survivor, $request, $by) {
            // Lock both, lowest id first, so concurrent merges cannot deadlock.
            $locked = Patient::query()->withoutGlobalScopes()->whereIn('id', [$source->id, $survivor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$source->id];
            $survivor = $locked[$survivor->id];
            $this->assertMergeable($source, $survivor);
            $clinicId = $source->clinic_id;

            // Messages still waiting for the duplicate are not sent — stopped
            // before its WhatsApp rows move to the survivor (they carry the
            // duplicate's number), and undone with the rest if the merge fails.
            app(PatientMessaging::class)->skipPendingFor($source, PatientMessageEligibility::MERGED_REASON);

            $moved = [];
            $conflicts = [];
            foreach ([...self::MOVED, ...self::MOVED_BY_PATIENT] as $table) {
                $ids = DB::table($table)->when(in_array($table, self::MOVED, true), fn ($q) => $q->where('clinic_id', $clinicId))
                    ->where('patient_id', $source->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
                foreach (array_chunk($ids, 1000) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->update(['patient_id' => $survivor->id]);
                }
                if ($ids !== []) {
                    $moved[$table] = $ids;
                }
            }

            $this->mergeHistory($clinicId, $source, $survivor, $moved, $conflicts);
            $this->moveIfAbsent('patient_profiles', $clinicId, $source, $survivor, $moved, $conflicts);
            $this->moveCaseNumbers($clinicId, $source, $survivor, $moved, $conflicts);
            $this->moveChart($clinicId, $source, $survivor, $moved, $conflicts);
            $this->copyEnrolments($clinicId, $source, $survivor, $moved);
            $this->moveClinicalEntries($clinicId, $source, $survivor, $moved, $conflicts);

            $source->forceFill(['status' => PatientStatus::Merged])->save();

            $merge = PatientMerge::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinicId,
                'source_patient_id' => $source->id,
                'survivor_patient_id' => $survivor->id,
                'deletion_request_id' => $request?->id,
                'moved' => $moved,
                'conflicts' => $conflicts ?: null,
                'merged_by' => $by->id,
                'merged_at' => now(),
            ]);

            // Rows moved per table (re-pointed references, keyed `table.column`, are not extra rows).
            $counts = array_map('count', array_filter($moved, fn (string $key) => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY));
            PatientTimelineEvent::query()->withoutGlobalScopes()->create([
                'organization_id' => $survivor->organization_id,
                'clinic_id' => $clinicId,
                'patient_id' => $survivor->id,
                'actor_id' => $by->id,
                'event_type' => 'patient_merged',
                'context' => ['merge_id' => $merge->id, 'from_patient_id' => $source->id, 'from_uhid' => $source->uhid, 'records' => array_sum($counts)],
            ]);
            $this->audit->record('patient.merged', $survivor, [
                'merge_id' => $merge->id, 'source_patient_id' => $source->id, 'deletion_request_id' => $request?->id,
                'moved' => $counts, 'conflicts' => count($conflicts),
            ], ['clinic_id' => $clinicId]);

            return $merge;
        });
    }

    /**
     * @param  array<string, list<int>>  $moved
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function mergeHistory(int $clinicId, Patient $source, Patient $survivor, array &$moved, array &$conflicts): void
    {
        $from = DB::table('patient_medical_histories')->where('clinic_id', $clinicId)->where('patient_id', $source->id)->first();
        if (! $from) {
            return;
        }
        $into = DB::table('patient_medical_histories')->where('clinic_id', $clinicId)->where('patient_id', $survivor->id)->first();
        if (! $into) {
            DB::table('patient_medical_histories')->where('id', $from->id)->update(['patient_id' => $survivor->id]);
            $moved['patient_medical_histories'] = [(int) $from->id];

            return;
        }

        $update = [];
        foreach (self::HISTORY_LISTS as $field) {
            $union = collect(json_decode((string) $into->{$field}, true) ?: [])->merge(json_decode((string) $from->{$field}, true) ?: [])
                ->map(fn ($v) => trim((string) $v))->filter()->unique(fn ($v) => mb_strtolower($v))->values()->all();
            if ($union !== (json_decode((string) $into->{$field}, true) ?: [])) {
                $update[$field] = json_encode($union);
            }
        }
        foreach (self::HISTORY_FLAGS as $field) {
            if ($from->{$field} && ! $into->{$field}) {
                $update[$field] = true;
            }
        }
        if ($update !== []) {
            DB::table('patient_medical_histories')->where('id', $into->id)->update($update + ['updated_at' => now()]);
        }
        $conflicts[] = ['table' => 'patient_medical_histories', 'kept_id' => (int) $into->id, 'other_id' => (int) $from->id, 'resolution' => 'unioned into the kept record'];
    }

    /**
     * @param  array<string, list<int>>  $moved
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function moveIfAbsent(string $table, int $clinicId, Patient $source, Patient $survivor, array &$moved, array &$conflicts): void
    {
        $from = DB::table($table)->where('clinic_id', $clinicId)->where('patient_id', $source->id)->value('id');
        if (! $from) {
            return;
        }
        $into = DB::table($table)->where('clinic_id', $clinicId)->where('patient_id', $survivor->id)->value('id');
        if ($into) {
            $conflicts[] = ['table' => $table, 'kept_id' => (int) $into, 'other_id' => (int) $from, 'resolution' => 'left with the merged record for review'];

            return;
        }
        DB::table($table)->where('id', $from)->update(['patient_id' => $survivor->id]);
        $moved[$table] = [(int) $from];
    }

    /**
     * A case number for a period the survivor already has stays with the duplicate.
     *
     * @param  array<string, list<int>>  $moved
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function moveCaseNumbers(int $clinicId, Patient $source, Patient $survivor, array &$moved, array &$conflicts): void
    {
        $taken = DB::table('case_numbers')->where('clinic_id', $clinicId)->where('patient_id', $survivor->id)->pluck('id', 'period_key');
        foreach (DB::table('case_numbers')->where('clinic_id', $clinicId)->where('patient_id', $source->id)->get(['id', 'period_key']) as $case) {
            if ($taken->has($case->period_key)) {
                // Visits that moved were filed under the duplicate's number: file them under the survivor's.
                $visits = DB::table('patient_visits')->whereIn('id', $moved['patient_visits'] ?? [])->where('case_number_id', $case->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
                DB::table('patient_visits')->whereIn('id', $visits)->update(['case_number_id' => $taken[$case->period_key]]);
                $conflicts[] = ['table' => 'case_numbers', 'kept_id' => (int) $taken[$case->period_key], 'other_id' => (int) $case->id, 'resolution' => 'both kept; the merged record keeps its own', 'visits_refiled' => $visits];

                continue;
            }
            DB::table('case_numbers')->where('id', $case->id)->update(['patient_id' => $survivor->id]);
            $historyIds = DB::table('case_number_history')->where('case_number_id', $case->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
            DB::table('case_number_history')->whereIn('id', $historyIds)->update(['patient_id' => $survivor->id]);
            $moved['case_numbers'][] = (int) $case->id;
            if ($historyIds !== []) {
                $moved['case_number_history'] = [...($moved['case_number_history'] ?? []), ...$historyIds];
            }
        }
    }

    /**
     * A tooth only the duplicate has charted moves; one both have keeps the
     * survivor's current state (the duplicate's history has moved already).
     *
     * @param  array<string, list<int>>  $moved
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function moveChart(int $clinicId, Patient $source, Patient $survivor, array &$moved, array &$conflicts): void
    {
        $taken = DB::table('patient_dental_charts')->where('clinic_id', $clinicId)->where('patient_id', $survivor->id)->pluck('id', 'tooth_number');
        foreach (DB::table('patient_dental_charts')->where('clinic_id', $clinicId)->where('patient_id', $source->id)->get(['id', 'tooth_number']) as $tooth) {
            if ($taken->has($tooth->tooth_number)) {
                $conflicts[] = ['table' => 'patient_dental_charts', 'tooth' => (int) $tooth->tooth_number, 'kept_id' => (int) $taken[$tooth->tooth_number], 'other_id' => (int) $tooth->id, 'resolution' => 'kept the current state of the patient kept'];

                continue;
            }
            DB::table('patient_dental_charts')->where('id', $tooth->id)->update(['patient_id' => $survivor->id]);
            $moved['patient_dental_charts'][] = (int) $tooth->id;
        }
    }

    /**
     * The duplicate's conditions, allergies and medications move to the
     * survivor; one the survivor already has (same kind and item) stays on
     * the duplicate and is noted. The survivor's derived alerts are then
     * rebuilt from its entries, replacing the alert rows moved with the rest.
     *
     * @param  array<string, list<int>>  $moved
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function moveClinicalEntries(int $clinicId, Patient $source, Patient $survivor, array &$moved, array &$conflicts): void
    {
        $existing = DB::table('patient_clinical_entries')->where('clinic_id', $clinicId)->where('patient_id', $survivor->id)
            ->get(['id', 'kind', 'dedupe_key'])->keyBy(fn ($row) => "{$row->kind}:{$row->dedupe_key}");
        $sourceEntries = DB::table('patient_clinical_entries')->where('clinic_id', $clinicId)->where('patient_id', $source->id)->get(['id', 'kind', 'dedupe_key']);

        foreach ($sourceEntries as $entry) {
            $kept = $existing->get("{$entry->kind}:{$entry->dedupe_key}");
            if ($kept) {
                $conflicts[] = ['table' => 'patient_clinical_entries', 'kept_id' => (int) $kept->id, 'other_id' => (int) $entry->id, 'resolution' => 'the survivor already records this'];

                continue;
            }
            DB::table('patient_clinical_entries')->where('id', $entry->id)->update(['patient_id' => $survivor->id, 'updated_at' => now()]);
            $moved['patient_clinical_entries'][] = (int) $entry->id;
        }

        // Only once either record is on clinical entries: until the backfill
        // reaches them, their alerts still come from the legacy history.
        if ($sourceEntries->isNotEmpty() || $existing->isNotEmpty()) {
            app(PatientClinicalEntries::class)->syncDerivedAlerts($survivor);
        }
    }

    /**
     * The survivor gains the duplicate's specialty enrolments and branch
     * activity; treatments that moved are re-pointed at the survivor's own
     * enrolment, so no moved row refers back to the duplicate.
     *
     * @param  array<string, list<int>>  $moved
     */
    private function copyEnrolments(int $clinicId, Patient $source, Patient $survivor, array &$moved): void
    {
        $now = now();
        $enrolments = DB::table('patient_specialties')->where('clinic_id', $clinicId)->where('patient_id', $source->id)->get();
        $rows = $enrolments
            ->map(fn ($e) => ['clinic_id' => $clinicId, 'patient_id' => $survivor->id, 'clinic_specialty_id' => $e->clinic_specialty_id, 'status' => $e->status, 'source' => 'manual',
                'enrolled_at' => $e->enrolled_at, 'enrolled_by' => $e->enrolled_by, 'last_activity_at' => $e->last_activity_at, 'created_at' => $now, 'updated_at' => $now])->all();
        if ($rows !== []) {
            DB::table('patient_specialties')->insertOrIgnore($rows);
            $survivorEnrolment = DB::table('patient_specialties')->where('clinic_id', $clinicId)->where('patient_id', $survivor->id)->pluck('id', 'clinic_specialty_id');
            foreach ($enrolments as $enrolment) {
                $treatments = DB::table('patient_treatments')->whereIn('id', $moved['patient_treatments'] ?? [])->where('patient_specialty_id', $enrolment->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($treatments !== []) {
                    DB::table('patient_treatments')->whereIn('id', $treatments)->update(['patient_specialty_id' => $survivorEnrolment[$enrolment->clinic_specialty_id]]);
                    $moved['patient_treatments.patient_specialty_id'] = [...($moved['patient_treatments.patient_specialty_id'] ?? []), ...$treatments];
                }
            }
        }

        foreach (DB::table('patient_branch_activity')->where('clinic_id', $clinicId)->where('patient_id', $source->id)->get() as $activity) {
            $this->branchActivity->record($clinicId, $survivor->id, (int) $activity->branch_id, Carbon::parse($activity->first_seen_at), 'backfill');
            $this->branchActivity->record($clinicId, $survivor->id, (int) $activity->branch_id, Carbon::parse($activity->last_seen_at), 'backfill');
        }
    }
}
