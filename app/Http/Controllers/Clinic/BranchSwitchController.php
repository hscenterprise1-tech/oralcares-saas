<?php

namespace App\Http\Controllers\Clinic;

use App\BranchStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Scopes\OrganizationScope;
use App\Services\ClinicContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    /**
     * The most branches returned per page of the switcher.
     */
    public const PAGE_SIZE = 25;

    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * The branches the signed-in member may switch to, one bounded page at a
     * time, optionally narrowed by name.
     *
     * Only the membership's own accessible, active branches are ever listed.
     * The clinic comes from the session's resolved context, never from the
     * request, so no parameter can widen it to another clinic.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $membership = $this->clinicContext->membership();
        abort_unless($membership, 403);

        $search = trim((string) ($validated['search'] ?? ''));

        $page = $membership->accessibleActiveBranches()
            ->when($search !== '', fn ($query) => $query->where('branches.name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('branches.is_main')
            ->orderBy('branches.name')
            ->simplePaginate(self::PAGE_SIZE, ['branches.id', 'branches.name', 'branches.is_main'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_main' => (bool) $branch->is_main,
            ])->values(),
            'active_branch_id' => $this->clinicContext->branch()?->id,
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /**
     * Switch the active branch context for the current clinic session.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $clinic = $this->clinicContext->clinic();
        $membership = $this->clinicContext->membership();

        if (! $clinic || ! $membership) {
            return redirect()->back()->with('error', 'No active clinic session found.');
        }

        // Found across organisations: a login can belong to clinics in several,
        // and the clinic and access checks below are what bound it.
        $branch = Branch::withoutGlobalScope(OrganizationScope::class)->find($validated['branch_id']);

        if (! $branch || $branch->clinic_id !== $clinic->id || $branch->status !== BranchStatus::Active || ! $membership->hasAccessToBranch($branch)) {
            abort(403, 'You do not have access to this branch.');
        }

        $this->clinicContext->activateBranch($branch);

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($branch->id);
        }

        return redirect()->back()->with('success', "Active branch switched to {$branch->name}.");
    }
}
