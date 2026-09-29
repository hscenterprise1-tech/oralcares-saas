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
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['clinic_type', 'expected_revenue', 'organization_scale']);

            // Organization Info
            $table->string('organization_type')->nullable()->after('priority');
            $table->string('healthcare_specialty')->nullable()->after('organization_type');
            $table->string('organization_size')->nullable()->after('healthcare_specialty');
            $table->integer('branch_count')->default(1)->after('organization_size');
            $table->integer('expected_users')->default(1)->after('branch_count');
            $table->decimal('expected_mrr', 15, 2)->nullable()->after('expected_users');
            $table->decimal('expected_arr', 15, 2)->nullable()->after('expected_mrr');

            // Sales Info
            $table->string('next_best_action')->nullable()->after('manual_priority');
            $table->integer('probability_to_close')->default(0)->after('next_best_action');
            $table->date('expected_go_live_date')->nullable()->after('probability_to_close');

            // Business Info
            $table->string('current_software')->nullable()->after('expected_go_live_date');
            $table->boolean('migration_required')->default(false)->after('current_software');
            $table->string('deployment_preference')->nullable()->after('migration_required');
            $table->json('required_modules')->nullable()->after('deployment_preference');
            $table->json('required_integrations')->nullable()->after('required_modules');

            // Commercial Info
            $table->foreignId('selected_plan_id')->nullable()->after('required_integrations'); // Usually a foreign key to plans
            $table->string('payment_status')->nullable()->after('selected_plan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'organization_type',
                'healthcare_specialty',
                'organization_size',
                'branch_count',
                'expected_users',
                'expected_mrr',
                'expected_arr',
                'next_best_action',
                'probability_to_close',
                'expected_go_live_date',
                'current_software',
                'migration_required',
                'deployment_preference',
                'required_modules',
                'required_integrations',
                'selected_plan_id',
                'payment_status',
            ]);

            $table->string('clinic_type')->nullable();
            $table->string('organization_scale')->nullable();
            $table->decimal('expected_revenue', 15, 2)->nullable();
        });
    }
};
