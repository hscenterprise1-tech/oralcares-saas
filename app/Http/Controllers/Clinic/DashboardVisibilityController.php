<?php

namespace App\Http\Controllers\Clinic;

use App\ClinicRole;
use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Support\Dashboard\DashboardVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner choosing which widgets a staff role's dashboard shows.
 *
 * Owner Mode only (`clinic_owner_mode`), for the session's own clinic. The
 * choice can hide a widget from a role; it can never give a role a widget its
 * permissions do not already allow (see `DashboardVisibility`).
 */
class DashboardVisibilityController extends Controller
{
    public function update(Request $request, ClinicContext $context, DashboardVisibility $visibility): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(ClinicRole::class)],
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string', Rule::in(DashboardVisibility::configurableKeys())],
        ]);

        $visibility->update($context->clinic(), ClinicRole::from($validated['role']), $validated['hidden'], $request->user());

        return back()->with('success', 'Dashboard updated for '.$validated['role'].'.');
    }
}
