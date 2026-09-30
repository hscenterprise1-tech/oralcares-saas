<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Services\ClinicContext;
use App\Services\OwnerMode\OwnerMode;
use App\Services\OwnerMode\OwnerModeVerifier;
use App\Services\Security\SecurityAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Entering and leaving Owner Mode.
 *
 * The clinic is never taken from the request. It is the one the session is
 * bound to, so no clinic id, URL or payload can aim this at another clinic.
 */
class OwnerModeController extends Controller
{
    public function __construct(
        protected OwnerMode $ownerMode,
        protected SecurityAudit $audit,
        protected ClinicContext $clinicContext,
    ) {}

    public function store(Request $request, OwnerModeVerifier $verifier): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! $this->ownerMode->isOwner()) {
            $this->audit->record('owner_mode.refused', $this->clinicContext->membership(), ['reason' => 'not_owner']);

            abort(403, 'Only the clinic owner can enter Owner Mode.');
        }

        if (! $verifier->verify($request->user(), $request->only('password'))) {
            $this->audit->record('owner_mode.verification_failed', $this->clinicContext->membership(), ['method' => $verifier->method()]);

            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $this->ownerMode->activate($verifier->method());

        return back()->with('success', 'Owner Mode is on.');
    }

    public function destroy(): RedirectResponse
    {
        $this->ownerMode->exit();

        return back()->with('success', 'Owner Mode is off.');
    }
}
