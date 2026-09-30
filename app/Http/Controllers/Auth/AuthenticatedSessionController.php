<?php

namespace App\Http\Controllers\Auth;

use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\CompanyEmployeeInvitationService;
use App\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = $request->user();

        // Enforce login restrictions for Company Employees
        if ($user->user_type === UserType::CompanyUser) {
            $employee = $user->companyEmployee;
            if (! $employee || $employee->employment_status !== EmploymentStatus::Active) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => 'Your employee account is not active.',
                ]);
            }

            // Log First Login if not already logged
            $hasLoggedIn = $employee->activities()->where('event', 'First Login')->exists();
            if (! $hasLoggedIn) {
                app(CompanyEmployeeInvitationService::class)->logActivity(
                    $employee,
                    'First Login',
                    'Employee performed their first login.'
                );
            }
        }

        $request->session()->regenerate();

        if ($user->isMasterAdmin() || $user->user_type === UserType::CompanyUser) {
            return redirect()->intended(route('company.dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
