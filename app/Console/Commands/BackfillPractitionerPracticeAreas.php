<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsPerClinic;
use App\Models\Clinic;
use App\Models\ClinicalSpecialty;
use App\Models\Practitioner;
use App\Services\Security\SecurityAudit;
use App\Support\Specialty\PracticeAreas;
use App\Support\Specialty\SpecialtyEntitlements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives each practitioner profile recorded before practice areas existed
 * keyed specialty assignments, from what `Practitioner::practiceAreaKeys()`
 * already reads off it (specialty names, then qualification
 * specializations, then the broad title).
 *
 * - The organisation's catalogue entry for each area is keyed (an existing
 *   entry of the same name is adopted; otherwise one is created).
 * - A profile with no primary specialty gets its first area as primary; the
 *   rest are added as additional specialties. Existing assignments are
 *   never removed or replaced.
 * - Areas of a module the clinic's plan does not sell are skipped.
 *
 * Idempotent; a profile that already has a keyed assignment is left alone.
 * `--dry-run` does everything in a transaction rolled back. The table lists
 * profiles nothing could be read from: the clinic owner has to set them.
 */
class BackfillPractitionerPracticeAreas extends Command
{
    use RunsPerClinic;

    protected $signature = 'practitioners:backfill-practice-areas
        {--clinic=* : Clinic id(s)}
        {--all-clinics : Every active clinic}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Key practitioners\' specialty assignments to registry practice areas, from what their profiles already record';

    public function handle(PracticeAreas $areas, SpecialtyEntitlements $entitlements, SecurityAudit $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $unresolved = [];

        $this->forEachSelectedClinic(function (Clinic $clinic) use ($areas, $entitlements, $audit, $dryRun, &$rows, &$unresolved) {
            DB::beginTransaction();
            try {
                $stats = ['clinic' => $clinic->id, 'profiles' => 0, 'already_keyed' => 0, 'keyed' => 0, 'areas_assigned' => 0, 'unresolved' => 0];

                $profiles = Practitioner::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
                    ->with(['employee:id,name', 'primarySpecialty' => fn ($q) => $q->withoutGlobalScopes(), 'specialties' => fn ($q) => $q->withoutGlobalScopes(), 'qualificationRecords' => fn ($q) => $q->withoutGlobalScopes()])
                    ->get();

                foreach ($profiles as $profile) {
                    $stats['profiles']++;
                    $assigned = collect([$profile->primarySpecialty])->merge($profile->specialties)->filter();
                    if ($assigned->contains(fn (ClinicalSpecialty $specialty) => $areas->get($specialty->practice_area_key) !== null)) {
                        $stats['already_keyed']++;

                        continue;
                    }

                    // Read the way the resolver reads it, even for an inactive profile.
                    $active = $profile->is_active;
                    $profile->is_active = true;
                    $keys = collect($profile->practiceAreaKeys())->filter(fn (string $key) => $entitlements->allows($clinic, $areas->get($key)['specialty']))->values();
                    $profile->is_active = $active;

                    if ($keys->isEmpty()) {
                        $stats['unresolved']++;
                        $unresolved[] = [$clinic->id, $profile->id, $profile->employee?->name ?? '—', $profile->specialty ?? $profile->profession ?? '—'];

                        continue;
                    }

                    $entries = $keys->map(fn (string $key) => $this->entryFor($clinic->organization_id, $key, $areas));
                    $primary = $profile->primary_specialty_id ? null : $entries->first();
                    if ($primary) {
                        $profile->forceFill(['primary_specialty_id' => $primary->id, 'specialty' => $profile->specialty ?: $primary->name])->saveQuietly();
                    }
                    $additional = $entries->reject(fn (ClinicalSpecialty $entry) => $entry->id === ($primary?->id ?? $profile->primary_specialty_id))->pluck('id');
                    $profile->specialties()->syncWithoutDetaching($additional->all());

                    $stats['keyed']++;
                    $stats['areas_assigned'] += $keys->count();
                    $audit->record('practitioner.practice_areas_backfilled', $profile, ['practice_areas' => $keys->all()]);
                }

                $rows[] = $stats;
                $dryRun ? DB::rollBack() : DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        });

        $this->table(['Clinic', 'Profiles', 'Already keyed', 'Keyed now', 'Areas assigned', 'Unresolved'], array_map('array_values', $rows));
        if ($unresolved !== []) {
            $this->warn('Needs the clinic owner (nothing on the profile names a practice area):');
            $this->table(['Clinic', 'Profile', 'Name', 'Recorded as'], $unresolved);
        }
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Done.');

        return self::SUCCESS;
    }

    /** The organisation's catalogue entry for an area: keyed, adopted by name, or created. */
    private function entryFor(int $organizationId, string $area, PracticeAreas $areas): ClinicalSpecialty
    {
        $query = fn () => ClinicalSpecialty::query()->withoutGlobalScopes()->where('organization_id', $organizationId);

        $keyed = $query()->where('practice_area_key', $area)->first();
        if ($keyed) {
            return $keyed;
        }

        $named = $query()->whereNull('practice_area_key')->get()->first(fn (ClinicalSpecialty $specialty) => $areas->match($specialty->name) === $area);
        if ($named) {
            $named->forceFill(['practice_area_key' => $area])->save();

            return $named;
        }

        return ClinicalSpecialty::query()->withoutGlobalScopes()->forceCreate([
            'organization_id' => $organizationId,
            'name' => $areas->get($area)['label'],
            'practice_area_key' => $area,
            'is_active' => true,
        ]);
    }
}
