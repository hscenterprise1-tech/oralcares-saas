<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `converted` did not mean converted.
 *
 * `completeTrial()` sets it when the outcome is *quotation requested* — the
 * customer asked for a price, which is a good sign and not a sale. Conversion
 * happens at Won, gated on an accepted agreement and a settled payment, and
 * every report counting "converted trials" has been counting the wrong thing.
 *
 * The mapping is one-to-one, so no log table is needed to reverse it: `down()`
 * restores the exact prior value for exactly the rows this changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('trials')->where('status', 'converted')->update(['status' => 'quotation_requested']);
    }

    public function down(): void
    {
        DB::table('trials')->where('status', 'quotation_requested')->update(['status' => 'converted']);
    }
};
