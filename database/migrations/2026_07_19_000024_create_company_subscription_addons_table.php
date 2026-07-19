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
        Schema::create('company_subscription_addons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_subscription_id')
                ->constrained('company_subscriptions')
                ->cascadeOnDelete()
                ->name('co_sub_addons_sub_fk');

            $table->string('addon_type');
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_subscription_addons');
    }
};
