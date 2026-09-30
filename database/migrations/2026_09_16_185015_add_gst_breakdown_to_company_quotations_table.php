<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit GST, replacing the flat `tax` figure with the actual breakdown.
 *
 * `tax` stays exactly as it is — the total-tax figure everything downstream
 * already reads (the PDF, `CommercialLedgerService::reconcile()`,
 * `grand_total`). What was missing was *how* that figure was reached: it was
 * a bare number a rep could type in, with no rate, no taxable base, and no
 * record of whether it was ever meant to be CGST+SGST or IGST.
 *
 * `GstCalculationService` fills these five columns from here on; `tax`
 * becomes their sum rather than an independently-entered figure. All are
 * nullable and every existing quotation is left with nulls here — the PDF
 * and printable view render the old flat "Tax" line for exactly the
 * quotations that predate this, and the new breakdown for everything after,
 * rather than guessing a split for money nobody recorded the state data for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->decimal('taxable_amount', 15, 2)->nullable()->after('discount');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('taxable_amount');
            /*
             * 'cgst_sgst' | 'igst' | null. Null means the split could not be
             * determined — seller or customer state was missing — not that no
             * tax applies; `tax` may still be nonzero in that case.
             */
            $table->string('tax_type')->nullable()->after('gst_rate');
            $table->decimal('cgst_amount', 15, 2)->default(0)->after('tax_type');
            $table->decimal('sgst_amount', 15, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 15, 2)->default(0)->after('sgst_amount');
        });
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropColumn(['taxable_amount', 'gst_rate', 'tax_type', 'cgst_amount', 'sgst_amount', 'igst_amount']);
        });
    }
};
