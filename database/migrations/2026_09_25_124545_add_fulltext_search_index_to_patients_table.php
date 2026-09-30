<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // `phone` already has a plain index (see the earlier
        // `add_search_indexes_to_patients_table` migration);
        // `alternate_phone` search uses the same prefix-match strategy and
        // needs the same kind of index to benefit from it. Ordinary index,
        // works on every driver including SQLite (the test database).
        Schema::table('patients', function (Blueprint $table) {
            $table->index('alternate_phone', 'patients_alternate_phone_index');
        });

        // `%term%` LIKE search on name columns can never use a normal
        // B-tree index — it's a leading wildcard. A FULLTEXT index lets the
        // directory and modal-autocomplete searches use MySQL's native
        // `MATCH ... AGAINST (... IN BOOLEAN MODE)` instead, which is
        // indexed and prefix-aware (`term*`). SQLite has no FULLTEXT
        // support at all (`Schema::table` would throw building it), so —
        // same convention as `create_ent_search_tables` — this only runs
        // on MySQL/MariaDB; `PatientController::applyPatientSearch()`
        // falls back to `LIKE` on any other driver.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE patients ADD FULLTEXT patients_name_fulltext (name, first_name, last_name)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE patients DROP INDEX patients_name_fulltext');
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_alternate_phone_index');
        });
    }
};
