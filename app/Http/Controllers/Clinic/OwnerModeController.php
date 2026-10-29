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

        // An owner who does not treat patients already holds the owner's privileges;
        // for them this only confirms the password a T2 action asks for.
        return back()->with('success', $this->ownerMode->isDoctorOwner() ? 'Owner Mode is on.' : 'Password confirmed.');
    }

    /**
     * Leave Owner Mode: the doctor-owner is back in Doctor Mode, on their own home,
     * since the page they were on may be the owner's alone.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->ownerMode->exit();

        return redirect()->route('clinic.dashboard', array_filter(['clinic' => $request->route('clinic')], fn ($value) => is_string($value)))
            ->with('success', 'Owner Mode is off. You are back in Doctor Mode.');
    }
}
