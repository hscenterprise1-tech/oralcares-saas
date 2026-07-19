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
        Schema::create('company_employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('company_departments')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('company_teams')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->string('designation')->nullable();
            $table->string('employment_status')->default('active');
            $table->date('joining_date')->nullable();
            $table->string('profile_photo')->nullable();
            $table->string('timezone')->nullable();
            $table->string('language')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_employees');
    }
};
