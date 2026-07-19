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
        // 1. Workflow Definitions Table
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('version')->default('1.0');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Workflow Stages Table
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('floor')->nullable();
            $table->string('department')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });

        // 3. Workflow Transitions Table
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();

            $table->foreignId('from_stage_id')
                ->constrained('workflow_stages')
                ->cascadeOnDelete()
                ->name('wf_trans_from_stage_fk');

            $table->foreignId('to_stage_id')
                ->constrained('workflow_stages')
                ->cascadeOnDelete()
                ->name('wf_trans_to_stage_fk');

            $table->json('rules')->nullable();
            $table->json('validation_rules')->nullable();
            $table->timestamps();
        });

        // 4. Workflow Runs (Orchestrator instance per encounter)
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();

            $table->foreignId('current_stage_id')
                ->nullable()
                ->constrained('workflow_stages')
                ->nullOnDelete()
                ->name('wf_run_curr_stage_fk');

            $table->string('status')->default('active'); // active, completed, cancelled
            $table->timestamps();

            $table->unique('encounter_id');
        });

        // 5. Workflow Histories Table
        Schema::create('workflow_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();

            $table->foreignId('from_stage_id')
                ->nullable()
                ->constrained('workflow_stages')
                ->nullOnDelete()
                ->name('wf_hist_from_stage_fk');

            $table->foreignId('to_stage_id')
                ->nullable()
                ->constrained('workflow_stages')
                ->nullOnDelete()
                ->name('wf_hist_to_stage_fk');

            $table->foreignId('performed_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->string('action_type'); // move, hold, return, skip, complete, cancel, escalate
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_histories');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflow_definitions');
    }
};
