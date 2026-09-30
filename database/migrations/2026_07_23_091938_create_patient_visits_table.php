<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the patient_visits table. Every physical visit to the clinic
     * creates one row here. The UHID (patient) stays constant; the case
     * number (yearly) is generated per visit.
     */
    public function up(): void
    {
        Schema::create('patient_visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('clinic_departments')
                ->nullOnDelete()
                ->name('pv_dept_fk');

            $table->foreignId('doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('pv_doctor_fk');

            $table->string('registration_type')->default('new'); // new | repeat
            $table->date('visit_date');
            $table->string('queue_status')->default('not_queued');  // not_queued, in_queue, with_doctor, completed
            $table->string('visit_status')->default('registered');  // registered, checked_in, in_queue, with_doctor, completed, cancelled
            $table->boolean('registration_fee_collected')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['clinic_id', 'visit_date']);
            $table->index(['patient_id', 'visit_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_visits');
    }
};
