<?php

namespace App\Http\Controllers\Master;

use App\ClinicStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use Illuminate\View\View;

class MasterDashboardController extends Controller
{
    /**
     * Display the Master Admin dashboard.
     */
    public function index(): View
    {
        $totalClinics = Clinic::count();
        $activeClinics = Clinic::where('status', ClinicStatus::Active)->count();
        $totalBranches = Branch::count();
        $totalClinicMembers = ClinicMembership::distinct()->count('user_id');

        $recentClinics = Clinic::latest()
            ->limit(5)
            ->with(['primaryBranch', 'primaryOwnerMembership.user'])
            ->get();

        return view('master.dashboard', compact(
            'totalClinics',
            'activeClinics',
            'totalBranches',
            'totalClinicMembers',
            'recentClinics'
        ));
    }
}
