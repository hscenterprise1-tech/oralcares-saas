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
        Schema::create('company_employee_roles', function (Blueprint $table) {
            $table->foreignId('company_employee_id')->constrained('company_employees')->cascadeOnDelete();
            $table->foreignId('company_role_id')->constrained('company_roles')->cascadeOnDelete();

            $table->primary(['company_employee_id', 'company_role_id'], 'co_emp_role_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_employee_roles');
    }
};
