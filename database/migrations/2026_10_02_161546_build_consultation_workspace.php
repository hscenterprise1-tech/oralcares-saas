<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consultation workspace on the canonical clinical anchor,
 * `clinical_encounters` (ADR-001 §E).
 *
 * - The encounter holds the doctor's narrative (chief complaint, history,
 *   examination, vitals) and the follow-up, saved as a draft while the
 *   consultation runs (`lock_version` stops two screens overwriting each
 *   other), then finalised and signed: a hash of the signed content is kept,
 *   and the record never changes again.
 * - After signing, the record grows only by addenda and amendments
 *   (`clinical_encounter_addenda`), each signed by its author; an amendment
 *   keeps the text it supersedes.
 * - Diagnoses can be marked primary; performed procedures point at the
 *   clinic's own service catalogue (which billing reads); documents can be
 *   filed against the encounter they belong to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->string('specialty', 40)->nullable()->after('encounter_type');
            $table->text('chief_complaint')->nullable();
            $table->text('history')->nullable();
            $table->text('examination')->nullable();
            $table->json('vitals')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->text('follow_up_note')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('last_autosaved_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->char('signature_hash', 64)->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->unique(['id', 'clinic_id'], 'clinical_encounters_id_clinic_unique');
            $table->index(['clinic_id', 'doctor_id', 'status'], 'clinical_encounters_doctor_status_index');
            $table->index(['clinic_id', 'patient_id', 'started_at'], 'clinical_encounters_patient_started_index');
        });

        Schema::table('clinical_diagnoses', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('status');
        });

        Schema::table('patient_procedures', function (Blueprint $table) {
            $table->foreignId('clinical_procedure_id')->nullable()->after('procedure_master_id')->constrained('clinical_procedures')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1)->after('name');
        });

        Schema::table('attached_documents', function (Blueprint $table) {
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained('clinical_encounters')->nullOnDelete();
        });

        Schema::create('clinical_encounter_addenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('section', 40)->nullable();
            $table->text('content');
            $table->text('previous_content')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('author_id')->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamp('signed_at');
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->foreign(['encounter_id', 'clinic_id'], 'encounter_addenda_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('clinical_encounters');
            $table->index(['encounter_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_encounter_addenda');

        Schema::table('attached_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('encounter_id'));

        Schema::table('patient_procedures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinical_procedure_id');
            $table->dropColumn('quantity');
        });

        Schema::table('clinical_diagnoses', fn (Blueprint $table) => $table->dropColumn('is_primary'));

        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropIndex('clinical_encounters_patient_started_index');
            $table->dropIndex('clinical_encounters_doctor_status_index');
            $table->dropUnique('clinical_encounters_id_clinic_unique');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn([
                'specialty', 'chief_complaint', 'history', 'examination', 'vitals', 'follow_up_on', 'follow_up_note',
                'lock_version', 'last_autosaved_at', 'finalized_at', 'signature_hash', 'cancellation_reason',
            ]);
        });
    }
};
