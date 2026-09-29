<?php

declare(strict_types=1);

namespace App\Http\Controllers\Company\CustomerSuccess;

use App\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Services\CustomerSuccess\SubscriptionActionService;
use App\Support\CrmScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Changing a customer's contract, from the module that owns the customer.
 *
 * Customer Success could read a subscription and could not touch one. Every
 * upgrade, seat change, renewal and cancellation either went through the CRM as
 * a *deal* — the pipeline built for winning strangers — or was done by hand in
 * the database. Renewing an existing customer onto the plan they already have
 * is not a sale.
 *
 * Four verbs, each authorising against the account rather than the subscription:
 * a contract is reachable only through the customer it belongs to, and
 * `assertReachable()` is what stops a posted id from another tenant's book
 * being changed through a route that only checked a permission.
 */
class SubscriptionActionController extends Controller
{
    public function __construct(protected SubscriptionActionService $actions) {}

    public function changePlan(Request $request, CompanySubscription $subscription)
    {
        $this->assertReachable($request, $subscription);

        $validated = $request->validate([
            'company_plan_id' => ['required', 'integer', 'exists:company_plans,id'],
            /*
             * Money is stated, never inferred. Adopting the new plan's list
             * price would quietly rewrite what this customer agreed to pay.
             */
            'mrr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'max_users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_branches' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_doctors' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'effective_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = CompanyPlan::findOrFail($validated['company_plan_id']);

        return $this->run(
            fn () => $this->actions->changePlan($subscription, $plan, $request->user(), [
                'mrr' => $validated['mrr'] ?? null,
                'max_users' => $validated['max_users'] ?? null,
                'max_branches' => $validated['max_branches'] ?? null,
                'max_doctors' => $validated['max_doctors'] ?? null,
                'effective_at' => isset($validated['effective_at']) ? now()->parse($validated['effective_at']) : null,
                'reason' => $validated['reason'] ?? null,
            ]),
            $request,
            $subscription,
            "Plan changed to {$plan->name}.",
        );
    }

    public function adjustEntitlements(Request $request, CompanySubscription $subscription)
    {
        $this->assertReachable($request, $subscription);

        $validated = $request->validate([
            'max_users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_branches' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_doctors' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mrr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->run(
            fn () => $this->actions->adjustEntitlements($subscription, $validated, $request->user()),
            $request,
            $subscription,
            'Entitlements updated.',
        );
    }

    /**
     * The common case: same customer, same plan, another term.
     */
    public function renew(Request $request, CompanySubscription $subscription)
    {
        $this->assertReachable($request, $subscription);

        $validated = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'company_plan_id' => ['nullable', 'integer', 'exists:company_plans,id'],
            'mrr' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = isset($validated['company_plan_id'])
            ? CompanyPlan::findOrFail($validated['company_plan_id'])
            : null;

        return $this->run(
            fn () => $this->actions->renew($subscription, (int) $validated['months'], $request->user(), [
                'plan' => $plan,
                'mrr' => $validated['mrr'] ?? null,
                'reason' => $validated['reason'] ?? null,
            ]),
            $request,
            $subscription,
            "Renewed for {$validated['months']} months.",
        );
    }

    public function cancel(Request $request, CompanySubscription $subscription)
    {
        $this->assertReachable($request, $subscription);

        $validated = $request->validate([
            /* A cancellation without a reason teaches nobody anything. */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'effective_at' => ['nullable', 'date'],
        ]);

        return $this->run(
            fn () => $this->actions->cancel(
                $subscription,
                $validated['reason'],
                $request->user(),
                isset($validated['effective_at']) ? now()->parse($validated['effective_at']) : null,
            ),
            $request,
            $subscription,
            'Contract cancelled.',
        );
    }

    /**
     * Run one action, record it on the account's timeline, report the outcome.
     *
     * The service throws `InvalidArgumentException` for the things a form
     * cannot check — renewing onto the plan already held, an adjustment that
     * names no limit — and those are the reader's mistakes, not errors, so they
     * come back as validation messages rather than a five hundred.
     */
    /**
     * Renew a selection of contracts in one action.
     *
     * A renewals queue is a list of dates, and the answer to most of them is
     * "yes, another year". Doing that one modal at a time is four clicks per
     * customer; a team holding a hundred and twenty thousand accounts cannot
     * spend a morning on a week's worth of renewals.
     *
     * Deliberately the narrowest possible bulk verb. It extends terms and
     * nothing else — no plan, no money, no entitlements — because those differ
     * per customer and a bulk form that can silently reprice a hundred
     * contracts is a bulk form that eventually does.
     */
    public function bulkRenew(Request $request)
    {
        Gate::authorize('cs.accounts.manage');

        $validated = $request->validate([
            /*
             * Bounded, so one request cannot walk the whole book. Each renewal
             * is its own transaction and its own snapshot refresh; two hundred
             * is a working selection, not a migration.
             */
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'months' => ['required', 'integer', 'min:1', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * The scope re-resolves the posted ids rather than trusting them. A
         * selection arrives as a list of numbers from a browser, and the only
         * thing that makes those numbers safe is asking the database which of
         * them this reader may actually reach.
         */
        $reachable = CompanySubscription::query()
            ->whereIn('id', $validated['ids'])
            ->whereIn(
                'organization_id',
                CrmScope::customerAccounts($request->user())->select('organizations.id')
            )
            ->get();

        $months = (int) $validated['months'];
        $renewed = 0;
        $failed = [];

        foreach ($reachable as $subscription) {
            try {
                $this->actions->renew($subscription, $months, $request->user(), [
                    'reason' => $validated['reason'] ?? null,
                ]);
                $renewed++;
            } catch (InvalidArgumentException $e) {
                /*
                 * One contract that cannot be renewed does not abandon the
                 * other ninety-nine. What failed is named back to the reader
                 * rather than swallowed, so they know what still needs doing.
                 */
                $failed[] = ($subscription->organization?->name ?? "Contract #{$subscription->id}").' — '.$e->getMessage();
            }
        }

        if ($renewed === 0) {
            throw ValidationException::withMessages([
                'ids' => $failed !== []
                    ? 'Nothing was renewed. '.implode(' ', array_slice($failed, 0, 3))
                    : 'None of those contracts is in your book.',
            ]);
        }

        $summary = $renewed.' contract'.($renewed === 1 ? '' : 's').' renewed for '.$months.' months.';

        if ($failed !== []) {
            $summary .= ' '.count($failed).' could not be renewed: '.implode(' ', array_slice($failed, 0, 3));
        }

        return back()->with('success', $summary);
    }

    private function run(callable $action, Request $request, CompanySubscription $subscription, string $success)
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['subscription' => $e->getMessage()]);
        }

        $subscription->organization?->activities()->create([
            'user_id' => $request->user()?->id,
            'type' => ActivityType::SystemUpdate,
            'description' => $success,
        ]);

        return back()->with('success', $success);
    }

    /**
     * A contract is reachable only through a customer this reader may change.
     *
     * Route-model binding hands over any subscription whose id appears in the
     * URL. Authorising against the owning organisation is what keeps one
     * tenant's book out of another's reach — the same rule
     * `AccountStructureController` applies to clinics and branches.
     */
    private function assertReachable(Request $request, CompanySubscription $subscription): void
    {
        Gate::authorize('cs.accounts.manage');

        $organization = $subscription->organization;

        abort_if($organization === null, 404);

        abort_unless(
            CrmScope::customerAccounts($request->user())
                ->where('organizations.id', $organization->id)
                ->exists(),
            404,
        );
    }
}
