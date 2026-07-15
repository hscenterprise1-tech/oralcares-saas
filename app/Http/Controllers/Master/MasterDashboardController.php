<?php

namespace App\Http\Controllers\Master;

use App\ClinicStatus;
use App\Http\Controllers\Controller;
use App\LeadStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Lead;
use App\Models\Task;
use App\TaskStatus;
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

        $newLeads = Lead::where('status', LeadStatus::New)->count();
        $activeLeads = Lead::whereIn('status', [
            LeadStatus::Contacted,
            LeadStatus::DemoScheduled,
            LeadStatus::DemoCompleted,
            LeadStatus::ProposalSent,
            LeadStatus::Negotiation,
        ])->count();

        $overdueActions = Task::where('status', TaskStatus::Pending)
            ->where('due_at', '<', now())
            ->count();

        $unassignedLeads = Lead::whereNull('assigned_user_id')
            ->whereNull('assigned_team_id')
            ->whereNotIn('status', [LeadStatus::Won, LeadStatus::Lost])
            ->count();

        $recentClinics = Clinic::latest()
            ->limit(5)
            ->with(['primaryBranch', 'primaryOwnerMembership.user'])
            ->get();

        $recentLeads = Lead::latest()
            ->limit(5)
            ->with(['assignedUser', 'assignedTeam'])
            ->get();

        return view('master.dashboard', compact(
            'totalClinics',
            'activeClinics',
            'totalBranches',
            'totalClinicMembers',
            'newLeads',
            'activeLeads',
            'overdueActions',
            'unassignedLeads',
            'recentClinics',
            'recentLeads'
        ));
    }
}
