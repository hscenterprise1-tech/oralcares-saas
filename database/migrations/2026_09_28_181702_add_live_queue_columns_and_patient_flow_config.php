<?php

use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4 — what the live queue needs to run itself.
 *
 * - `clinic_queues.queue_date`: one reception queue per branch per clinic-local
 *   day, replacing the lookup by a "Reception General Queue - 28 Sep 2026"
 *   name string. `closed_at` is set by `queue:close-day`.
 * - `clinic_queue_items`: a timestamp for every state (`checked_in_at`,
 *   `started_at`, `completed_at`; `called_at` already exists), the doctor the
 *   patient is waiting for, and the appointment they checked in against.
 *   Queue timestamps are clinic wall-clock digits, like `scheduled_at`.
 * - `clinics.patient_flow_config`: per-clinic switches, rooms off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_queues', function (Blueprint $table) {
            $table->date('queue_date')->nullable()->after('doctor_id');
            $table->timestamp('closed_at')->nullable()->after('queue_date');
            $table->index(['clinic_id', 'branch_id', 'queue_date'], 'cq_clinic_branch_date_index');
        });

        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable()->after('patient_visit_id')
                ->constrained('clinic_employees')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->after('doctor_id')
                ->constrained('appointments')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->timestamp('started_at')->nullable()->after('called_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });

        Schema::table('clinics', function (Blueprint $table) {
            $table->json('patient_flow_config')->nullable()->after('case_number_config');
        });

        DB::table('clinic_queues')->whereNull('queue_date')->orderBy('id')->get(['id', 'created_at'])
            ->each(fn (object $row) => DB::table('clinic_queues')->where('id', $row->id)->update([
                'queue_date' => ClinicClock::fromUtc(CarbonImmutable::parse($row->created_at ?? 'now'))->toDateString(),
            ]));

        DB::table('clinic_queue_items')->whereNull('checked_in_at')->orderBy('id')->get(['id', 'created_at', 'patient_visit_id'])
            ->each(function (object $row): void {
                DB::table('clinic_queue_items')->where('id', $row->id)->update([
                    'checked_in_at' => ClinicClock::fromUtc(CarbonImmutable::parse($row->created_at ?? 'now'))->toDateTimeString(),
                    'doctor_id' => $row->patient_visit_id
                        ? DB::table('patient_visits')->where('id', $row->patient_visit_id)->value('doctor_id')
                        : null,
                ]);
            });
    }

    public function down(): void
    {
        /*
         * MySQL lets the composite index double as the `clinic_id` foreign
         * key's index and then refuses to drop it, so the plain FK index is
         * put back first (under the name Laravel originally gave it) when it
         * is missing. Runs first: MySQL DDL is not transactional, and a
         * failure here must not leave the other tables half rolled back.
         */
        if (! Schema::hasIndex('clinic_queues', 'clinic_queues_clinic_id_foreign')) {
            Schema::table('clinic_queues', function (Blueprint $table) {
                $table->index('clinic_id', 'clinic_queues_clinic_id_foreign');
            });
        }

        Schema::table('clinic_queues', function (Blueprint $table) {
            $table->dropIndex('cq_clinic_branch_date_index');
            $table->dropColumn(['queue_date', 'closed_at']);
        });

        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
            $table->dropConstrainedForeignId('doctor_id');
            $table->dropColumn(['checked_in_at', 'started_at', 'completed_at']);
        });

        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('patient_flow_config');
        });
    }
};
