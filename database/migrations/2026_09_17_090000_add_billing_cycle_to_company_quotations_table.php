<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What tenure a quotation's `grand_total` is priced for.
 *
 * `company_plans.billing_period` is a catalogue fact — monthly or yearly, the
 * two tenures the plan is sold at. A quotation is a specific commercial
 * conversation with one clinic, and a rep may quote a quarter or a half-year
 * against a plan the catalogue only lists monthly or annual pricing for. The
 * quotation needs to say what it actually quoted, independently of the plan's
 * own default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->string('billing_cycle')->default('monthly')->after('company_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
