<?php

use App\Services\Staff\StaffOrphanFinder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys for the rows that hang off a staff record: branch placements,
 * pre-invitation roles, departments, rooms and invitations. These tables had
 * none, so deleting a staff record left its rows behind.
 *
 * Before the keys can exist, rows whose referenced parent row is missing are
 * removed (`StaffOrphanFinder`). That is the only rule: a row is removed when
 * the staff record (or branch, role, department, room) it points at does not
 * exist. Ids are never reused, so such a row can never become valid again and
 * grants nothing. Every removed row is written to the security log in full
 * first. `clinic:staff-audit --orphans` lists the same rows beforehand.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(StaffOrphanFinder::class)->remove();

        foreach (StaffOrphanFinder::REFERENCES as $table => $references) {
            Schema::table($table, function (Blueprint $blueprint) use ($references) {
                foreach ($references as $column => $parent) {
                    $blueprint->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (StaffOrphanFinder::REFERENCES as $table => $references) {
            Schema::table($table, function (Blueprint $blueprint) use ($references) {
                foreach (array_keys($references) as $column) {
                    $blueprint->dropForeign([$column]);
                }
            });
        }
    }
};
