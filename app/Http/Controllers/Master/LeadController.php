<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreLeadRequest;
use App\Http\Requests\Master\UpdateLeadRequest;
use App\LeadActivityType;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\LogLeadActivityService;
use App\TaskStatus;
use App\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LeadController extends Controller
{
    public function __construct(
        protected LogLeadActivityService $activityService
    ) {}

    /**
     * Display a listing of the leads.
     */
    public function index(Request $request): View
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $source = $request->input('source');
        $assignee = $request->input('assignee');

        // Fetch internal users & active teams for filtering
        $users = User::whereIn('user_type', [UserType::MasterAdmin, UserType::CompanyUser])->get();
        $teams = Team::where('is_active', true)->get();

        // Server-side filtering, searching, eager loading, and pagination
        $leads = Lead::with(['assignedUser', 'assignedTeam'])
            ->withMin(['tasks as next_action_at' => function ($query) {
                $query->where('status', TaskStatus::Pending)->whereNotNull('due_at');
            }], 'due_at')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('company_name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($source, function ($query, $source) {
                $query->where('source', $source);
            })
            ->when($assignee, function ($query, $assignee) {
                if (str_starts_with($assignee, 'user_')) {
                    $query->where('assigned_user_id', substr($assignee, 5));
                } elseif (str_starts_with($assignee, 'team_')) {
                    $query->where('assigned_team_id', substr($assignee, 5));
                } elseif ($assignee === 'unassigned') {
                    $query->whereNull('assigned_user_id')->whereNull('assigned_team_id');
                }
            })
            ->latest('updated_at')
            ->paginate(10)
            ->withQueryString();

        return view('master.leads.index', compact('leads', 'users', 'teams', 'search', 'status', 'source', 'assignee'));
    }

    /**
     * Show the form for creating a new lead.
     */
    public function create(): View
    {
        $users = User::whereIn('user_type', [UserType::MasterAdmin, UserType::CompanyUser])->get();
        $teams = Team::where('is_active', true)->get();

        return view('master.leads.create', compact('users', 'teams'));
    }

    /**
     * Store a newly created lead in storage.
     */
    public function store(StoreLeadRequest $request): RedirectResponse
    {
        $lead = DB::transaction(function () use ($request) {
            $lead = Lead::create($request->validated());

            $this->activityService->execute(
                lead: $lead,
                type: LeadActivityType::LeadCreated,
                description: 'Lead profile ingested into platform CRM.',
                actor: Auth::user()
            );

            return $lead;
        });

        return redirect()->route('master.leads.show', $lead)
            ->with('success', 'Lead created successfully.');
    }

    /**
     * Display the specified lead.
     */
    public function show(Lead $lead): View
    {
        $lead->load(['assignedUser', 'assignedTeam']);

        $activities = $lead->activities()
            ->with('user')
            ->latest()
            ->paginate(25, ['*'], 'activity_page')
            ->withQueryString();

        return view('master.leads.show', compact('lead', 'activities'));
    }

    /**
     * Show the form for editing the specified lead.
     */
    public function edit(Lead $lead): View
    {
        $users = User::whereIn('user_type', [UserType::MasterAdmin, UserType::CompanyUser])->get();
        $teams = Team::where('is_active', true)->get();

        return view('master.leads.edit', compact('lead', 'users', 'teams'));
    }

    /**
     * Update the specified lead in storage.
     */
    public function update(UpdateLeadRequest $request, Lead $lead): RedirectResponse
    {
        DB::transaction(function () use ($request, $lead) {
            $oldStatus = $lead->status;
            $oldAssignedUser = $lead->assigned_user_id;
            $oldAssignedTeam = $lead->assigned_team_id;

            $lead->update($request->validated());
            $lead->load(['assignedUser', 'assignedTeam']);

            // 1. Status transition logging
            if ($oldStatus !== $lead->status) {
                $type = LeadActivityType::StatusChanged;
                $desc = "Status updated from {$oldStatus->value} to {$lead->status->value}.";

                if ($lead->status === LeadStatus::Won) {
                    $type = LeadActivityType::LeadWon;
                    $desc = 'Lead successfully WON.';
                } elseif ($lead->status === LeadStatus::Lost) {
                    $type = LeadActivityType::LeadLost;
                    $desc = 'Lead marked as LOST.';
                }

                $this->activityService->execute(
                    lead: $lead,
                    type: $type,
                    description: $desc,
                    actor: Auth::user(),
                    properties: ['old_status' => $oldStatus->value, 'new_status' => $lead->status->value]
                );
            }

            // 2. Assignment change logging
            if ($oldAssignedUser !== $lead->assigned_user_id || $oldAssignedTeam !== $lead->assigned_team_id) {
                $assigneeName = 'Unassigned';
                if ($lead->assigned_user_id) {
                    $assigneeName = 'User: '.$lead->assignedUser->name;
                } elseif ($lead->assigned_team_id) {
                    $assigneeName = 'Team: '.$lead->assignedTeam->name;
                }

                $this->activityService->execute(
                    lead: $lead,
                    type: LeadActivityType::AssignmentChanged,
                    description: "Lead assignment changed to {$assigneeName}.",
                    actor: Auth::user(),
                    properties: [
                        'old_user' => $oldAssignedUser,
                        'new_user' => $lead->assigned_user_id,
                        'old_team' => $oldAssignedTeam,
                        'new_team' => $lead->assigned_team_id,
                    ]
                );
            }
        });

        return redirect()->route('master.leads.show', $lead)
            ->with('success', 'Lead updated successfully.');
    }

    /**
     * Store a quick internal note on the lead.
     */
    public function storeNote(Request $request, Lead $lead): RedirectResponse
    {
        $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $note = trim($request->input('note'));

        if ($note === '') {
            return redirect()->back()->withErrors(['note' => 'Note content cannot be empty or whitespace only.']);
        }

        DB::transaction(function () use ($lead, $note) {
            $this->activityService->execute(
                lead: $lead,
                type: LeadActivityType::NoteAdded,
                description: $note,
                actor: Auth::user()
            );
        });

        return redirect()->route('master.leads.show', $lead)
            ->with('success', 'Note added successfully.');
    }
}
