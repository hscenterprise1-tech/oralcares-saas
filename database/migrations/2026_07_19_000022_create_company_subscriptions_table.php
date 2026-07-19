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
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('company_opportunities')->nullOnDelete();
            $table->foreignId('company_plan_id')->nullable()->constrained('company_plans')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            // Resource limits copied from Plan or customized
            $table->integer('max_users')->default(5);
            $table->integer('max_doctors')->default(3);
            $table->integer('max_branches')->default(1);
            $table->integer('storage_limit_gb')->default(10);

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
    }
};
