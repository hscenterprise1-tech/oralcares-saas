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
        // 1. Add catalog reference to treatment_records
        Schema::table('treatment_records', function (Blueprint $table) {
            $table->foreignId('clinical_procedure_id')
                ->nullable()
                ->constrained('clinical_procedures')
                ->nullOnDelete()
                ->name('tx_rec_proc_fk');
        });

        // 2. Add treatment reference to billing_references
        Schema::table('billing_references', function (Blueprint $table) {
            $table->foreignId('treatment_record_id')
                ->nullable()
                ->constrained('treatment_records')
                ->nullOnDelete()
                ->name('bill_ref_tx_rec_fk');
        });

        // 3. Patient Tooth Status Histories
        Schema::create('patient_tooth_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->integer('tooth_number');
            $table->string('status');
            $table->text('notes')->nullable();

            $table->foreignId('treatment_record_id')
                ->nullable()
                ->constrained('treatment_records')
                ->nullOnDelete()
                ->name('pt_tooth_hist_tx_rec_fk');

            $table->foreignId('updated_by_doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('pt_tooth_hist_doc_fk');

            $table->timestamps();
        });

        // 4. Treatment Progress Notes
        Schema::create('treatment_progress_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_record_id')->constrained('treatment_records')->cascadeOnDelete();
            $table->string('event'); // started, paused, resumed, completed, aborted
            $table->text('notes')->nullable();
            $table->timestamp('logged_at')->useCurrent();
        });

        // 5. Treatment Materials
        Schema::create('treatment_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_record_id')->constrained('treatment_records')->cascadeOnDelete();
            $table->string('material_name');
            $table->decimal('quantity', 8, 2)->default(1.00);
            $table->string('batch_number')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('treatment_materials');
        Schema::dropIfExists('treatment_progress_notes');
        Schema::dropIfExists('patient_tooth_status_histories');

        Schema::table('billing_references', function (Blueprint $table) {
            $table->dropForeign(['treatment_record_id']);
            $table->dropColumn('treatment_record_id');
        });

        Schema::table('treatment_records', function (Blueprint $table) {
            $table->dropForeign(['clinical_procedure_id']);
            $table->dropColumn('clinical_procedure_id');
        });
    }
};
