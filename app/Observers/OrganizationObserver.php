<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomerSuccessSnapshot;
use App\Models\Organization;

/**
 * Every customer account has a projection row, from the moment it exists.
 *
 * The Customer Success list filters and sorts on `customer_success_snapshots`,
 * and it joined that table with a LEFT JOIN because an account might not have a
 * row yet. That single keyword cost the module everything it was built for: a
 * LEFT JOIN forces MySQL to make organizations the driving table, so ordering
 * by any projected column — health, days to expiry, utilisation — materialised
 * all 120,000 rows and filesorted them. Measured on a seeded book that size:
 *
 *     LEFT JOIN  ... ORDER BY css.days_to_expiry LIMIT 25   2,892 ms
 *     INNER JOIN ... ORDER BY css.days_to_expiry LIMIT 25       0.5 ms
 *
 * An INNER JOIN lets the planner read the snapshot index in order and stop
 * after twenty-five rows. It is only honest if the projection is complete,
 * which is what this observer guarantees: the nightly refresh covers the whole
 * book, and this covers the gap between an account being created and that run.
 *
 * The row written here is deliberately empty of derived values. Computing
 * health for an account created a millisecond ago would read a handful of empty
 * relations to arrive at nothing; every write path that gives the account
 * something to measure — a subscription, a branch, an onboarding step — already
 * refreshes the snapshot properly.
 */
class OrganizationObserver
{
    public function created(Organization $organization): void
    {
        if (! $organization->isCustomer()) {
            return;
        }

        CustomerSuccessSnapshot::firstOrCreate(
            ['organization_id' => $organization->id],
            $this->seedRow($organization)
        );
    }

    /**
     * The opening state of an account's projection.
     *
     * Carries across what the account row already states about itself rather
     * than starting blank. Several surfaces ask "is there a snapshot?" and fall
     * back to the organization's own columns when there is not; once every
     * account has a row that fallback never fires again, so a blank row would
     * quietly replace a recorded health score or churn risk with nothing.
     *
     * Nothing here is derived. Health, utilisation and stage are computed by
     * `AccountSnapshotService` from relations this account does not have yet,
     * and inventing them at creation time would be the fabricated-figure
     * problem this module has already been cleaned of once.
     *
     * @return array<string, mixed>
     */
    private function seedRow(Organization $organization): array
    {
        return [
            'stage' => 'not_provisioned',
            'has_subscription' => false,
            'health_score' => $organization->health_score,
            'churn_risk' => $organization->churn_risk,
            'renewal_probability' => $organization->renewal_probability,
            'expansion_opportunity' => (bool) $organization->expansion_opportunity,
            /* Null says what is true: projected, never computed. */
            'computed_at' => null,
        ];
    }

    /**
     * An organization promoted to customer after the fact still gets its row.
     *
     * Rare, but it happens: a record created as something else and corrected.
     * Without this the account would be invisible to the whole module until the
     * nightly run, which is the precise failure an INNER JOIN cannot forgive.
     */
    public function updated(Organization $organization): void
    {
        if (! $organization->wasChanged('role')) {
            return;
        }

        if (! $organization->isCustomer()) {
            $this->prune($organization);

            return;
        }

        CustomerSuccessSnapshot::firstOrCreate(
            ['organization_id' => $organization->id],
            $this->seedRow($organization)
        );
    }

    /**
     * A deleted account leaves the projection with it.
     *
     * This is the other half of the contract the list query depends on, and it
     * buys the same thing the first half did. `Organization` is soft-deleted, so
     * every query against it carries `deleted_at IS NULL` — and that single
     * extra predicate was enough to make MySQL abandon the snapshot index and
     * sort the whole book again: 0.5 ms against 2,025 ms, same twenty-five rows.
     *
     * So the list asks for the accounts `withTrashed()` and lets the INNER JOIN
     * do the excluding. That is only truthful while a trashed account has no
     * projection row, which is what this guarantees. `CustomerSuccessInvariant`
     * pins it, because the failure would be silent: deleted customers quietly
     * reappearing in somebody's book.
     */
    public function deleted(Organization $organization): void
    {
        $this->prune($organization);
    }

    public function restored(Organization $organization): void
    {
        if (! $organization->isCustomer()) {
            return;
        }

        CustomerSuccessSnapshot::firstOrCreate(
            ['organization_id' => $organization->id],
            $this->seedRow($organization)
        );
    }

    private function prune(Organization $organization): void
    {
        CustomerSuccessSnapshot::where('organization_id', $organization->id)->delete();
    }
}
