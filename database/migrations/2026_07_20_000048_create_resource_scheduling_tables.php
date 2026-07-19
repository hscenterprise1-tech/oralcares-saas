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
        // 1. Generic Resources Table
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('clinic_employees')->cascadeOnDelete();
            $table->string('name');
            $table->string('resource_type'); // doctor, assistant, receptionist, lab_technician, xray_technician, chair, operatory, xray_room, lab_room, custom
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // 2. Availability Table (Recurring availability per Resource)
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->integer('day_of_week'); // 0 = Sunday, 1 = Monday, ..., 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });

        // 3. Shift Management Table
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('name'); // Morning, Afternoon, Evening, Night, Split Shift, Custom
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });

        // 4. Leave Management Table
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->string('leave_type'); // casual, sick, vacation, emergency, custom
            $table->string('status')->default('requested'); // requested, approved, rejected, cancelled
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        // 5. Clinic Operating Hours Table
        Schema::create('clinic_operating_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->integer('day_of_week'); // 0-6
            $table->time('open_time');
            $table->time('close_time');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        // 6. Resource Allocations Table
        Schema::create('resource_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('allocated_to_type'); // encounter, workflow_stage, chair_assignment, xray, lab
            $table->unsignedBigInteger('allocated_to_id')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_allocations');
        Schema::dropIfExists('clinic_operating_hours');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('availabilities');
        Schema::dropIfExists('resources');
    }
};
