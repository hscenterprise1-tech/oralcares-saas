<?php

namespace App\Http\Controllers\Company\Crm;

use App\Http\Controllers\Controller;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\CrmMetricsService;
use App\Support\CrmScope;
use App\TaskStatus;
use App\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Sales Teams administration.
 *
 * Viewing is open to any CRM user — a rep needs to know who is on their team —
 * but every mutation is gated on `crm.teams.manage`, which only management
 * personas hold. Workload and performance are additionally gated on
 * `crm.overview.team`, because they expose named individuals' numbers.
 */
class TeamController extends Controller
{
    public function __construct(protected CrmMetricsService $metrics) {}

    public function index(Request $request)
    {
        Gate::authorize('crm.teams.view');

        $user = $request->user();
        $orgId = $user->resolveOrganizationId();
        $canManage = Gate::allows('crm.teams.manage');
        $canViewPerformance = Gate::allows('crm.overview.team');

        $teams = Team::query()
            ->where('organization_id', $orgId)
            ->with([
                'manager:id,name,email',
                'users' => fn ($q) => $q->select('users.id', 'name', 'email'),
            ])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $workload = $canViewPerformance ? $this->workloadFor($teams) : [];

        return Inertia::render('Company/Crm/Teams/Index', [
            'teams' => $teams->map(fn (Team $team) => $this->transformTeam($team, $workload[$team->id] ?? null)),
            'assignableUsers' => $this->assignableUsers($orgId),
            'can' => [
                'manage' => $canManage,
                'view_performance' => $canViewPerformance,
            ],
        ]);
    }

    /**
     * A single team with its member roster and per-member contribution.
     */
    public function show(Request $request, Team $team)
    {
        Gate::authorize('crm.teams.view');
        $this->assertSameOrganization($request, $team);

        $team->load(['manager:id,name,email', 'users:id,name,email']);

        $canViewPerformance = Gate::allows('crm.overview.team');
        $memberIds = $team->users->pluck('id')->all();

        return Inertia::render('Company/Crm/Teams/Show', [
            'team' => $this->transformTeam($team, $this->workloadFor(collect([$team]))[$team->id] ?? null),
            'assignableUsers' => $this->assignableUsers($request->user()->resolveOrganizationId()),
            'memberPerformance' => $canViewPerformance
                ? collect($this->metrics->repPerformance($request->user()))
                    ->whereIn('user_id', $memberIds)
                    ->values()
                    ->all()
                : null,
            'can' => [
                'manage' => Gate::allows('crm.teams.manage'),
                'view_performance' => $canViewPerformance,
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('crm.teams.manage');

        $validated = $this->validateTeam($request);
        $orgId = $request->user()->resolveOrganizationId();

        $team = Team::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'key' => str($validated['name'])->slug().'-'.str()->random(4),
            'description' => $validated['description'] ?? null,
            'manager_id' => $this->resolveManagerId($validated['manager_id'] ?? null, $orgId),
            'is_active' => $validated['is_active'] ?? true,
            'assignment_strategy' => $validated['assignment_strategy'],
            'territory_map' => $this->sanitizeTerritoryMap($validated['territory_map'] ?? [], $orgId),
        ]);

        $this->syncMembers($team, $validated['member_ids'] ?? []);

        return back()->with('success', "Team \"{$team->name}\" created.");
    }

    public function update(Request $request, Team $team)
    {
        Gate::authorize('crm.teams.manage');
        $this->assertSameOrganization($request, $team);

        $validated = $this->validateTeam($request);
        $orgId = $request->user()->resolveOrganizationId();

        $team->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'manager_id' => $this->resolveManagerId($validated['manager_id'] ?? null, $orgId),
            'is_active' => $validated['is_active'] ?? true,
            'assignment_strategy' => $validated['assignment_strategy'],
            'territory_map' => $this->sanitizeTerritoryMap($validated['territory_map'] ?? [], $orgId),
        ]);

        if (array_key_exists('member_ids', $validated)) {
            $this->syncMembers($team, $validated['member_ids'] ?? []);
        }

