<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt's own identity, distinct from the invoice it settles and the
 * quotation number it traces back to.
 *
 * `commercial_payments` already is the one row a successful payment produces
 * — `CommercialLedgerService::recordPayment()` only ever creates one for
 * `status === 'successful'`. A receipt document is a print/PDF view of this
 * exact row; it needed its own number, not a repurposed invoice or
 * quotation number, because a single invoice can be settled by more than one
 * payment (part-payments), each of which is its own receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->unique()->after('transaction_reference');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
