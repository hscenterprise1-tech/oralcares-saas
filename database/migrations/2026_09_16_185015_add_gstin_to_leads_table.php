<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's own GST registration, captured during the sales
 * conversation alongside the address fields that already live here.
 *
 * A prospect is quoted, approved and paid long before any deal is Won and a
 * customer `Organization` exists — `leads.state` is already the source
 * `GstCalculationService` reads to decide intra- vs inter-state, so the
 * matching GSTIN belongs beside it rather than waiting for provisioning.
 * Nullable: most quotations will be raised before a customer has volunteered
 * theirs, and the GST breakdown must still compute correctly without it —
 * only the buyer-GSTIN line on the printed document is absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('gstin')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('gstin');
        });
    }
};
