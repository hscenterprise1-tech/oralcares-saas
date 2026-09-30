<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the legacy deals a tenant.
 *
 * `leads.organization_id` is the CRM tenant — the OralCares organisation
 * working the deal — and `createLead()` has stamped it since Phase 0. Rows
 * created before that stamp carry null, and `LeadPolicy` had to treat a null
 * tenant as "matches everybody" to keep them reachable. That branch is what
 * let any authenticated CRM user open any organisation's open pipeline.
 *
 * The policy is made strict in the same change, so these rows have to be
 * given a real tenant first or they become invisible to everyone but a master
 * admin.
 *
 * Two sources, in order of confidence:
 *
 *   1. The tenant of whoever the deal is assigned to. If a rep owns it, it
 *      belongs to that rep's organisation.
 *   2. The only organisation that has company users on it. On a single-vendor
 *      install this is unambiguous; where it is not, the row is left alone
 *      rather than guessed at, and stays visible to master admins for a
 *      manual decision.
 *
 * Reversible: `down()` restores null for exactly the rows this touched, which
 * are recorded in the audit table below rather than re-derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lead_tenant_backfill_log')->truncate();

        $companyOrgIds = DB::table('users')
            ->where('user_type', 'company_user')
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id');

        $soleCompanyOrgId = $companyOrgIds->count() === 1 ? (int) $companyOrgIds->first() : null;

        $orphans = DB::table('leads')->whereNull('organization_id')->get(['id', 'assigned_user_id']);

        foreach ($orphans as $lead) {
            $tenantId = null;

            if ($lead->assigned_user_id) {
                $tenantId = DB::table('users')->where('id', $lead->assigned_user_id)->value('organization_id');
            }

            $tenantId ??= $soleCompanyOrgId;

            if (! $tenantId) {
                continue;
            }

            DB::table('leads')->where('id', $lead->id)->update(['organization_id' => $tenantId]);

            DB::table('lead_tenant_backfill_log')->insert([
                'lead_id' => $lead->id,
                'organization_id' => $tenantId,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('lead_tenant_backfill_log')->pluck('lead_id');

        if ($ids->isNotEmpty()) {
            DB::table('leads')->whereIn('id', $ids)->update(['organization_id' => null]);
        }

        DB::table('lead_tenant_backfill_log')->truncate();
    }
};
