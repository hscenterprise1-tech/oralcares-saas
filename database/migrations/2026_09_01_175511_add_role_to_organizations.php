<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record what each organization is to us, instead of guessing.
 *
 * The seller was identified by the literal string 'OralCares HQ' in two
 * services, by `min('id')` as a fallback, and customers were found by asking
 * whether a row happened to have clinics or won leads — which is why the
 * Accounts directory returned nothing.
 *
 * The backfill deliberately does not look at the name. It asks the only
 * question that is actually true of the seller and false of a customer: whose
 * organization owns the leads and the staff. A customer has neither — customers
 * have clinics, subscriptions and patients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            /*
             * Defaults to customer, because that is what almost every row will
             * be for the rest of the product's life — at ten thousand clinics
             * there is exactly one provider.
             */
            $table->string('role', 16)->default('customer')->after('type');
            $table->index('role');
        });

        /*
         * The provider is the organization the company's own people belong to.
         *
         * Leads are the second signal: a lead is something the seller works, so
         * an organization that owns leads is the seller. Both are checked
         * because a fresh install may have users before it has leads.
         */
        $providerId = DB::table('users')
            ->whereIn('user_type', ['master_admin', 'company_user'])
            ->whereNotNull('organization_id')
            ->orderByRaw('count(*) desc')
            ->groupBy('organization_id')
            ->value('organization_id')
            ?? DB::table('leads')->whereNotNull('organization_id')->value('organization_id');

        if ($providerId) {
            DB::table('organizations')->where('id', $providerId)->update(['role' => 'provider']);
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
