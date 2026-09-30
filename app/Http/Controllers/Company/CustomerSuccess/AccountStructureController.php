<?php

namespace App\Http\Controllers\Company\CustomerSuccess;

use App\ActivityType;
use App\BranchStatus;
use App\ClinicStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Organization;
use App\Services\CustomerSuccess\AccountStructureService;
use App\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The customer's structure, managed from the account that owns it.
 *
 * Clinics and branches used to be reachable only through a Blade console under
 * `/company/master/clinics` — outside the sidebar, on the legacy stack, and with
 * no idea which account a clinic belonged to. Account 360 already showed an
 * organisation's clinics and their branches; it just could not change them, so
 * the one page that knows the customer sent you somewhere that did not.
 *
 * Every route here hangs off the organisation and authorises against it, so a
 * clinic cannot be created, renamed or retired except in the context of the
 * account it serves.
 */
class AccountStructureController extends Controller
{
    public function __construct(protected AccountStructureService $structure) {}

    public function storeClinic(Request $request, Organization $organization)
    {
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ClinicStatus::class)],
            'primary_branch_name' => ['nullable', 'string', 'max:255'],
            /*
             * Only a clinic user can own a clinic — `AssignPrimaryOwnerService`
             * throws otherwise, and a 500 is the wrong way to tell somebody they
             * picked the wrong person.
             *
             * And only a clinic user *of this account*. The service checks the
             * user's type and nothing else, so any clinic user id in the
             * platform could be posted here and would be made primary owner of
             * another tenant's clinic, with `has_all_branches_access` — a
             * cross-tenant grant reachable from a form field.
             */
            'owner_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where('user_type', UserType::ClinicUser->value)
                    ->where('organization_id', $organization->id),
            ],
        ]);

        $clinic = $this->structure->createClinic($organization, $validated);

        $this->record($request, $organization, ActivityType::RecordCreated, "Clinic \"{$clinic->name}\" added to this account.");

        return back()->with('success', "{$clinic->name} added to this account.");
    }

    public function updateClinic(Request $request, Organization $organization, Clinic $clinic)
    {
        Gate::authorize('update', $organization);
        $this->assertBelongsToAccount($clinic->organization_id, $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(ClinicStatus::class)],
        ]);

        $this->structure->updateClinic($clinic, $validated);

        $this->record($request, $organization, ActivityType::RecordUpdated, "Clinic \"{$clinic->name}\" updated (status {$clinic->fresh()->status->value}).");

        return back()->with('success', "{$clinic->name} updated.");
    }

    public function storeBranch(Request $request, Organization $organization, Clinic $clinic)
    {
        Gate::authorize('update', $organization);
        $this->assertBelongsToAccount($clinic->organization_id, $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(BranchStatus::class)],
            'is_main' => ['nullable', 'boolean'],
        ]);

        $branch = $this->structure->createBranch($clinic, $validated);

        $this->record($request, $organization, ActivityType::RecordCreated, "Branch \"{$branch->name}\" added to clinic \"{$clinic->name}\".");

        return back()->with('success', "{$branch->name} added to {$clinic->name}.");
    }

    public function updateBranch(Request $request, Organization $organization, Clinic $clinic, Branch $branch)
    {
        Gate::authorize('update', $organization);
        $this->assertBelongsToAccount($clinic->organization_id, $organization);
        abort_unless((int) $branch->clinic_id === (int) $clinic->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(BranchStatus::class)],
            'is_main' => ['nullable', 'boolean'],
        ]);

        $this->structure->updateBranch($branch, $validated);

        $this->record($request, $organization, ActivityType::RecordUpdated, "Branch \"{$branch->name}\" updated on clinic \"{$clinic->name}\".");

        return back()->with('success', "{$branch->name} updated.");
    }

    /**
     * Write the structure change to the account's timeline.
     *
     * Health updates, onboarding steps, go-live and deal creation all recorded
     * themselves; creating a clinic — which mints the tenant slug the clinic
     * portal routes by — recorded nothing at all. The Operations audit trail
     * reads `activities`, so a change that does not land here is a change the
     * platform cannot show anybody afterwards.
     */
    protected function record(Request $request, Organization $organization, ActivityType $type, string $description): void
    {
        $organization->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => $type,
            'description' => $description,
        ]);
    }

    /**
     * A clinic reached through an account has to belong to it.
     *
     * Route-model binding will happily hand over any clinic whose id appears in
     * the URL, and the three clinics carrying no organisation at all would
     * otherwise be editable from every account in the platform.
     */
    protected function assertBelongsToAccount(?int $clinicOrganizationId, Organization $organization): void
    {
        abort_unless(
            $clinicOrganizationId !== null && (int) $clinicOrganizationId === (int) $organization->id,
            404
        );
    }
}
