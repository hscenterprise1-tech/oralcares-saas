<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The procedure catalogue (`clinical_procedures`) belongs to a clinic, so a
 * procedure code only has to be unique within that clinic. It was unique
 * across every clinic, which stopped a second clinic from ever having "D0120".
 *
 * Prices (`clinical_procedure_prices`) gain an optional branch: a row with no
 * branch is the clinic-wide price, a row with a branch overrides it there.
 * `branch_key` is `branch_id` with NULL read as 0, so the unique index also
 * allows only one clinic-wide price per procedure (a plain index would treat
 * every NULL as distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_procedures', function (Blueprint $table) {
            $table->unique(['clinic_id', 'code'], 'clinical_procedures_clinic_code_unique');
        });

        Schema::table('clinical_procedures', function (Blueprint $table) {
            $table->dropUnique('clinical_procedures_code_unique');
        });

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('clinic_id')->constrained('branches')->cascadeOnDelete();
        });

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_key')->virtualAs('COALESCE(branch_id, 0)');
        });

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->unique(['clinic_id', 'clinical_procedure_id', 'branch_key'], 'clinical_procedure_prices_clinic_procedure_branch_unique');
        });

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->dropUnique('clinical_procedure_prices_clinic_id_clinical_procedure_id_unique');
        });
    }

    public function down(): void
    {
        $branchPrices = DB::table('clinical_procedure_prices')->whereNotNull('branch_id')->count();

        if ($branchPrices > 0) {
            throw new RuntimeException("{$branchPrices} branch prices exist; remove them by hand before rolling back.");
        }

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->unique(['clinic_id', 'clinical_procedure_id']);
        });

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->dropUnique('clinical_procedure_prices_clinic_procedure_branch_unique');
            $table->dropColumn('branch_key');
        });

        Schema::table('clinical_procedure_prices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('clinical_procedures', function (Blueprint $table) {
            $table->unique('code');
        });

        Schema::table('clinical_procedures', function (Blueprint $table) {
            $table->dropUnique('clinical_procedures_clinic_code_unique');
        });
    }
};
