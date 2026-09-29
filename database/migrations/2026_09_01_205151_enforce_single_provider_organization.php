<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * There is exactly one provider. Make the data say so.
 *
 * `role` was added with a backfill that promoted a single organization, but
 * nothing stopped a second one being written as a provider afterwards — and
 * three had been. That is not a cosmetic problem: `customerAccounts()` excludes
 * providers, so every wrongly-promoted row vanished from the Accounts
 * directory, and `provider()` returns the first match, so which organization
 * counted as "us" depended on insertion order.
 *
 * The provider is the organization the company's own staff belong to. Where
 * several claim the role, the one with the most staff wins and the rest are
 * returned to being customers, which is what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $providers = DB::table('organizations')->where('role', 'provider')->pluck('id');

        if ($providers->count() <= 1) {
            return;
        }

        $staffPerOrg = DB::table('users')
            ->select('organization_id', DB::raw('count(*) as staff'))
            ->whereIn('user_type', ['master_admin', 'company_user'])
            ->whereIn('organization_id', $providers)
            ->groupBy('organization_id')
            ->pluck('staff', 'organization_id');

        $keep = $providers
            ->sortByDesc(fn (int $id): int => (int) ($staffPerOrg[$id] ?? 0))
            ->first();

        DB::table('organizations')
            ->where('role', 'provider')
            ->where('id', '!=', $keep)
            ->update(['role' => 'customer']);
    }

    /**
     * Irreversible by design: the rows this corrects were wrong, and restoring
     * them would only hide customers from the directory again.
     */
    public function down(): void {}
};
