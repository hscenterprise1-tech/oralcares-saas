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
        Schema::create('company_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number');
            $table->integer('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('company_quotations')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->foreignId('prepared_by_id')->constrained('company_employees')->cascadeOnDelete();
            $table->foreignId('assigned_id')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0.00);
            $table->decimal('discount', 15, 2)->default(0.00);
            $table->decimal('tax', 15, 2)->default(0.00);
            $table->decimal('grand_total', 15, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['quotation_number', 'version']);
            $table->index('quotation_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_quotations');
    }
};
