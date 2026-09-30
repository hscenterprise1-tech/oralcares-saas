<?php

namespace App\Services\CustomerSuccess;

use App\BranchStatus;
use App\ClinicStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\User;
use App\Services\AssignPrimaryOwnerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shape of a customer: account → clinics → branches.
 *
 * This lived in two places that disagreed. `CustomerProvisioningEngine` creates
 * a clinic against the organisation when a deal is won, and gets it right.
 * `OnboardClinicService`, reached from the master console, created one with no
 * organisation at all — which is why three of the four clinics in the database
 * have a null `organization_id`, no branches and no memberships, while the one
 * the engine provisioned carries its account correctly.
 *
 * Every rule here is taken from those services rather than invented:
 *
 *   - a clinic belongs to an organisation, and cannot exist without one;
 *   - a clinic slug is globally unique, because the clinic portal routes by it,
 *     and a collision is resolved with a suffix rather than a failure;
 *   - a branch belongs to a clinic and carries `organization_id` alongside for
 *     scoping, which is `BranchProvisioningService`'s stated rule;
 *   - exactly one branch per clinic is the main one.
 *
 * Nothing here repairs existing rows. The three clinics with no account keep
 * their history; what changes is that nothing can create another one.
 */
class AccountStructureService
{
    public function __construct(protected AssignPrimaryOwnerService $assignPrimaryOwner) {}

    /**
     * Add a clinic to an account.
     *
     * @param  array{name: string, status?: string, primary_branch_name?: string|null, owner_id?: int|null}  $data
     */
    public function createClinic(Organization $organization, array $data): Clinic
    {
        return DB::transaction(function () use ($organization, $data) {
            $clinic = Clinic::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'slug' => $this->uniqueClinicSlug($data['name']),
                'status' => ClinicStatus::from($data['status'] ?? ClinicStatus::Active->value),
            ]);

            /*
             * A clinic with nowhere to see patients is not yet operable, so the
             * first branch is created with it when one is named — the same
             * pairing `OnboardClinicService` performs.
             */
            if (! empty($data['primary_branch_name'])) {
                $this->createBranch($clinic, [
                    'name' => $data['primary_branch_name'],
                    'is_main' => true,
                ]);
            }

            /*
             * The person who runs it.
             *
             * The retired console assigned a primary owner as part of creating a
             * clinic, and that is the membership the clinic portal reads to let
             * anybody in. A clinic with no owner is provisioned but unusable, so
             * the assignment moves here with the rest of the surface.
             */
            if (! empty($data['owner_id'])) {
                $owner = User::findOrFail($data['owner_id']);
                $this->assignPrimaryOwner->execute($clinic, $owner);
            }

            return $clinic->fresh(['branches', 'primaryOwnerMembership']);
        });
    }

    /**
     * Rename a clinic or move its status.
     *
     * The slug is deliberately not regenerated. The clinic portal routes by it,
     * so changing it on a rename would break every link and bookmark that
     * points at a live tenant.
     *
     * @param  array{name?: string, status?: string}  $data
     */
    public function updateClinic(Clinic $clinic, array $data): Clinic
    {
        $clinic->update(array_filter([
            'name' => $data['name'] ?? null,
            'status' => isset($data['status']) ? ClinicStatus::from($data['status']) : null,
        ], fn ($value) => $value !== null));

        return $clinic->fresh('branches');
    }

    /**
     * Add a branch to a clinic.
     *
     * @param  array{name: string, status?: string, is_main?: bool}  $data
     */
    public function createBranch(Clinic $clinic, array $data): Branch
    {
        return DB::transaction(function () use ($clinic, $data) {
            $isMain = (bool) ($data['is_main'] ?? false);

            /* A clinic's first branch is its main one whether or not anybody said so. */
            if (! $isMain && $clinic->branches()->count() === 0) {
                $isMain = true;
            }

            if ($isMain) {
                $this->demoteOtherMainBranches($clinic);
            }

            $branch = Branch::create([
                'clinic_id' => $clinic->id,
                /* Carried for scoping; the clinic is the owner. */
                'organization_id' => $clinic->organization_id,
                'name' => $data['name'],
                'slug' => $this->uniqueBranchSlug($clinic, $data['name']),
                'is_main' => $isMain,
                'status' => BranchStatus::from($data['status'] ?? BranchStatus::Active->value),
            ]);

            return $branch;
        });

        app(AccountSnapshotService::class)->refreshForBranch($branch);

        return $branch;
    }

    /**
     * @param  array{name?: string, status?: string, is_main?: bool}  $data
     */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        $freshBranch = DB::transaction(function () use ($branch, $data) {
            if (! empty($data['is_main']) && $branch->clinic) {
                $this->demoteOtherMainBranches($branch->clinic, $branch->id);
            }

            $branch->update(array_filter([
                'name' => $data['name'] ?? null,
                'status' => isset($data['status']) ? BranchStatus::from($data['status']) : null,
                'is_main' => array_key_exists('is_main', $data) ? (bool) $data['is_main'] : null,
            ], fn ($value) => $value !== null));

            return $branch->fresh();
        });

        app(AccountSnapshotService::class)->refreshForBranch($freshBranch);

        return $freshBranch;
    }

    /**
     * Only one branch per clinic is the main one.
     */
    protected function demoteOtherMainBranches(Clinic $clinic, ?int $exceptBranchId = null): void
    {
        $clinic->branches()
            ->where('is_main', true)
            ->when($exceptBranchId, fn ($query) => $query->whereKeyNot($exceptBranchId))
            ->update(['is_main' => false]);
    }

    /**
     * A clinic slug no other clinic holds.
     *
     * The rule is `CustomerProvisioningEngine::uniqueClinicSlug`: the portal
     * routes by this in domain mode, so a collision is resolved with a suffix
     * rather than by refusing to create the clinic.
     */
    protected function uniqueClinicSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;

        for ($attempt = 0; Clinic::where('slug', $slug)->exists(); $attempt++) {
            $slug = $base.'-'.Str::lower(Str::random(6));

            if ($attempt > 10) {
                return $base.'-'.Str::uuid();
            }
        }

        return $slug;
    }

    /**
     * Branch slugs only have to be unique within their clinic.
     */
    protected function uniqueBranchSlug(Clinic $clinic, string $name): string
    {
        $base = Str::slug($name) ?: 'branch';
        $slug = $base;

        for ($attempt = 0; $clinic->branches()->where('slug', $slug)->exists(); $attempt++) {
            $slug = $base.'-'.Str::lower(Str::random(6));

            if ($attempt > 10) {
                return $base.'-'.Str::uuid();
            }
        }

        return $slug;
    }
}
