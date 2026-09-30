<?php

namespace App\Http\Controllers\Auth;

use App\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Services\CompanyEmployeeInvitationService;
use App\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CompanyLoginController extends Controller
{
    public function create()
    {
        return Inertia::render('Auth/CompanyLogin');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $user = Auth::user();

            // 1. Ensure user has a valid Company portal type (MasterAdmin or CompanyUser)
            if ($user->user_type !== UserType::MasterAdmin && $user->user_type !== UserType::CompanyUser) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw ValidationException::withMessages([
                    'email' => 'Unauthorized action. Company portal access required.',
                ]);
            }

            // 2. Enforce active status for Company Employees
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

            if ($user->hasRole('Sales Representative') || $user->roles()->where('name', 'Sales Representative')->exists()) {
                return redirect()->intended(route('company.crm.overview'));
            }

            if (! $user->can('view executive dashboard') && $user->user_type === UserType::CompanyUser) {
                return redirect()->intended(route('company.crm.overview'));
            }

            return redirect()->intended(route('company.dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('company.login');
    }
}
