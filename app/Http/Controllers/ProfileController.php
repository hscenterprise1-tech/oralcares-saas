<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Refuse to delete an account from its own profile.
     *
     * No account is closed by the person holding it. A company account carries
     * assignments, approvals and an audit trail; a clinic account is the
     * author of clinical records, and deleting the user row cascaded away its
     * sign-in history and the patients' doctor-assignment history, and let a
     * clinic's primary owner orphan the clinic. Closing an account is an
     * administrative act: staff are deactivated by the clinic, memberships
     * suspended, company users retired by HQ. Refused before the password is
     * checked, so nothing about the account is confirmed or denied.
     */
    public function destroy(): never
    {
        abort(403, 'Accounts are closed by an administrator, not from the profile page.');
    }
}
