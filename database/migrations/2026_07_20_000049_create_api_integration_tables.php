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
        // 1. API Tokens Table (For Lightweight API authentication)
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_employee_id')->constrained('clinic_employees')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('name');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // 2. API Audit Logs Table
        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_employee_id')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->string('endpoint');
            $table->string('method');
            $table->string('ip_address')->nullable();
            $table->integer('status_code');
            $table->json('payload_summary')->nullable(); // Non-sensitive details only
            $table->timestamp('created_at')->useCurrent();
        });

        // 3. Webhook Subscriptions Table
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('event_type'); // PatientCreated, BillingCompleted, WorkflowCompleted, InventoryLow, LaboratoryCompleted
            $table->string('target_url');
            $table->string('secret_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('api_audit_logs');
        Schema::dropIfExists('api_tokens');
    }
};
