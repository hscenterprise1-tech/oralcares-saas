<?php

use App\Services\Patient\CaseNumberBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2 — a case number belongs to a patient for one period, not to one visit.
 *
 * - `period_key` (CY2026 / FY2026) is the span a patient keeps one number for;
 *   `counter_key` is the sequence it was drawn from (the period, or LIFETIME
 *   when a clinic turns yearly reset off). `search_key` is the number with
 *   separators stripped, for full-number search in any spelling.
 * - `(clinic, year, sequence)` can no longer be unique: after a switch from
 *   calendar to financial years, CY2026 and FY2026 both start at 1 in year
 *   2026. Uniqueness moves to `(clinic, counter_key, sequence)`.
 * - Visits point at their number through `patient_visits.case_number_id`;
 *   `case_numbers.patient_visit_id` stays as "the visit that first got it".
 * - Merged duplicates keep a full copy in `case_number_history`.
 * - `clinics.case_number_config` holds each clinic's own number format.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_numbers', function (Blueprint $table) {
            $table->string('period_key', 16)->nullable()->after('year');
            $table->string('counter_key', 16)->nullable()->after('period_key');
            $table->string('search_key', 64)->nullable()->after('case_number');
            $table->index(['clinic_id', 'search_key'], 'cn_clinic_search_key_index');
        });

        Schema::create('case_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('counter_key', 16);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['clinic_id', 'counter_key'], 'cns_clinic_counter_unique');
        });

        Schema::create('case_number_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_id');
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->unsignedBigInteger('patient_visit_id')->nullable();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->string('case_number');
            $table->string('period_key', 16)->nullable();
            $table->string('counter_key', 16)->nullable();
            $table->string('search_key', 64)->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('case_numbers')->nullOnDelete();
            $table->timestamp('original_created_at')->nullable();
            $table->timestamps();

            $table->unique('original_id', 'cnh_original_unique');
            $table->index(['clinic_id', 'search_key'], 'cnh_clinic_search_key_index');
        });

        Schema::table('patient_visits', function (Blueprint $table) {
            $table->foreignId('case_number_id')->nullable()->after('patient_id')
                ->constrained('case_numbers')->nullOnDelete();
        });

        Schema::table('clinics', function (Blueprint $table) {
            $table->json('case_number_config')->nullable()->after('patient_field_config');
        });

        app(CaseNumberBackfill::class)();

        DB::table('clinics')->whereNull('case_number_config')->update([
            'case_number_config' => json_encode([
                'active' => [
                    'prefix' => 'OPD',
                    'show_year' => true,
                    'year_style' => 'YYYY',
                    'separator' => '-',
                    'digits' => 6,
                    'start_number' => 1,
                    'reset_yearly' => true,
                ],
                'pending' => null,
            ]),
        ]);

        Schema::table('case_numbers', function (Blueprint $table) {
            $table->dropUnique('cn_clinic_year_seq_unique');
            $table->unique(['clinic_id', 'counter_key', 'sequence'], 'cn_clinic_counter_seq_unique');
            $table->unique(['clinic_id', 'patient_id', 'period_key'], 'cn_clinic_patient_period_unique');
        });
    }

    /**
     * Merged rows go back into `case_numbers` with their original ids.
     * Restoring `(clinic, year, sequence)` uniqueness can fail if numbers were
     * issued after a calendar → financial year switch; that data only exists
     * under the new rules and has to be resolved by hand first.
     */
    public function down(): void
    {
        Schema::table('case_numbers', function (Blueprint $table) {
            $table->dropUnique('cn_clinic_patient_period_unique');
            $table->dropUnique('cn_clinic_counter_seq_unique');
        });

        DB::table('case_number_history')->orderBy('id')->get()->each(function (object $row): void {
            DB::table('case_numbers')->insert([
                'id' => $row->original_id,
                'clinic_id' => $row->clinic_id,
                'patient_id' => $row->patient_id,
                'patient_visit_id' => $row->patient_visit_id,
                'year' => $row->year,
                'sequence' => $row->sequence,
                'case_number' => $row->case_number,
                'created_at' => $row->original_created_at,
                'updated_at' => $row->original_created_at,
            ]);
        });

        Schema::table('case_numbers', function (Blueprint $table) {
            $table->unique(['clinic_id', 'year', 'sequence'], 'cn_clinic_year_seq_unique');
        });

        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn('case_number_config');
        });

        Schema::table('patient_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_number_id');
        });

        Schema::dropIfExists('case_number_history');
        Schema::dropIfExists('case_number_sequences');

        Schema::table('case_numbers', function (Blueprint $table) {
            $table->dropIndex('cn_clinic_search_key_index');
            $table->dropColumn(['period_key', 'counter_key', 'search_key']);
        });
    }
};