        return back()->with('success', "Team \"{$team->name}\" updated.");
    }

    /**
     * Add one member without disturbing the rest of the roster.
     */
    public function addMember(Request $request, Team $team)
    {
        Gate::authorize('crm.teams.manage');
        $this->assertSameOrganization($request, $team);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($user->user_type === UserType::ClinicUser) {
            throw ValidationException::withMessages([
                'user_id' => 'Clinic users cannot be added to a sales team.',
            ]);
        }

        if ($user->resolveOrganizationId() !== (int) $team->organization_id) {
            throw ValidationException::withMessages([
                'user_id' => 'That person is not in your organisation.',
            ]);
        }

        $team->users()->syncWithoutDetaching([$user->id]);

        return back()->with('success', "{$user->name} added to {$team->name}.");
    }

    public function removeMember(Request $request, Team $team, User $user)
    {
        Gate::authorize('crm.teams.manage');
        $this->assertSameOrganization($request, $team);

        $team->users()->detach($user->id);

        // A manager who is no longer a member should not remain the accountable
        // owner — that would leave the team pointing at someone outside it.
        if ((int) $team->manager_id === (int) $user->id) {
            $team->update(['manager_id' => null]);
        }

        return back()->with('success', "{$user->name} removed from {$team->name}.");
    }

    /**
     * Flip a team between active and inactive.
     *
     * Deactivating is the reversible alternative to deleting: assignment stops
     * routing to it while the roster and history survive.
     */
    public function toggleActive(Request $request, Team $team)
    {
        Gate::authorize('crm.teams.manage');
        $this->assertSameOrganization($request, $team);

        $team->update(['is_active' => ! $team->is_active]);

        return back()->with('success', "Team \"{$team->name}\" is now ".($team->is_active ? 'active' : 'inactive').'.');
    }

    public function destroy(Request $request, Team $team)
    {
        Gate::authorize('crm.teams.manage');
        $this->assertSameOrganization($request, $team);

        $team->delete();

        return back()->with('success', 'Team removed.');
    }

    /**
     * Per-team open pipeline, won deals and overdue work, in three grouped
     * queries rather than a query per team.
     *
     * @param  Collection<int, Team>  $teams
     * @return array<int, array<string, mixed>>
     */
    protected function workloadFor($teams): array
    {
        $memberMap = [];
        foreach ($teams as $team) {
            foreach ($team->users as $member) {
                $memberMap[$team->id][] = (int) $member->id;
            }
        }

        $allMemberIds = collect($memberMap)->flatten()->unique()->values()->all();

        if (empty($allMemberIds)) {
            return $teams->mapWithKeys(fn (Team $team) => [$team->id => $this->emptyWorkload()])->all();
        }

        $leadRows = Lead::query()
            ->whereIn('assigned_user_id', $allMemberIds)
            ->selectRaw('assigned_user_id, status, count(*) as lead_count, coalesce(sum(expected_mrr), 0) as mrr, coalesce(sum(expected_arr), 0) as arr')
            ->groupBy('assigned_user_id', 'status')
            ->get()
            ->groupBy('assigned_user_id');

        $overdueRows = Task::query()
            ->whereIn('assigned_user_id', $allMemberIds)
            ->where('status', TaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay())
            ->selectRaw('assigned_user_id, count(*) as overdue_count')
            ->groupBy('assigned_user_id')
            ->pluck('overdue_count', 'assigned_user_id');

        $result = [];
        foreach ($teams as $team) {
            $ids = $memberMap[$team->id] ?? [];
            if (empty($ids)) {
                $result[$team->id] = $this->emptyWorkload();

                continue;
            }

            $rows = collect($ids)->flatMap(fn (int $id) => $leadRows->get($id, collect()));
            $terminal = [LeadStatus::Won->value, LeadStatus::Lost->value];

            $openRows = $rows->filter(fn ($row) => ! in_array($this->statusValue($row->status), $terminal, true));
            $openValue = $openRows->reduce(
                fn (float $carry, $row) => $carry + ((float) $row->arr > 0 ? (float) $row->arr : (float) $row->mrr * 12),
                0.0
            );

            $result[$team->id] = [
                'member_count' => count($ids),
                'open_leads' => (int) $openRows->sum('lead_count'),
                'open_pipeline_value' => round($openValue, 2),
                'won' => (int) $rows->filter(fn ($r) => $this->statusValue($r->status) === LeadStatus::Won->value)->sum('lead_count'),
                'lost' => (int) $rows->filter(fn ($r) => $this->statusValue($r->status) === LeadStatus::Lost->value)->sum('lead_count'),
                'overdue_followups' => (int) collect($ids)->sum(fn (int $id) => (int) ($overdueRows[$id] ?? 0)),
                'average_open_leads_per_member' => count($ids) > 0
                    ? round($openRows->sum('lead_count') / count($ids), 1)
                    : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyWorkload(): array
    {
        return [
            'member_count' => 0,
            'open_leads' => 0,
            'open_pipeline_value' => 0.0,
            'won' => 0,
            'lost' => 0,
            'overdue_followups' => 0,
            'average_open_leads_per_member' => null,
        ];
    }

    protected function statusValue(mixed $status): string
    {
        return is_object($status) && isset($status->value) ? $status->value : (string) $status;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateTeam(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
            'assignment_strategy' => ['required', Rule::in([Team::STRATEGY_ROUND_ROBIN, Team::STRATEGY_TERRITORY])],
            'territory_map' => ['nullable', 'array'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
        ]);
    }

    /**
     * A manager must be a company-portal user in the same organization —
     * otherwise the team is accountable to someone who cannot see it.
     */
    protected function resolveManagerId(?int $managerId, ?int $orgId): ?int
    {
        if (! $managerId) {
            return null;
        }

        $isEligible = User::query()
            ->where('id', $managerId)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->exists();

        return $isEligible ? $managerId : null;
    }

    /**
     * Drop territory-map entries that don't point at a real, assignable user
     * in this organization, rather than hard-failing the whole request.
     *
     * @param  array<string, mixed>  $territoryMap
     * @return array<string, int>|null
     */
    protected function sanitizeTerritoryMap(array $territoryMap, ?int $orgId): ?array
    {
        if (empty($territoryMap)) {
            return null;
        }

        $validUserIds = User::query()
            ->where('organization_id', $orgId)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->pluck('id')
            ->all();

        $clean = [];
        foreach ($territoryMap as $state => $userId) {
            $state = trim((string) $state);
            if ($state !== '' && in_array((int) $userId, $validUserIds, true)) {
                $clean[$state] = (int) $userId;
            }
        }

        return $clean ?: null;
    }

    /**
     * @param  list<int>  $memberIds
     */
    protected function syncMembers(Team $team, array $memberIds): void
    {
        $validMemberIds = User::query()
            ->whereIn('id', $memberIds)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->where('organization_id', $team->organization_id)
            ->pluck('id')
            ->all();

        $team->users()->sync($validMemberIds);
    }

    /**
     * @return Collection<int, User>
     */
    protected function assignableUsers(?int $orgId)
    {
        return User::query()
            ->where('organization_id', $orgId)
            ->where('user_type', '!=', UserType::ClinicUser)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();
    }

    /**
     * Route-model binding resolves any team by id, so tenant ownership has to
     * be asserted explicitly or one organization could administer another's.
     */
    protected function assertSameOrganization(Request $request, Team $team): void
    {
        if (CrmScope::level($request->user()) === CrmScope::LEVEL_ALL) {
            return;
        }

        $orgId = $request->user()->resolveOrganizationId();

        abort_unless($orgId && (int) $team->organization_id === (int) $orgId, 403);
    }

    /**
     * @param  array<string, mixed>|null  $workload
     * @return array<string, mixed>
     */
    protected function transformTeam(Team $team, ?array $workload = null): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'description' => $team->description,
            'is_active' => $team->is_active,
            'assignment_strategy' => $team->assignment_strategy,
            'territory_map' => $team->territory_map ?? [],
            'manager' => $team->manager ? [
                'id' => $team->manager->id,
                'name' => $team->manager->name,
                'email' => $team->manager->email,
            ] : null,
            'member_count' => $team->users_count ?? $team->users->count(),
            'members' => $team->users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
            'workload' => $workload,
        ];
    }
}
