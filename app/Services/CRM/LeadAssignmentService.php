<?php

namespace App\Services\CRM;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nobody's lead goes nowhere.
 *
 * An unowned lead is not a reporting problem to be surfaced on a dashboard; it
 * is a lead nobody is working. Auto-assignment existed before this but ran in
 * exactly one place — inside `LeadWorkspaceService::createLead()` — so a lead
 * arriving through an import, the marketing hand-off, a factory or the API was
 * never assigned at all. It is hooked to the model's own `created` event now,
 * which is the one path no caller can avoid.
 *
 * Four strategies, tried in order, each falling through to the next:
 *
 *   1. Territory   an active team with a territory strategy that claims the
 *                  lead's state. A rep who already covers a region should get
 *                  the region's leads.
 *   2. Least-loaded  the sales rep in this organisation with the fewest open
 *                  deals. Round-robin over a uniform queue is fair only if
 *                  everybody started level; load is what fairness actually
 *                  means once they have not.
 *   3. Team rota   an active round-robin team's cursor, for organisations that
 *                  have configured teams but whose members carry no CRM role.
 *   4. Fallback    the configured owner, a manager, or the master admin. Never
 *                  nothing.
 *
 * Every path records why it chose, so the audit trail distinguishes "no rules
 * exist yet" from "rules exist and none matched" — they need different fixes.
 */
class LeadAssignmentService
{
    /**
     * Roles whose holders are expected to work deals.
     *
     * A permission check would be truer but costs a query per candidate; these
     * are the four roles the CRM seeder grants CRM permissions to, and they are
     * what the organisation's own role list calls a salesperson.
     *
     * @var list<string>
     */
    public const SALES_ROLES = [
        'Sales Representative',
        'Sales Executive',
        'Sales Manager',
        'CRM Manager',
    ];

    public function __construct(protected LeadWorkspaceService $workspace) {}

    /**
     * Give this lead an owner, if it does not have one.
     *
     * Returns the user it landed on, or null when the lead already had an owner
     * or the system has nobody at all to give it to — which means an
     * organisation with no users and no master admin.
     */
    public function autoAssign(Lead $lead): ?User
    {
        if ($lead->assigned_user_id !== null || $lead->assigned_team_id !== null) {
            return null;
        }

        if (! $lead->organization_id) {
            return null;
        }

        return $this->byTerritory($lead)
            ?? $this->byLoad($lead)
            ?? $this->byTeamRota($lead)
            ?? $this->toFallbackOwner($lead);
    }

    /**
     * A rep who already covers this state.
     *
     * Skipped entirely when the lead has no state on file, which is the honest
     * answer rather than assigning on a territory nobody said it was in.
     */
    protected function byTerritory(Lead $lead): ?User
    {
        if (! $lead->state) {
            return null;
        }

        $teams = Team::query()
            ->where('organization_id', $lead->organization_id)
            ->where('is_active', true)
            ->where('assignment_strategy', Team::STRATEGY_TERRITORY)
            ->get();

        foreach ($teams as $team) {
            if ($user = $team->userForState($lead->state)) {
                return $this->give($lead, $user, 'territory_match');
            }
        }

        return null;
    }

    /**
     * The rep carrying the fewest open deals, and the longest wait as the
     * tiebreak.
     *
     * Both figures come from one grouped query rather than a count per
     * candidate, so an organisation with forty reps costs the same as one with
     * four. `last_assigned_at` is the newest lead each rep received: among reps
     * on equal load, the one who has been waiting longest goes next, which is
     * the round-robin rotation without a cursor column to drift out of sync.
     */
    protected function byLoad(Lead $lead): ?User
    {
        $candidates = $this->salesReps($lead);

        if ($candidates->isEmpty()) {
            return null;
        }

        $stats = DB::table('leads')
            ->selectRaw('assigned_user_id, COUNT(*) as open_deals, MAX(created_at) as last_assigned_at')
            ->whereIn('assigned_user_id', $candidates->keys())
            ->whereIn('status', DealsQueryService::OPEN_STATUSES)
            ->groupBy('assigned_user_id')
            ->get()
            ->keyBy('assigned_user_id');

        /*
         * Three keys, compared in order, with an explicit comparator.
         *
         * `sortBy()` with an array of closures reads like a multi-key sort and
         * is not one — it silently ranked on the first key alone, so every lead
         * in a batch went to the same rep however loaded they became. PHP's
         * spaceship operator compares arrays element by element, which is
         * exactly the semantics wanted, stated once.
         */
        $winner = $candidates->keys()
            ->map(fn ($id) => [
                /* Fewest open deals first. A rep with none sorts to the front. */
                (int) ($stats[$id]->open_deals ?? 0),
                /*
                 * Then whoever has waited longest. A rep who has never been
                 * given anything sorts ahead of everyone — the empty string
                 * precedes any timestamp.
                 */
                (string) ($stats[$id]->last_assigned_at ?? ''),
                /* Then by id, so the choice is reproducible. */
                $id,
            ])
            ->sort(fn (array $a, array $b) => $a <=> $b)
            ->first();

        return $winner === null ? null : $this->give($lead, $candidates[$winner[2]], 'least_loaded');
    }

