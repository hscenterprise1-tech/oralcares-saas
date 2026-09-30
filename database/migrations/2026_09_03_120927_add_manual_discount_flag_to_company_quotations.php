<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember whether the discount was chosen or merely calculated.
 *
 * `company_quotations.discount` exists but was only ever a rollup of the line
 * discounts, recomputed from scratch every time the items were saved. So a
 * negotiated headline discount could be typed in and then silently destroyed by
 * the next line-item edit — which is why the discount had to become editable in
 * its own right.
 *
 * This flag is the difference between the two. False keeps the old behaviour:
 * the header follows the lines. True means a person set that number and nothing
 * may overwrite it.
 *
 * Defaults to false, so every existing quotation keeps behaving exactly as it
 * does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->boolean('discount_is_manual')->default(false)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropColumn('discount_is_manual');
        });
    }
};
