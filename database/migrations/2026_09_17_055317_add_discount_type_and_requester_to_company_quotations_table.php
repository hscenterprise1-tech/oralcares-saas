<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — a discount is now typed (percentage or fixed amount), and the
 * approval rule needs to know *who* set it, because an admin and an employee
 * have different direct-discount ceilings.
 *
 * `discount` stays exactly what it always was — the resolved rupee amount
 * every other piece of the system already reads (GST calc, PDF, invoice
 * snapshot, the React detail page). `discount_type`/`discount_value` are the
 * new *inputs* that resolve to it; every existing row is backfilled as
 * `fixed` with `discount_value = discount`, since a flat amount is exactly
 * what the old system only ever supported — nothing about a pre-existing
 * quotation's numbers changes.
 *
 * `discount_requested_by_id` records who last set the discount. It is the
 * one piece of state the tiered approval rule needs: `QuotationApprovalService`
 * falls back to the original global-threshold behaviour whenever it is null,
 * so a quotation created before this migration — or in a test fixture that
 * writes `discount` directly — is completely unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->string('discount_type')->default('fixed')->after('discount');
            $table->decimal('discount_value', 15, 2)->nullable()->after('discount_type');
            $table->foreignId('discount_requested_by_id')->nullable()->after('discount_is_manual')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('company_quotations')->update([
            'discount_value' => DB::raw('discount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_requested_by_id');
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
