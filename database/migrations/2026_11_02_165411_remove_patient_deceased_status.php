<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The deceased status is removed from OralCares (2026-11-02, owner decision).
 *
 * - Every patient recorded as deceased becomes a current patient again.
 *   Nothing is deleted: the date of death and who recorded it are written to
 *   that patient's timeline first, as a `patient_death_cleared` event, which
 *   is also what `down()` restores from. The `date_of_death`,
 *   `death_recorded_at` and `death_recorded_by` columns are kept, emptied.
 * - Requests to record or correct a death still waiting for a decision are
 *   cancelled (they could no longer be carried out), with a note in their
 *   thread; `down()` reopens exactly those. Decided requests stay as history.
 *
 * Bookings and follow-ups cancelled when the death was recorded are not
 * restored. Merged and deleted records are untouched.
 */
return new class extends Migration
{
    private const MARKER = '2026_11_02_165411_remove_patient_deceased_status';

    private const EVENT = 'patient_death_cleared';

    private const OPEN = ['pending', 'clarification_requested'];

    private const ACTIONS = ['patient.record_death', 'patient.correct_death'];

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('patients')
                ->leftJoin('clinics', 'clinics.id', '=', 'patients.clinic_id')
                ->where('patients.status', 'deceased')
                ->orderBy('patients.id')
                ->select(['patients.id', 'patients.clinic_id', 'patients.date_of_death', 'patients.death_recorded_at', 'patients.death_recorded_by', DB::raw('COALESCE(patients.organization_id, clinics.organization_id) as organization_id')])
                ->chunkById(500, function ($patients) {
                    foreach ($patients as $patient) {
                        DB::table('patient_timeline_events')->insert([
                            'organization_id' => $patient->organization_id,
                            'clinic_id' => $patient->clinic_id,
                            'patient_id' => $patient->id,
                            'actor_id' => null,
                            'event_type' => self::EVENT,
                            'context' => json_encode([
                                'migration' => self::MARKER,
                                'date_of_death' => $patient->date_of_death,
                                'death_recorded_at' => $patient->death_recorded_at,
                                'death_recorded_by' => $patient->death_recorded_by,
                            ]),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::table('patients')->where('id', $patient->id)->update([
                            'status' => 'active', 'date_of_death' => null, 'death_recorded_at' => null, 'death_recorded_by' => null,
                        ]);
                    }
                }, 'patients.id', 'id');

            DB::table('deletion_requests')->whereIn('action_type', self::ACTIONS)->whereIn('status', self::OPEN)->orderBy('id')
                ->chunkById(500, function ($requests) {
                    foreach ($requests as $request) {
                        $thread = json_decode((string) $request->thread, true) ?: [];
                        $thread[] = [
                            'at' => now()->toIso8601String(), 'user_id' => null, 'name' => 'OralCares', 'event' => 'cancelled',
                            'note' => 'Recording deaths was removed from OralCares, so this request was closed.',
                            'migration' => self::MARKER, 'previous_status' => $request->status, 'previous_open_key' => $request->open_key,
                        ];
                        DB::table('deletion_requests')->where('id', $request->id)->update([
                            'status' => 'cancelled', 'open_key' => null, 'cancelled_at' => now(), 'thread' => json_encode($thread), 'updated_at' => now(),
                        ]);
                    }
                });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('patient_timeline_events')->where('event_type', self::EVENT)->orderBy('id')->chunkById(500, function ($events) {
                foreach ($events as $event) {
                    $context = json_decode((string) $event->context, true) ?: [];
                    if (($context['migration'] ?? null) !== self::MARKER) {
                        continue;
                    }
                    $recordedBy = $context['death_recorded_by'] ?? null;
                    // Only a record `up()` left current goes back; a later change (a merge, a deletion) wins.
                    DB::table('patients')->where('id', $event->patient_id)->where('status', 'active')->update([
                        'status' => 'deceased',
                        'date_of_death' => $context['date_of_death'] ?? null,
                        'death_recorded_at' => $context['death_recorded_at'] ?? null,
                        'death_recorded_by' => $recordedBy !== null && DB::table('users')->where('id', $recordedBy)->exists() ? $recordedBy : null,
                    ]);
                    DB::table('patient_timeline_events')->where('id', $event->id)->delete();
                }
            });

            DB::table('deletion_requests')->whereIn('action_type', self::ACTIONS)->where('status', 'cancelled')->orderBy('id')->chunkById(500, function ($requests) {
                foreach ($requests as $request) {
                    $thread = json_decode((string) $request->thread, true) ?: [];
                    $entry = collect($thread)->firstWhere('migration', self::MARKER);
                    if ($entry === null) {
                        continue;
                    }
                    $openKeyFree = $entry['previous_open_key'] === null
                        || ! DB::table('deletion_requests')->where('open_key', $entry['previous_open_key'])->exists();
                    DB::table('deletion_requests')->where('id', $request->id)->update([
                        'status' => $entry['previous_status'],
                        'open_key' => $openKeyFree ? $entry['previous_open_key'] : null,
                        'cancelled_at' => null,
                        'thread' => json_encode(array_values(array_filter($thread, fn (array $item) => ($item['migration'] ?? null) !== self::MARKER))),
                    ]);
                }
            });
        });
    }
};
