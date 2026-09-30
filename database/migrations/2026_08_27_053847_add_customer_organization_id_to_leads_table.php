<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate the two meanings that `leads.organization_id` was carrying.
 *
 * It was written at creation with the acting user's organization (the vendor
 * tenant) and simultaneously read as "the customer organization this lead
 * became when it was won". Because the first write always happened, the second
 * meaning could never be true, and CustomerProvisioningEngine's idempotency
 * guard treated every UI-created lead as already provisioned.
 *
 * This adds an explicit column for the won customer and leaves
 * `organization_id` with the tenant meaning every read path already assumes.
 * Additive and reversible: no existing column changes type or nullability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('customer_organization_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('organizations')
                ->nullOnDelete();

            $table->index('customer_organization_id');
        });

        // Backfill: a won lead that already points at an organization was
        // provisioned under the old single-column scheme, so that organization
        // is its customer. Open leads are left alone — their organization_id is
        // the tenant, never a customer.
        DB::table('leads')
            ->where('status', 'won')
            ->whereNotNull('organization_id')
            ->update(['customer_organization_id' => DB::raw('organization_id')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['customer_organization_id']);
            $table->dropIndex(['customer_organization_id']);
            $table->dropColumn('customer_organization_id');
        });
    }
};
