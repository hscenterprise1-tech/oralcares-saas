<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The physio treatments one session of this package covers, as sold
     * (`PhysioCatalogue` keys; null = every treatment). A treatment outside
     * it is charged per visit. Kept on the package so a later change to the
     * owner's template never changes what was sold.
     */
    public function up(): void
    {
        Schema::table('patient_session_packages', function (Blueprint $table) {
            $table->json('includes')->nullable()->after('sessions_adjusted');
        });
    }

    public function down(): void
    {
        Schema::table('patient_session_packages', function (Blueprint $table) {
            $table->dropColumn('includes');
        });
    }
};
