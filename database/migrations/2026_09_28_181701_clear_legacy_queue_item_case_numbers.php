<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M3 — queue items stop carrying their own "Q-01" style number.
 *
 * A patient in the queue is identified by name initials, the last four digits
 * of their case number and their doctor; `position` is only an ordering key
 * and is never shown. The `case_number` column stays (unused) rather than
 * being dropped, so this change is a data change only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clinic_queue_items')->whereNotNull('case_number')->update(['case_number' => null]);
    }

    /**
     * Approximate: the old value was always written as `Q-%02d` of the
     * position at check-in, so it is rebuilt from `position`. A position that
     * was later changed by hand would rebuild to a different number.
     */
    public function down(): void
    {
        DB::table('clinic_queue_items')->whereNull('case_number')->orderBy('id')->get(['id', 'position'])
            ->each(fn (object $row) => DB::table('clinic_queue_items')->where('id', $row->id)->update([
                'case_number' => sprintf('Q-%02d', $row->position),
            ]));
    }
};
