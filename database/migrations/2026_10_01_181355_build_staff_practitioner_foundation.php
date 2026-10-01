<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff and practitioner management:
     *
     * - staff records gain an employment profile (type, photo, primary branch,
     *   dates, notes) and branch placements can be paused;
     * - practitioner profiles gain profession, a primary specialty from the
     *   organisation's specialty catalogue, and professional registration;
     * - structured qualifications, the services each practitioner provides,
     *   and the leave workflow's decision trail;
     * - staff documents with a per-clinic catalogue of document types and a
     *   checklist of which types each staff type needs.
     */
    public function up(): void
    {
        Schema::table('clinic_employees', function (Blueprint $table) {
            $table->string('staff_type', 40)->nullable()->after('status');
            $table->foreignId('primary_branch_id')->nullable()->after('staff_type')->constrained('branches')->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('joining_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            // Not every staff member has an email (assistants, lab staff);
            // it stays unique per clinic when given.
            $table->string('email')->nullable()->change();
        });

        Schema::table('clinic_employee_branches', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->string('profession', 80)->nullable()->after('clinic_employee_id');
            $table->foreignId('primary_specialty_id')->nullable()->after('specialty')->constrained('clinical_specialties')->nullOnDelete();
            $table->string('registration_authority', 150)->nullable()->after('registration_number');
            $table->string('registration_state', 80)->nullable()->after('registration_authority');
            $table->string('registration_type', 40)->nullable()->after('registration_state');
            $table->date('registration_date')->nullable()->after('registration_type');
            $table->date('registration_expires_at')->nullable()->after('registration_date');
            // Unknown experience is not zero years.
            $table->integer('experience_years')->nullable()->default(null)->change();
        });

        Schema::create('staff_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            // practitioner | staff | all: who the type is offered for.
            $table->string('audience', 20)->default('all');
            $table->boolean('has_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['clinic_id', 'key']);
        });

        Schema::create('staff_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('staff_document_type_id')->constrained('staff_document_types')->cascadeOnDelete();
            $table->string('staff_type', 40);
            // required | optional | not_applicable
            $table->string('requirement', 20);
            $table->timestamps();

            $table->unique(['staff_document_type_id', 'staff_type'], 'staff_document_requirement_unique');
        });

        Schema::create('staff_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->foreignId('staff_document_type_id')->constrained('staff_document_types')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('mime_type', 100)->nullable();
            $table->string('file_sha256', 64);
            $table->string('scanner', 40);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            // pending_verification | verified | rejected | replaced
            $table->string('status', 30)->default('pending_verification');
            $table->text('notes')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->boolean('replacement_requested')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'clinic_employee_id', 'status']);
            $table->index(['clinic_id', 'expires_on']);
        });

        Schema::create('practitioner_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('doctor_profile_id')->constrained('doctor_profiles')->cascadeOnDelete();
            $table->string('qualification', 120);
            // bachelor | master | doctorate | diploma | fellowship | certification | other
            $table->string('degree_type', 30);
            $table->string('specialization', 120)->nullable();
            $table->string('institution', 160)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('country', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->foreignId('staff_document_id')->nullable()->constrained('staff_documents')->nullOnDelete();
            // unverified | verified | rejected
            $table->string('verification_status', 20)->default('unverified');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['doctor_profile_id', 'position']);
        });

        Schema::create('practitioner_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->foreignId('clinical_procedure_id')->constrained('clinical_procedures')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinic_employee_id', 'clinical_procedure_id'], 'practitioner_service_unique');
            $table->index(['clinic_id', 'clinical_procedure_id']);
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->text('note')->nullable()->after('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->index(['clinic_id', 'status', 'start_date'], 'leaves_clinic_status_start_index');
        });
    }

    public function down(): void
    {
        // MySQL may let the new composite indexes serve the clinic foreign
        // keys and drop their own; give any back before the composites go.
        foreach (['leaves'] as $table) {
            if (! Schema::hasIndex($table, "{$table}_clinic_id_foreign")) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('clinic_id', "{$table}_clinic_id_foreign"));
            }
        }

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndex('leaves_clinic_status_start_index');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['note', 'decided_at', 'decision_note']);
        });
        Schema::dropIfExists('practitioner_services');
        Schema::dropIfExists('practitioner_qualifications');
        Schema::dropIfExists('staff_documents');
        Schema::dropIfExists('staff_document_requirements');
        Schema::dropIfExists('staff_document_types');
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_specialty_id');
            $table->dropColumn(['profession', 'registration_authority', 'registration_state', 'registration_type', 'registration_date', 'registration_expires_at']);
        });
        Schema::table('clinic_employee_branches', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('clinic_employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_branch_id');
            $table->dropColumn(['staff_type', 'photo_path', 'date_of_birth', 'gender', 'joining_date', 'notes', 'status_changed_at']);
        });
    }
};
