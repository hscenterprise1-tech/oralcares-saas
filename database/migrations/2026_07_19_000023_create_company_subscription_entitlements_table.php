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
        Schema::create('company_subscription_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_subscription_id')
                ->constrained('company_subscriptions')
                ->cascadeOnDelete()
                ->name('co_sub_ent_sub_fk');

            $table->foreignId('company_feature_id')
                ->constrained('company_features')
                ->cascadeOnDelete()
                ->name('co_sub_ent_feat_fk');

            $table->boolean('is_enabled')->default(true);
            $table->integer('usage_limit')->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->timestamps();

            $table->unique(['company_subscription_id', 'company_feature_id'], 'co_sub_ent_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_subscription_entitlements');
    }
};
