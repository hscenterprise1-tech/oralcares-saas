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
        Schema::create('company_quotation_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_quotation_id')->constrained('company_quotations')->cascadeOnDelete();
            $table->foreignId('company_employee_id')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->string('event');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_quotation_activities');
    }
};
