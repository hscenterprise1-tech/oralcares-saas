<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4E.2: the treatment is the care context for physiotherapy.
 *
 * Additive only; nothing is dropped but two unique indexes, each replaced by
 * the correct, wider one in the same migration (checked free of duplicates
 * before it ran):
 *
 * - patient_treatments: a primary diagnosis reference and a goal.
 * - physio_care_plans: revisions (revision, supersedes_id, effective_from,
 *   status `superseded`), and at most ONE active plan PER TREATMENT, enforced
 *   by a unique index on a generated column (was: one per patient, in code).
 * - physio_sessions: lifecycle (draft → completed → reversed); uniqueness per
 *   consultation AND treatment, so one visit holds several treatments'
 *   sessions; numbers unique within a treatment.
 * - physio_session_exercises: what was actually done, against the plan.
 * - session_package_usages: a signed ledger (consume / reversal / adjustment)
 *   with an idempotency key; a session may appear in it more than once.
 * - patient_session_packages: renewal link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->foreignId('primary_diagnosis_id')->nullable()->after('encounter_id')->constrained('clinical_diagnoses')->nullOnDelete();
            $table->text('goal')->nullable()->after('title');
        });

        Schema::table('physio_care_plans', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('patient_treatment_id');
            $table->foreignId('supersedes_id')->nullable()->after('revision')->constrained('physio_care_plans')->nullOnDelete();
            $table->date('effective_from')->nullable()->after('starts_on');
            // Non-null only while active: the unique index below then allows one active plan per treatment.
            $table->unsignedBigInteger('active_treatment_key')->nullable()->virtualAs("case when status = 'active' then patient_treatment_id end");
            $table->unique('active_treatment_key', 'physio_care_plans_one_active_per_treatment');
        });
        DB::table('physio_care_plans')->whereNull('effective_from')->update(['effective_from' => DB::raw('starts_on')]);

        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->string('status', 12)->default('draft')->after('session_number');
            $table->timestamp('completed_at')->nullable()->after('status');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('completed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            // One session per treatment per consultation (was: one per consultation).
            $table->unique(['encounter_id', 'patient_treatment_id'], 'physio_sessions_encounter_treatment_unique');
            $table->unique(['patient_treatment_id', 'session_number'], 'physio_sessions_treatment_number_unique');
        });
        // Sessions recorded before this had already been punched: they count as completed.
        DB::table('physio_sessions')->update(['status' => 'completed', 'completed_at' => DB::raw('created_at')]);
        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->dropUnique('physio_sessions_encounter_id_unique');
        });

        Schema::create('physio_session_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('physio_session_id')->constrained('physio_sessions')->restrictOnDelete();
            // The prescribed exercise, when there was one; null for an exercise added in the session.
            $table->foreignId('physio_exercise_id')->nullable()->constrained('physio_exercises')->restrictOnDelete();
            $table->string('name', 150); // snapshot: renaming the plan's exercise never rewrites history
            $table->string('status', 12); // performed | skipped
            $table->unsignedSmallInteger('sets')->nullable();
            $table->unsignedSmallInteger('reps')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('intensity', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['clinic_id', 'physio_session_id'], 'physio_session_exercises_session_index');
            $table->unique(['physio_session_id', 'physio_exercise_id'], 'physio_session_exercises_prescribed_unique');
        });

        Schema::table('session_package_usages', function (Blueprint $table) {
            $table->string('entry_type', 12)->default('consume')->after('physio_session_id');
            // Effect on the remaining balance: consume -1, reversal +1, adjustment ±n.
            $table->integer('quantity')->default(-1)->after('entry_type');
            $table->string('reason', 500)->nullable()->after('quantity');
            $table->string('entry_key', 80)->nullable()->after('reason');
            $table->foreignId('created_by')->nullable()->after('used_by')->constrained('users')->nullOnDelete();
            $table->index('physio_session_id', 'session_package_usages_session_index');
            $table->unique('entry_key', 'session_package_usages_entry_key_unique');
            $table->index(['clinic_id', 'package_id', 'entry_type'], 'session_package_usages_package_index');
        });
        Schema::table('session_package_usages', function (Blueprint $table) {
            // A session can now be consumed, reversed and consumed again; adjustments have no session.
            $table->dropUnique('session_package_usages_physio_session_id_unique');
            $table->unsignedBigInteger('physio_session_id')->nullable()->change();
        });
        // Earlier usages are each a session's first consumption.
        DB::table('session_package_usages')->whereNull('entry_key')->whereNotNull('physio_session_id')->orderBy('id')
            ->each(fn ($usage) => DB::table('session_package_usages')->where('id', $usage->id)
                ->update(['entry_key' => "session:{$usage->physio_session_id}:consume:1"]));

        Schema::table('patient_session_packages', function (Blueprint $table) {
            $table->foreignId('renews_package_id')->nullable()->after('patient_treatment_id')->constrained('patient_session_packages')->nullOnDelete();
            // Cache of the ledger's adjustments (like `sessions_used` is of its consumptions); never the source of truth.
            $table->integer('sessions_adjusted')->default(0)->after('sessions_used');
        });
    }

    public function down(): void
    {
        // The earlier schema holds one usage per session and one session per consultation. Stop
        // before changing anything (MySQL cannot undo a half-run rollback) rather than delete
        // ledger entries or sessions it cannot represent.
        $ledgerOnly = DB::table('session_package_usages')->where(fn ($q) => $q->where('entry_type', '!=', 'consume')->orWhereNull('physio_session_id'))->exists()
            || DB::table('session_package_usages')->select('physio_session_id')->groupBy('physio_session_id')->havingRaw('count(*) > 1')->exists();
        $sharedVisits = DB::table('physio_sessions')->select('encounter_id')->groupBy('encounter_id')->havingRaw('count(*) > 1')->exists();
        if ($ledgerOnly || $sharedVisits) {
            throw new RuntimeException('Cannot roll back: reversals, adjustments or several sessions in one consultation exist, which the earlier schema cannot hold.');
        }

        Schema::table('patient_session_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renews_package_id');
            $table->dropColumn('sessions_adjusted');
        });
        // Back to one usage per session. Fails (rather than deleting anything) while the ledger
        // holds reversals or adjustments, which the earlier schema cannot represent.
        Schema::table('session_package_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('physio_session_id')->nullable(false)->change();
            $table->unique('physio_session_id', 'session_package_usages_physio_session_id_unique');
        });
        // MySQL let the package index below stand in for the clinic foreign key's own index.
        if (DB::getDriverName() === 'mysql' && ! Schema::hasIndex('session_package_usages', 'session_package_usages_clinic_id_foreign')) {
            Schema::table('session_package_usages', fn (Blueprint $table) => $table->index('clinic_id', 'session_package_usages_clinic_id_foreign'));
        }
        Schema::table('session_package_usages', function (Blueprint $table) {
            $table->dropIndex('session_package_usages_session_index');
            $table->dropUnique('session_package_usages_entry_key_unique');
            $table->dropIndex('session_package_usages_package_index');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['entry_type', 'quantity', 'reason', 'entry_key']);
        });
        Schema::dropIfExists('physio_session_exercises');
        Schema::table('physio_sessions', function (Blueprint $table) {
            $table->unique('encounter_id', 'physio_sessions_encounter_id_unique');
            $table->dropUnique('physio_sessions_encounter_treatment_unique');
            $table->dropUnique('physio_sessions_treatment_number_unique');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn(['status', 'completed_at', 'reversed_at', 'reversal_reason']);
        });
        Schema::table('physio_care_plans', function (Blueprint $table) {
            $table->dropUnique('physio_care_plans_one_active_per_treatment');
            $table->dropColumn('active_treatment_key');
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropColumn(['revision', 'effective_from']);
        });
        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_diagnosis_id');
            $table->dropColumn('goal');
        });
    }
};
