<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `gateway` already exists and stays exactly what it was — the free-text
 * processor name (`manual`, `razorpay`, …). `payment_method` is a different,
 * narrower fact the Record Advance Payment flow needs to capture: which of
 * the four channels the money actually moved through. `payment_date` is the
 * date the rep is asserting the money arrived, which is not always the
 * moment they got round to typing it into the CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('gateway');
            $table->date('payment_date')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_date']);
        });
    }
};
