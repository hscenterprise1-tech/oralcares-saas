<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a branch's name is the one provisioning gave it because none was
 * supplied, as opposed to a name somebody chose. The Clinic Portal names a
 * single-branch clinic's branch only when somebody chose it.
 *
 * Existing rows are classified by the two fallbacks provisioning has used,
 * exactly, and nothing else: the literal "Main Branch", and a trial's branch
 * named after its clinic. Every other name — including near-misses a person
 * may have typed — stays a chosen name. No branch is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('name_is_default')->default(false)->after('name');
        });

        DB::table('branches')->where('name', 'Main Branch')->update(['name_is_default' => true]);

        DB::table('branches')
            ->whereExists(fn ($query) => $query->from('clinics')
                ->whereColumn('clinics.id', 'branches.clinic_id')
                ->whereColumn('clinics.name', 'branches.name'))
            ->update(['name_is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('name_is_default');
        });
    }
};
