<?php

namespace App\Services\Provisioning;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Turn an empty tenant into a clinic somebody could actually open tomorrow.
 *
 * Provisioning created an organisation, a clinic, a branch and a login, and
 * stopped. Every operational table stayed empty — no opening hours, no
 * departments, no rooms, no queue, no treatments — across all four existing
 * customers. A trial handed over in that state cannot be evaluated, because
 * nothing in the product will run until the customer has filled all of it in.
 *
 * So this creates the operational floor. Everything it writes is starter data
 * the customer is expected to edit, and everything is idempotent: running it
 * twice on the same clinic adds nothing, which is what makes the retry path
 * and the trial-to-paid conversion safe.
 *
 * It writes only structure. It never creates staff accounts — see
 * {@see AdminHandoverService} for why that boundary exists.
 */
class ClinicOperationsProvisioner
{
    public function __construct(protected StarterCatalogue $catalogue) {}

    /**
     * @return array<string, int> what was created, per entity, for the audit
     */
    public function provision(Organization $organization, Clinic $clinic, Branch $branch): array
    {
        return DB::transaction(function () use ($organization, $clinic, $branch) {
            return [
                'operating_hours' => $this->operatingHours($clinic, $branch),
                'departments' => $this->departments($organization, $clinic),
                'rooms' => $this->rooms($organization, $branch),
                'queues' => $this->queues($clinic, $branch),
                'branch_settings' => $this->branchSettings($branch),
                'specialties' => $this->specialties($organization),
                'treatments' => $this->treatments($organization),
            ];
        });
    }

    /**
     * Opening hours, one row per weekday.
     *
     * Without these the appointment grid has no bookable span at all, so this
     * is the single most load-bearing thing in the method.
     */
    protected function operatingHours(Clinic $clinic, Branch $branch): int
    {
        $created = 0;

        foreach ($this->catalogue->operatingHours() as $row) {
            $exists = DB::table('clinic_operating_hours')
                ->where('clinic_id', $clinic->id)
                ->where('branch_id', $branch->id)
                ->where('day_of_week', $row['day_of_week'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('clinic_operating_hours')->insert($row + [
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }

    protected function departments(Organization $organization, Clinic $clinic): int
    {
        $created = 0;

        foreach ($this->catalogue->departments($organization->type) as $row) {
            $exists = DB::table('clinic_departments')
                ->where('clinic_id', $clinic->id)
                ->where('key', $row['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('clinic_departments')->insert($row + [
                'clinic_id' => $clinic->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }

    protected function rooms(Organization $organization, Branch $branch): int
    {
        $created = 0;

        foreach ($this->catalogue->rooms($organization->type) as $row) {
            $exists = DB::table('clinic_rooms')
                ->where('branch_id', $branch->id)
                ->where('key', $row['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('clinic_rooms')->insert($row + [
                'branch_id' => $branch->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * One walk-in queue.
     *
     * `doctor_id` is left null on purpose: assigning the queue to the initial
     * admin would imply that person is a practising clinician, which CRM has no
     * way of knowing.
     */
    protected function queues(Clinic $clinic, Branch $branch): int
    {
        $exists = DB::table('clinic_queues')
            ->where('clinic_id', $clinic->id)
            ->where('branch_id', $branch->id)
            ->exists();

        if ($exists) {
            return 0;
        }

        DB::table('clinic_queues')->insert([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'name' => 'Walk-in Queue',
            'doctor_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 1;
    }

    /**
     * Branch defaults the clinic app reads before it will schedule anything.
     */
    protected function branchSettings(Branch $branch): int
    {
        $settings = [
            ['group' => 'scheduling', 'key' => 'slot_minutes', 'value' => '30'],
            ['group' => 'scheduling', 'key' => 'buffer_minutes', 'value' => '0'],
            ['group' => 'general', 'key' => 'timezone', 'value' => 'Asia/Kolkata'],
            ['group' => 'general', 'key' => 'currency', 'value' => 'INR'],
        ];

        $created = 0;

        foreach ($settings as $row) {
            $exists = DB::table('branch_settings')
                ->where('branch_id', $branch->id)
                ->where('group', $row['group'])
                ->where('key', $row['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('branch_settings')->insert([
                'branch_id' => $branch->id,
                'group' => $row['group'],
                'key' => $row['key'],
                /*
                 * `value` is a JSON column and these are raw inserts, so there
                 * is no model cast to encode for us.
                 */
                'value' => json_encode($row['value']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }

    protected function specialties(Organization $organization): int
    {
        $created = 0;

        foreach ($this->catalogue->specialties($organization->type) as $name) {
            $exists = DB::table('clinical_specialties')
                ->where('organization_id', $organization->id)
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('clinical_specialties')->insert([
                'organization_id' => $organization->id,
                'name' => $name,
                'description' => StarterCatalogue::MARKER,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }

    protected function treatments(Organization $organization): int
    {
        $created = 0;

        foreach ($this->catalogue->treatments($organization->type) as $row) {
            $exists = DB::table('treatment_catalogs')
                ->where('organization_id', $organization->id)
                ->where('name', $row['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('treatment_catalogs')->insert([
                'organization_id' => $organization->id,
                'name' => $row['name'],
                'category' => $row['category'],
                /*
                 * Marked, not priced. A fee schedule the clinic did not set is
                 * worse than no fee schedule.
                 */
                'description' => StarterCatalogue::MARKER,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return $created;
    }
}
