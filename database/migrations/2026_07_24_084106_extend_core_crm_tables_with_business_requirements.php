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
            $table->string('clinic_type')->nullable()->after('priority');
            $table->string('organization_scale')->nullable()->after('clinic_type');
            $table->json('product_interest')->nullable()->after('organization_scale');
            $table->string('lost_reason')->nullable()->after('status');
            $table->integer('automated_score')->default(0)->after('lead_score'); // Keeping lead_score for now
            $table->string('manual_priority')->nullable()->after('automated_score');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('clinic_type')->nullable()->after('type');
            $table->string('organization_scale')->nullable()->after('clinic_type');
            $table->integer('health_score')->default(100)->after('assigned_user_id');
            $table->integer('renewal_probability')->default(100)->after('health_score');
            $table->string('churn_risk')->default('Low')->after('renewal_probability');
            $table->boolean('expansion_opportunity')->default(false)->after('churn_risk');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
            $table->string('decision_role')->nullable()->after('job_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
            $table->dropColumn(['lead_id', 'decision_role']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'clinic_type',
                'organization_scale',
                'health_score',
                'renewal_probability',
                'churn_risk',
                'expansion_opportunity',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'clinic_type',
                'organization_scale',
                'product_interest',
                'lost_reason',
                'automated_score',
                'manual_priority',
            ]);
        });
    }
};
