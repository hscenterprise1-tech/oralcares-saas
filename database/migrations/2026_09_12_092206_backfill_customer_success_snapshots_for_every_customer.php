<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give every customer account the projection row the list query assumes.
 *
 * The Customer Success list joined `customer_success_snapshots` with a LEFT
 * JOIN, because an account might not have been projected yet. That one keyword
 * meant MySQL had to drive from `organizations`, so ordering by any projected
 * column materialised the whole book and filesorted it. On a seeded book of
 * 120,000 accounts: 2,892 ms with the LEFT JOIN, 0.5 ms with an INNER JOIN.
 *
 * The INNER JOIN is only truthful if no customer account is missing its row,
 * or the list silently stops showing it. Three things now guarantee that
 * together: this backfill for the accounts that already exist,
 * `OrganizationObserver` for the ones created from here on, and the nightly
 * refresh for everything in between.
 *
 * The rows written here carry no derived values — `computed_at` is null, which
 * says exactly what is true: projected, never computed. The nightly run fills
 * them in, and every write path that changes what there is to measure refreshes
 * the account's snapshot on the spot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')
            ->where('role', 'customer')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('customer_success_snapshots')
                    ->whereColumn('customer_success_snapshots.organization_id', 'organizations.id');
            })
            ->orderBy('id')
            /* Chunked, because this runs against the whole book on deploy. */
            ->chunkById(500, function ($organizations) {
                $now = now();

                DB::table('customer_success_snapshots')->insertOrIgnore(
                    collect($organizations)->map(fn ($organization) => [
                        'organization_id' => $organization->id,
                        'stage' => 'not_provisioned',
                        'has_subscription' => false,
                        'computed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling back would mean deleting projection rows, and there is no way to
     * tell the ones this migration created from the ones the nightly refresh
     * has since filled in. A complete projection is not a thing to undo.
     */
    public function down(): void {}
};
