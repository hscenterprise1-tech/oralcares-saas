<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attach money to the quotation it settles.
 *
 * The commercial flow is Quotation → Approval → Payment → Invoice → Won. There
 * is no agreement step. But `payments` and `commercial_invoices` key only on
 * `commercial_document_id` — the agreement — so under the real flow there was
 * no way to record a payment against a quotation at all.
 *
 * `commercial_document_id` is deliberately left in place on both tables. One
 * live payment of ₹5.4L points at document 7, and that record predates this
 * flow; dropping the column would destroy it. It stays a legacy link, read but
 * never written by the new path, and the row is not back-linked to any
 * quotation — inventing an association it never had would be worse than
 * leaving it plainly orphaned.
 *
 * Both columns are nullable and neither is read by existing code, so this is
 * additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('company_quotation_id')->nullable()->after('lead_id')
                ->constrained('company_quotations')->nullOnDelete();
        });

        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->foreignId('company_quotation_id')->nullable()->after('commercial_document_id')
                ->constrained('company_quotations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_quotation_id');
        });

        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_quotation_id');
        });
    }
};
