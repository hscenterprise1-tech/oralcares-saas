<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a clinic spends: categories, vendors, each expense (with its receipt,
 * approval and payment) and recurring expenses that raise the next one when
 * due. Every row belongs to one clinic; nothing here is shared with the
 * company's own `company_expenses`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'name']);
        });

        Schema::create('clinic_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('tax_number', 30)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['clinic_id', 'name']);
        });

        Schema::create('clinic_recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('category_id')->constrained('clinic_expense_categories')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('clinic_vendors')->nullOnDelete();
            $table->string('title', 160);
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->string('frequency', 12);
            $table->date('next_due_on');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'next_due_on']);
            $table->index(['clinic_id', 'is_active']);
        });

        Schema::create('clinic_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('clinic_departments')->nullOnDelete();
            $table->foreignId('category_id')->constrained('clinic_expense_categories')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('clinic_vendors')->nullOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->constrained('clinic_recurring_expenses')->nullOnDelete();
            $table->string('title', 160);
            $table->decimal('amount', 12, 2);
            $table->date('spent_on');
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            // pending (waiting for approval) → approved | rejected; approved → paid.
            $table->string('status', 12)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->char('attachment_sha256', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'spent_on']);
            $table->index(['clinic_id', 'status', 'spent_on']);
            $table->index(['clinic_id', 'category_id', 'spent_on']);
            $table->unique(['recurring_expense_id', 'spent_on'], 'clinic_expense_recurring_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_expenses');
        Schema::dropIfExists('clinic_recurring_expenses');
        Schema::dropIfExists('clinic_vendors');
        Schema::dropIfExists('clinic_expense_categories');
    }
};