    /**
     * People in this organisation who work deals.
     *
     * Clinic users are excluded because they belong to a customer, not to the
     * company selling to them. Master admins are excluded here and reachable
     * through the fallback instead: an admin is where a lead goes when there is
     * no rep, not somebody to put in the rota alongside them.
     *
     * The role pivot is queried directly rather than through `whereHas('roles')`
     * because Spatie runs in teams mode here: the relation is scoped to whatever
     * team context the current request happens to carry, and a lead created from
     * a queued job, a console command or an import carries none. The relation
     * then matched nobody, the pool came back empty, and every lead in the
     * system fell through to the master admin — silently, and exactly in the
     * situations where nobody was watching. Whether somebody is a sales rep does
     * not depend on which branch the request was scoped to.
     *
     * @return Collection<int, User>
     */
    protected function salesReps(Lead $lead): Collection
    {
        return User::query()
            ->where('organization_id', $lead->organization_id)
            ->where('user_type', UserType::CompanyUser->value)
            ->whereExists(function ($query) {
                /* Spatie's tables are renamed in this application; ask it. */
                $pivot = config('permission.table_names.model_has_roles');
                $roles = config('permission.table_names.roles');
                $morphKey = config('permission.column_names.model_morph_key', 'model_id');

                $query->selectRaw('1')
                    ->from($pivot)
                    ->join($roles, "{$roles}.id", '=', "{$pivot}.role_id")
                    ->whereColumn("{$pivot}.{$morphKey}", 'users.id')
                    ->where("{$pivot}.model_type", (new User)->getMorphClass())
                    ->whereIn("{$roles}.name", self::SALES_ROLES);
            })
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'organization_id', 'user_type'])
            ->keyBy('id');
    }

    /** An organisation with teams but no CRM roles still has a rota. */
    protected function byTeamRota(Lead $lead): ?User
    {
        $team = Team::query()
            ->where('organization_id', $lead->organization_id)
            ->where('is_active', true)
            ->where('assignment_strategy', Team::STRATEGY_ROUND_ROBIN)
            ->first();

        $user = $team?->nextRoundRobinUser();

        return $user ? $this->give($lead, $user, 'round_robin') : null;
    }

    /**
     * Nobody matched, so the lead goes to a named owner rather than nowhere.
     *
     * A sales manager in the organisation is preferred over a platform admin:
     * they are the person who will actually redistribute it.
     */
    protected function toFallbackOwner(Lead $lead): ?User
    {
        $configuredId = config('crm.fallback_lead_owner_id');

        $owner = ($configuredId ? User::find($configuredId) : null)
            ?? User::query()
                ->where('organization_id', $lead->organization_id)
                ->where('user_type', UserType::CompanyUser->value)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Sales Manager', 'CRM Manager']))
                ->orderBy('id')
                ->first()
            ?? User::query()
                ->where('user_type', UserType::MasterAdmin->value)
                ->orderByRaw('CASE WHEN organization_id = ? THEN 0 ELSE 1 END', [$lead->organization_id])
                ->orderBy('id')
                ->first();

        return $owner ? $this->give($lead, $owner, 'fallback_owner') : null;
    }

    /**
     * Hand the lead over.
     *
     * Routed through `LeadWorkspaceService::assignLead()` so auto-assignment
     * writes the same activity, fires the same observer and sends the same
     * notification as somebody doing it by hand — an owner who cannot tell how
     * they got a lead cannot question it.
     */
    protected function give(Lead $lead, User $user, string $reason): User
    {
        $this->workspace->assignLead($lead, $user, null, $reason, notify: true, createTask: false);

        return $user;
    }

    /**
     * Leads that slipped through before this existed.
     *
     * Not called by the application — it is here for a console command or a
     * one-off repair, because the observer only covers leads created from the
     * moment it was installed.
     *
     * @return int how many found an owner
     */
    public function backfill(?int $organizationId = null, int $limit = 500): int
    {
        $assigned = 0;

        Lead::query()
            ->whereNull('assigned_user_id')
            ->whereNull('assigned_team_id')
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->limit($limit)
            ->get()
            ->each(function (Lead $lead) use (&$assigned) {
                if ($this->autoAssign($lead)) {
                    $assigned++;
                }
            });

        return $assigned;
    }
}
