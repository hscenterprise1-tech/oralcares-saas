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
        Schema::create('company_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('company_opportunities')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('pending');
            $table->json('modules_enabled')->nullable();
            $table->integer('max_users')->default(5);
            $table->integer('max_branches')->default(1);
            $table->integer('max_doctors')->default(3);
            $table->integer('storage_limit_gb')->default(10);
            $table->json('feature_flags')->nullable();
            $table->foreignId('trial_owner_id')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_trials');
    }
};
