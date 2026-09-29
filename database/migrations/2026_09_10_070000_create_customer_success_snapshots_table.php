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
        Schema::create('customer_success_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->unique()
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('health_score')->nullable();
            $table->json('health_dimensions')->nullable();
            $table->string('churn_risk', 32)->nullable();
            $table->unsignedTinyInteger('renewal_probability')->nullable();

            $table->unsignedInteger('purchased_seats')->default(0);
            $table->unsignedInteger('used_seats')->default(0);
            $table->unsignedTinyInteger('seat_utilization_pct')->nullable();

            $table->unsignedInteger('purchased_branches')->default(0);
            $table->unsignedInteger('active_branches')->default(0);
            $table->unsignedTinyInteger('branch_utilization_pct')->nullable();

            $table->boolean('has_subscription')->default(false);
            $table->foreignId('active_subscription_id')
                ->nullable()
                ->constrained('company_subscriptions')
                ->nullOnDelete();
            $table->foreignId('plan_id')
                ->nullable()
                ->constrained('company_plans')
                ->nullOnDelete();

            $table->decimal('mrr', 12, 2)->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->integer('days_to_expiry')->nullable();

            $table->string('onboarding_status', 64)->nullable();
            $table->string('onboarding_current_step', 64)->nullable();
            $table->string('stage', 64)->nullable();

            $table->boolean('expansion_opportunity')->default(false);
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->timestamps();

            // Single and composite indexes for Customer Success directory queries
            $table->index('health_score');
            $table->index('churn_risk');
            $table->index('days_to_expiry');
            $table->index('stage');
            $table->index('seat_utilization_pct');
            $table->index(['stage', 'health_score'], 'cs_snapshots_stage_health_idx');
            $table->index(['churn_risk', 'days_to_expiry'], 'cs_snapshots_risk_expiry_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_success_snapshots');
    }
};
