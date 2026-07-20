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
        // 1. Commercial Invoices Table (For SaaS Billing, independent of patient billing)
        Schema::create('commercial_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('company_subscription_id')
                ->nullable()
                ->constrained('company_subscriptions')
                ->nullOnDelete()
                ->name('comm_inv_sub_fk');

            $table->string('invoice_number')->unique();
            $table->string('status')->default('draft'); // draft, issued, paid, overdue, cancelled
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0.00);
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('final_amount', 10, 2);
            $table->date('due_date');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // 2. Commercial Payments Table
        Schema::create('commercial_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('commercial_invoice_id')
                ->nullable()
                ->constrained('commercial_invoices')
                ->cascadeOnDelete()
                ->name('comm_pay_inv_fk');

            $table->decimal('amount', 10, 2);
            $table->string('payment_method'); // credit_card, bank_transfer, direct_debit, manually_recorded, credit_note
            $table->string('payment_type')->default('charge'); // charge, refund, credit_note
            $table->string('transaction_reference')->nullable();
            $table->timestamp('paid_at')->useCurrent();
            $table->timestamps();
        });

        // 3. Usage Metrics Table (Track SaaS usage)
        Schema::create('usage_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('metric_key'); // branches_count, active_users_count, storage_gb, api_requests_count
            $table->decimal('metric_value', 10, 2);
            $table->date('recorded_date');
            $table->timestamps();

            $table->unique(['clinic_id', 'metric_key', 'recorded_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_metrics');
        Schema::dropIfExists('commercial_payments');
        Schema::dropIfExists('commercial_invoices');
    }
};
