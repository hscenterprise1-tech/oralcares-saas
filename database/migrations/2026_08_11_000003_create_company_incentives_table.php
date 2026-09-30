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
        Schema::create('company_incentives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('company_employees')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->date('awarded_date');
            $table->foreignId('related_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_incentives');
    }
};
