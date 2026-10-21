<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodontal charting: one exam per consultation, one row per tooth with
 * the six standard sites (MB, B, DB, ML, L, DL) for probing depth, gingival
 * margin, bleeding on probing and plaque, plus mobility and furcation.
 *
 * Gingival margin is in mm from the CEJ: positive = recession (apical),
 * negative = overgrowth (coronal). Attachment loss is PD + GM. The exam row
 * keeps the whole-mouth figures so the history list never re-reads sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodontal_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->unique()->constrained('clinical_encounters')->restrictOnDelete();
            $table->foreignId('examined_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->unsignedTinyInteger('teeth_examined')->default(0);
            $table->decimal('bop_percent', 5, 1)->nullable();
            $table->decimal('plaque_percent', 5, 1)->nullable();
            $table->decimal('mean_probing_depth', 4, 1)->nullable();
            $table->unsignedSmallInteger('sites_4mm_plus')->default(0);
            $table->unsignedSmallInteger('sites_6mm_plus')->default(0);
            $table->string('stage', 10)->nullable();
            $table->string('grade', 5)->nullable();
            $table->string('extent', 20)->nullable();
            $table->string('diagnosis', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'patient_id', 'created_at'], 'perio_exams_patient_index');
        });

        Schema::create('periodontal_teeth', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periodontal_exam_id')->constrained('periodontal_exams')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->unsignedTinyInteger('tooth_number');
            $table->boolean('missing')->default(false);
            $table->json('probing_depths')->nullable();
            $table->json('gingival_margins')->nullable();
            $table->json('bleeding')->nullable();
            $table->json('plaque')->nullable();
            $table->unsignedTinyInteger('mobility')->nullable();
            $table->unsignedTinyInteger('furcation')->nullable();
            $table->timestamps();

            $table->unique(['periodontal_exam_id', 'tooth_number'], 'perio_teeth_exam_tooth_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodontal_teeth');
        Schema::dropIfExists('periodontal_exams');
    }
};
