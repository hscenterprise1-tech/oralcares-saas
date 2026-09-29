<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        /*
         * Company and HQ accounts are not closed by the person holding them.
         *
         * A company account carries assignments, approvals and an audit trail
         * that other people's records point at, so removing one is an
         * administrative act rather than a setting. Refused before the password
         * is even checked, so nothing about the account is confirmed or denied
         * along the way.
         *
         * The route and the form are shared with the clinic portal and are
         * unchanged for it; only who may use them differs.
         */
        $user = $request->user();

        abort_if(
            $user->isMasterAdmin() || $user->user_type === UserType::CompanyUser,
            403,
            'A company account cannot be deleted from the profile page.'
        );

        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
