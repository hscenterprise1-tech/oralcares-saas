<?php

namespace App\Http\Controllers\Clinic;

use App\BranchStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function __construct(protected ClinicContext $clinicContext) {}

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

        $branch = Branch::find($validated['branch_id']);

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
