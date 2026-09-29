<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database backstops for the reception queue. Check-in already serialises on
 * the branch row, so these should never fire; they turn a future bug into a
 * failed write instead of two patients at one position or two queues a day.
 *
 * - One position per queue: `(clinic_queue_id, position)`.
 * - One queue per branch, doctor and day. `doctor_key` is `doctor_id` with
 *   NULL read as 0, because the shared reception queue has no doctor and a
 *   plain unique index treats every NULL as distinct.
 *
 * Existing duplicates are never deleted: the migration stops and lists them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $positionClashes = DB::table('clinic_queue_items')
            ->select('clinic_queue_id', 'position', DB::raw('COUNT(*) as items'))
            ->groupBy('clinic_queue_id', 'position')
            ->having('items', '>', 1)
            ->get();

        $queueClashes = DB::table('clinic_queues')
            ->whereNotNull('queue_date')
            ->select('branch_id', 'queue_date', DB::raw('COALESCE(doctor_id, 0) as doctor_key'), DB::raw('COUNT(*) as queues'))
            ->groupBy('branch_id', 'queue_date', DB::raw('COALESCE(doctor_id, 0)'))
            ->having('queues', '>', 1)
            ->get();

        if ($positionClashes->isNotEmpty() || $queueClashes->isNotEmpty()) {
            throw new RuntimeException(
                'Queue data has duplicates; resolve them by hand before this migration can run. '
                .'Positions: '.$positionClashes->toJson().' Queues: '.$queueClashes->toJson()
            );
        }

        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->unique(['clinic_queue_id', 'position'], 'clinic_queue_items_queue_position_unique');
        });

        Schema::table('clinic_queues', function (Blueprint $table) {
            $table->unsignedBigInteger('doctor_key')->virtualAs('COALESCE(doctor_id, 0)');
        });

        Schema::table('clinic_queues', function (Blueprint $table) {
            $table->unique(['branch_id', 'queue_date', 'doctor_key'], 'clinic_queues_branch_day_doctor_unique');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_queues', function (Blueprint $table) {
            $table->dropUnique('clinic_queues_branch_day_doctor_unique');
        });

        Schema::table('clinic_queues', function (Blueprint $table) {
            $table->dropColumn('doctor_key');
        });

        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->dropUnique('clinic_queue_items_queue_position_unique');
        });
    }
};
