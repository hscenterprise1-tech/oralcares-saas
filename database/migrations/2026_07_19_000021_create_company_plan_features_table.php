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
        Schema::dropIfExists('company_plan_features');
        Schema::create('company_plan_features', function (Blueprint $table) {
            $table->foreignId('company_plan_id')->constrained('company_plans')->cascadeOnDelete();
            $table->foreignId('company_feature_id')->constrained('company_features')->cascadeOnDelete();

            $table->primary(['company_plan_id', 'company_feature_id'], 'co_plan_feat_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_plan_features');
    }
};
