<?php

namespace App\Http\Controllers\Company\Lifecycle;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\ProvisioningAudit;
use App\Models\Trial;
use App\Models\User;
use App\Services\Lifecycle\AccountLifecycleService;
use App\Support\CrmScope;
use App\TrialStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where every account is in its life, and what went wrong getting there.
 *
 * Read-only, and composed from records that already exist: `provisioning_audits`
 * for what the engine did, `onboarding_progress` for the setup machine, `trials`
 * for the evaluation, and the subscription window for the paid term.
 *
 * Retrying a failed provision is deliberately **not** here. That path already
 * exists behind the trials surface, and a second button calling the same engine
 * from a different page is how two modules end up disagreeing about whether
 * something was retried.
 */
class ProvisioningController extends Controller
{
    public function __construct(protected AccountLifecycleService $lifecycle) {}

    public function index(Request $request): Response
    {
        Gate::authorize('view-provisioning');

        $filters = $request->only(['search', 'stage', 'status']);
        $user = $request->user();

        $query = CrmScope::customerAccounts($user)
            ->with(['clinics:id,organization_id,name'])
            ->when($filters['search'] ?? null, fn ($builder, $search) => $builder->where('name', 'like', "%{$search}%"));

        if (! empty($filters['stage'])) {
            $query = $this->lifecycle->filterByStage($query, $filters['stage']);
        }

        $accounts = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        /*
         * Three batched lookups for the page, not four per row.
         *
         * This used to call `lifecycleFor()` on every organisation, and that
         * method assembles an account's whole life — twenty provisioning
         * audits, fifty subscription events, the trial and the onboarding
         * record — to answer a list row that shows five fields. Rendering
         * thirty-six accounts took two hundred and sixty queries, and the count
         * rose with the book.
         *
         * The stage and onboarding columns come off the projection, which
         * already stores them. Only the trial and the last provisioning attempt
         * still need reading, and both are fetched for the whole page at once.
         */
        $organizationIds = $accounts->getCollection()->pluck('id')->all();

        /*
         * The stage is derived here by the same `stageFor()` the stage *filter*
         * uses, not read off the projection.
         *
         * Reading it from the snapshot looked tidier and was wrong: the filter
         * translates `stageFor()` into SQL over the subscription's own dates,
         * so an account with no snapshot row yet would be selected as
         * "trialling" by the filter and then labelled "not provisioned" by the
         * row. One definition, two readings, guaranteed to disagree.
         *
         * `stageOnly()` and `window()` are pure — they read columns off models
         * already in memory — so this costs two queries for the page rather
         * than four per row.
         */
        $latestSubscriptions = CompanySubscription::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderBy('id')
            ->get()
            ->keyBy('organization_id');

        $onboardings = OnboardingProgress::query()
            ->whereIn('organization_id', $organizationIds)
            ->orderBy('id')
            ->get()
            ->keyBy('organization_id');

        $trials = Trial::query()
            ->whereIn('customer_organization_id', $organizationIds)
            ->orderByDesc('id')
            ->get(['id', 'customer_organization_id', 'status', 'provisioning_error'])
            ->keyBy('customer_organization_id');

        $lastAudits = $this->latestAuditsFor($organizationIds);

        $rows = $accounts->getCollection()
            ->map(function (Organization $organization) use ($trials, $lastAudits, $latestSubscriptions, $onboardings) {
                $trial = $trials->get($organization->id);
                $subscription = $latestSubscriptions->get($organization->id);
                $onboarding = $onboardings->get($organization->id);

                return [
                    'organization_id' => $organization->id,
                    'account' => $organization->name,
                    'stage' => $this->lifecycle->stageOnly($subscription, $onboarding),
                    'window_state' => $subscription ? $this->lifecycle->windowStateFor($subscription) : null,
                    'onboarding_step' => $onboarding?->current_step,
                    'onboarding_status' => $onboarding?->status,
                    'trial_status' => $trial?->status instanceof \BackedEnum
                        ? $trial->status->value
                        : ($trial?->status !== null ? (string) $trial->status : null),
                    'provisioning_error' => $trial?->provisioning_error,
                    'last_provisioning' => $lastAudits[$organization->id] ?? null,
                    'subscription_id' => $subscription?->id,
                ];
            });

        return Inertia::render('Company/Lifecycle/ProvisioningWorkspace', [
            'rows' => $rows->values(),
            'paginator' => [
                'links' => $accounts->linkCollection(),
                'from' => $accounts->firstItem(),
                'to' => $accounts->lastItem(),
                'total' => $accounts->total(),
            ],
            'filters' => $filters,
            'summary' => $this->summary($user),
        ]);
    }

    /**
     * The most recent provisioning attempt for each account on this page.
     *
     * One query for the page. `provisioning_audits` records a clinic, not an
     * organisation, so the clinic → organisation mapping is fetched alongside
     * and the newest row per account wins — which is what the list column means
     * by "last provisioned".
     *
     * @param  list<int>  $organizationIds
     * @return array<int, array<string, mixed>|null>
     */
    protected function latestAuditsFor(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $clinicOwners = Clinic::query()
            ->whereIn('organization_id', $organizationIds)
            ->pluck('organization_id', 'id');

        if ($clinicOwners->isEmpty()) {
            return [];
        }

        $latest = [];

        ProvisioningAudit::query()
            ->whereIn('clinic_id', $clinicOwners->keys())
            ->orderByDesc('id')
            ->get(['id', 'clinic_id', 'profile', 'status', 'duration_ms', 'version', 'error_message', 'created_at'])
            ->each(function (ProvisioningAudit $audit) use ($clinicOwners, &$latest) {
                $organizationId = (int) $clinicOwners[$audit->clinic_id];

                /* Ordered newest first, so the first one seen is the latest. */
                if (isset($latest[$organizationId])) {
                    return;
                }

                $latest[$organizationId] = [
                    'id' => $audit->id,
                    'profile' => $audit->profile,
                    'status' => $audit->status,
                    'duration_ms' => $audit->duration_ms,
                    'version' => $audit->version,
                    'error' => $audit->error_message,
                    'at' => $audit->created_at?->format('d M Y, H:i'),
                ];
            });

        return $latest;
    }

    /**
     * Counts across the whole book, aggregated in SQL.
     *
     * @return array<string, mixed>
     */
    protected function summary(?User $user = null): array
    {
        $accountsQuery = CrmScope::customerAccounts($user);
        $accountIds = (clone $accountsQuery)->select('organizations.id');

        return [
            'accounts' => (clone $accountsQuery)->count(),
            'subscriptions' => CompanySubscription::query()->whereIn('organization_id', $accountIds)->count(),
            'onboarding_open' => OnboardingProgress::query()->whereIn('organization_id', $accountIds)->where('status', '!=', 'completed')->count(),
            'trials_active' => Trial::query()->whereIn('customer_organization_id', $accountIds)->where('status', TrialStatus::Active->value)->count(),
            /*
             * A trial stuck in `preparing` with an error recorded is the one
             * failure state the engine actually writes down.
             */
            'provisioning_failed' => Trial::query()->whereIn('customer_organization_id', $accountIds)->whereNotNull('provisioning_error')->count(),
            'audits' => ProvisioningAudit::query()
                ->whereIn('clinic_id', Clinic::query()->whereIn('organization_id', $accountIds)->select('clinics.id'))
                ->count(),
            'audits_failed' => ProvisioningAudit::query()
                ->whereIn('clinic_id', Clinic::query()->whereIn('organization_id', $accountIds)->select('clinics.id'))
                ->where('status', 'failed')
                ->count(),
        ];
    }
}
