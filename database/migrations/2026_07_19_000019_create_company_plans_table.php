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
        Schema::create('company_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0.00);
            $table->string('billing_period')->default('monthly'); // monthly, yearly
            $table->integer('max_users')->default(5);
            $table->integer('max_doctors')->default(3);
            $table->integer('max_branches')->default(1);
            $table->integer('storage_limit_gb')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_plans');
    }
};
