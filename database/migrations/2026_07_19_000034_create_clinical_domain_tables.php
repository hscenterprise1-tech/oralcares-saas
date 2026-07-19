<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Patients
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('patient_number')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        // 2. Patient Alerts
        Schema::create('patient_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('type'); // allergy, medical_condition, custom
            $table->string('description');
            $table->string('severity')->default('moderate'); // low, moderate, high, critical
            $table->timestamps();
        });

        // 3. Clinical Encounters
        Schema::create('clinical_encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('cl_enc_doc_fk');

            $table->string('encounter_number')->unique();
            $table->string('status')->default('in_progress'); // scheduled, in_progress, completed, cancelled
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // 4. Clinical Notes
        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('author_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('cl_note_auth_fk');

            $table->string('note_type')->default('soap');
            $table->text('content');
            $table->timestamps();
        });

        // 5. Treatment Plans
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('draft'); // draft, active, completed, suspended
            $table->timestamps();
        });

        // 6. Treatment Records (Procedures executed)
        Schema::create('treatment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('encounter_id')
                ->nullable()
                ->constrained('clinical_encounters')
                ->nullOnDelete()
                ->name('tx_rec_enc_fk');

            $table->foreignId('treatment_plan_id')
                ->nullable()
                ->constrained('treatment_plans')
                ->nullOnDelete()
                ->name('tx_rec_plan_fk');

            $table->string('code');
            $table->string('description');
            $table->string('tooth_number')->nullable();
            $table->string('status')->default('proposed'); // proposed, in_progress, completed, aborted
            $table->decimal('price', 10, 2)->default(0.00);
            $table->timestamps();
        });

        // 7. Prescriptions
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('rx_doc_fk');

            $table->string('prescription_number')->unique();
            $table->string('status')->default('active'); // active, expired, discontinued
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 8. Prescription Items
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->string('drug_name');
            $table->string('dosage');
            $table->string('frequency');
            $table->string('duration');
            $table->string('instructions')->nullable();
            $table->timestamps();
        });

        // 9. X-Ray Records
        Schema::create('xray_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('encounter_id')
                ->nullable()
                ->constrained('clinical_encounters')
                ->nullOnDelete()
                ->name('xr_rec_enc_fk');

            $table->string('xray_number')->unique();
            $table->string('file_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('ordered'); // ordered, captured, interpreted
            $table->timestamps();
        });

        // 10. Lab Orders
        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('encounter_id')
                ->nullable()
                ->constrained('clinical_encounters')
                ->nullOnDelete()
                ->name('lab_ord_enc_fk');

            $table->string('lab_order_number')->unique();
            $table->string('status')->default('ordered'); // ordered, sent_to_lab, received, cancelled
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 11. Billing References
        Schema::create('billing_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('encounter_id')
                ->nullable()
                ->constrained('clinical_encounters')
                ->nullOnDelete()
                ->name('bill_ref_enc_fk');

            $table->string('billing_reference_number')->unique();
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('status')->default('pending'); // pending, partial, paid, void
            $table->timestamps();
        });

        // 12. Consent Records
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('consent_type'); // general, surgery, xray, lab
            $table->string('status')->default('pending'); // pending, granted, revoked
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
        });

        // 13. Attached Documents
        Schema::create('attached_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->integer('file_size_bytes');
            $table->string('document_type'); // id_proof, medical_report, other
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attached_documents');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('billing_references');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('xray_records');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('treatment_records');
        Schema::dropIfExists('treatment_plans');
        Schema::dropIfExists('clinical_notes');
        Schema::dropIfExists('clinical_encounters');
        Schema::dropIfExists('patient_alerts');
        Schema::dropIfExists('patients');
    }
};
