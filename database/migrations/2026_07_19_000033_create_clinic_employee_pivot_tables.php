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
        // 1. clinic_employee_branches
        Schema::create('clinic_employee_branches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_employee_id')
                ->constrained('clinic_employees')
                ->cascadeOnDelete()
                ->name('cl_emp_br_emp_fk');

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete()
                ->name('cl_emp_br_br_fk');

            $table->unique(['clinic_employee_id', 'branch_id']);
        });

        // 2. clinic_employee_departments
        Schema::create('clinic_employee_departments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_employee_id')
                ->constrained('clinic_employees')
                ->cascadeOnDelete()
                ->name('cl_emp_dept_emp_fk');

            $table->foreignId('clinic_department_id')
                ->constrained('clinic_departments')
                ->cascadeOnDelete()
                ->name('cl_emp_dept_dept_fk');

            $table->unique(['clinic_employee_id', 'clinic_department_id'], 'cl_emp_dept_unique');
        });

        // 3. clinic_employee_rooms
        Schema::create('clinic_employee_rooms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_employee_id')
                ->constrained('clinic_employees')
                ->cascadeOnDelete()
                ->name('cl_emp_room_emp_fk');

            $table->foreignId('clinic_room_id')
                ->constrained('clinic_rooms')
                ->cascadeOnDelete()
                ->name('cl_emp_room_room_fk');

            $table->unique(['clinic_employee_id', 'clinic_room_id'], 'cl_emp_room_unique');
        });

        // 4. clinic_employee_roles
        Schema::create('clinic_employee_roles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clinic_employee_id')
                ->constrained('clinic_employees')
                ->cascadeOnDelete()
                ->name('cl_emp_role_emp_fk');

            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete()
                ->name('cl_emp_role_role_fk');

            $table->unique(['clinic_employee_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_employee_roles');
        Schema::dropIfExists('clinic_employee_rooms');
        Schema::dropIfExists('clinic_employee_departments');
        Schema::dropIfExists('clinic_employee_branches');
    }
};
