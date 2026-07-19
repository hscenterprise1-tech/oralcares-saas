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
        Schema::create('company_trial_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_trial_id')->constrained('company_trials')->cascadeOnDelete();
            $table->date('previous_end_date');
            $table->date('new_end_date');
            $table->text('reason')->nullable();
            $table->foreignId('extended_by_id')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_trial_extensions');
    }
};
