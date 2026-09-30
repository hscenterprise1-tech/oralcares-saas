<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Proforma Invoice is the same quotation, formatted as a request for
 * payment, once it has been Approved but before it is settled — there is no
 * separate ledger row to hang it off, because none exists yet at that point
 * in the lifecycle. Rather than a new table duplicating the quotation's own
 * commercial data, the quotation freezes its own proforma identity the first
 * time the document is generated, exactly the way `sent_at`/`approved_at`
 * already stamp the moment those things happened.
 *
 * Nullable and only ever set once: the number does not change on a second
 * download, and an unapproved or already-settled quotation simply never
 * gets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->string('proforma_number')->nullable()->unique()->after('rejection_reason');
            $table->timestamp('proforma_issued_at')->nullable()->after('proforma_number');
        });
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropUnique(['proforma_number']);
            $table->dropColumn(['proforma_number', 'proforma_issued_at']);
        });
    }
};
