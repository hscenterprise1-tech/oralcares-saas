<?php

use App\ClinicRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Patient archiving is removed from OralCares (2026-10-25).
 *
 * - Every archived patient becomes a current patient again. Nothing is
 *   deleted: what the archive recorded (when, by whom, why, the previous
 *   status) is written to that patient's timeline first, as a
 *   `patient_archive_cleared` event, which is also what `down()` restores from.
 *   The original `patient_archived` timeline events and the decided
 *   `deletion_requests` rows stay as history.
 * - Archive requests still waiting for a decision are cancelled (they could
 *   no longer be carried out), with a note in their thread; `down()` reopens
 *   exactly those.
 * - The archive-only columns on `patients` (`archived_at`, `archived_by`,
 *   `archive_reason`) are dropped, and the `patient.delete` permission, which
 *   only ever allowed asking for an archive, is removed.
 *
 * Merged and deceased records are untouched.
 */
return new class extends Migration
{
    private const MARKER = '2026_10_24_191407_remove_patient_archive';

    private const EVENT = 'patient_archive_cleared';

    private const OPEN = ['pending', 'clarification_requested'];

    public function up(): void
    {
        $this->convert();

        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });

        $this->removePermission();
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
        });

        $this->restore();
        $this->restorePermission();
    }

    /**
     * The data half of `up()`, while the archive columns still exist: archived
     * patients become current (their archive noted on the timeline) and open
     * archive requests are cancelled. Running it again changes nothing.
     */
    public function convert(): void
    {
        DB::transaction(function () {
            $this->clearArchivedPatients();
            $this->cancelOpenArchiveRequests();
        });
    }

    /** The data half of `down()`, once the archive columns are back: exactly what `convert()` changed is put back. */
    public function restore(): void
    {
        DB::transaction(function () {
            DB::table('patient_timeline_events')->where('event_type', self::EVENT)->orderBy('id')->chunkById(500, function ($events) {
                foreach ($events as $event) {
                    $context = json_decode((string) $event->context, true) ?: [];
                    if (($context['migration'] ?? null) !== self::MARKER) {
                        continue;
                    }
                    $previous = $context['previous_status'] ?? 'archived';
                    $archivedBy = $context['archived_by'] ?? null;
                    DB::table('patients')->where('id', $event->patient_id)->update([
                        'archived_at' => $context['archived_at'] ?? null,
                        // The user may have been removed since; the link is nulled then, as the column does on delete.
                        'archived_by' => $archivedBy !== null && DB::table('users')->where('id', $archivedBy)->exists() ? $archivedBy : null,
                        'archive_reason' => $context['archive_reason'] ?? null,
                    ]);
                    // The status goes back only if it is still what `convert()` left; a later change (a merge) wins.
                    DB::table('patients')->where('id', $event->patient_id)->where('status', $previous === 'archived' ? 'active' : $previous)->update(['status' => $previous]);
                    DB::table('patient_timeline_events')->where('id', $event->id)->delete();
                }
            });

            DB::table('deletion_requests')->where('action_type', 'patient.archive')->where('status', 'cancelled')->orderBy('id')->chunkById(500, function ($requests) {
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

    /** Archived patients become current patients; the archive's details go to their timeline first. */
    private function clearArchivedPatients(): void
    {
        DB::table('patients')
            ->leftJoin('clinics', 'clinics.id', '=', 'patients.clinic_id')
            ->where(fn ($q) => $q->where('patients.status', 'archived')->orWhereNotNull('patients.archived_at'))
            ->orderBy('patients.id')
            ->select(['patients.id', 'patients.clinic_id', 'patients.status', 'patients.archived_at', 'patients.archived_by', 'patients.archive_reason', DB::raw('COALESCE(patients.organization_id, clinics.organization_id) as organization_id')])
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
                            'previous_status' => $patient->status,
                            'archived_at' => $patient->archived_at,
                            'archived_by' => $patient->archived_by,
                            'archive_reason' => $patient->archive_reason,
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    // The archive's details now live on the timeline. Only the archived status changes;
                    // a merged or deceased record keeps its own.
                    DB::table('patients')->where('id', $patient->id)->update([
                        'archived_at' => null, 'archived_by' => null, 'archive_reason' => null,
                        'status' => $patient->status === 'archived' ? 'active' : $patient->status,
                    ]);
                }
            }, 'patients.id', 'id');
    }

    /** Requests to archive that are still open can no longer be carried out: cancel them, noting why. */
    private function cancelOpenArchiveRequests(): void
    {
        DB::table('deletion_requests')->where('action_type', 'patient.archive')->whereIn('status', self::OPEN)->orderBy('id')
            ->chunkById(500, function ($requests) {
                foreach ($requests as $request) {
                    $thread = json_decode((string) $request->thread, true) ?: [];
                    $thread[] = [
                        'at' => now()->toIso8601String(), 'user_id' => null, 'name' => 'OralCares', 'event' => 'cancelled',
                        'note' => 'Patient archiving was removed from OralCares, so this request was closed.',
                        'migration' => self::MARKER, 'previous_status' => $request->status, 'previous_open_key' => $request->open_key,
                    ];
                    DB::table('deletion_requests')->where('id', $request->id)->update([
                        'status' => 'cancelled', 'open_key' => null, 'cancelled_at' => now(), 'thread' => json_encode($thread), 'updated_at' => now(),
                    ]);
                }
            });
    }

    private function removePermission(): void
    {
        $permissions = config('permission.table_names.permissions');
        $ids = DB::table($permissions)->where('name', 'patient.delete')->pluck('id');

        DB::table(config('permission.table_names.role_has_permissions'))->whereIn('permission_id', $ids)->delete();
        DB::table(config('permission.table_names.model_has_permissions'))->whereIn('permission_id', $ids)->delete();
        DB::table($permissions)->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Back as it was seeded: the permission, granted to the Clinic Admin roles (the owner holds every permission anyway). */
    private function restorePermission(): void
    {
        $permissions = config('permission.table_names.permissions');
        if (! DB::table($permissions)->where('name', 'patient.delete')->where('guard_name', 'web')->exists()) {
            DB::table($permissions)->insert(['name' => 'patient.delete', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        $permissionId = DB::table($permissions)->where('name', 'patient.delete')->where('guard_name', 'web')->value('id');

        foreach (DB::table(config('permission.table_names.roles'))->where('name', ClinicRole::ClinicAdmin->value)->where('guard_name', 'web')->pluck('id') as $roleId) {
            DB::table(config('permission.table_names.role_has_permissions'))->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
