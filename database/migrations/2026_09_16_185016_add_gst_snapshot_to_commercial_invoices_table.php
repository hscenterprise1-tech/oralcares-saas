<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What makes a `commercial_invoices` row a complete Tax Invoice document,
 * not just a ledger entry.
 *
 * `CommercialLedgerService::generateInvoice()` already raises this row the
 * moment a quotation is settled in full — that trigger point is correct and
 * unchanged. What it could not yet do is stand alone as the tax document a
 * customer keeps: it had `tax_amount` but no seller/buyer GSTIN, no state,
 * no rate, no CGST/SGST/IGST split.
 *
 * These are a snapshot, not a live read of the quotation or the provider
 * organisation. A tax invoice is a legal record of what was true the moment
 * it was issued — if the seller's GSTIN is corrected next year, every
 * invoice already raised must keep showing the number that was actually on
 * it, so this copies the figures in at generation time rather than joining
 * out to `organizations`/`leads` on every render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->string('seller_gstin')->nullable()->after('tax_amount');
            $table->string('seller_state')->nullable()->after('seller_gstin');
            $table->string('buyer_gstin')->nullable()->after('seller_state');
            $table->string('buyer_state')->nullable()->after('buyer_gstin');
            $table->decimal('taxable_amount', 15, 2)->nullable()->after('buyer_state');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('taxable_amount');
            $table->string('tax_type')->nullable()->after('gst_rate');
            $table->decimal('cgst_amount', 15, 2)->default(0)->after('tax_type');
            $table->decimal('sgst_amount', 15, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 15, 2)->default(0)->after('sgst_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'seller_gstin', 'seller_state', 'buyer_gstin', 'buyer_state',
                'taxable_amount', 'gst_rate', 'tax_type', 'cgst_amount', 'sgst_amount', 'igst_amount',
            ]);
        });
    }
};
