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
        // 1. Marketing Automations definitions
        Schema::create('marketing_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft, active, paused, archived
            $table->string('trigger_type');
            $table->json('definition');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'mkt_auto_org_stat_idx');
            $table->index(['organization_id', 'trigger_type'], 'mkt_auto_org_trig_idx');
        });

        // 2. Marketing Automation Event Log (Idempotency)
        Schema::create('marketing_automation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('event_key');
            $table->string('event_type');
            $table->foreignId('contact_id')->nullable()->constrained('marketing_contacts')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('marketing_whatsapp_conversations')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'event_key'], 'mkt_auto_evt_org_key_uniq');
            $table->index(['organization_id', 'event_type'], 'mkt_auto_evt_org_type_idx');
        });

        // 3. Marketing Automation Executions
        Schema::create('marketing_automation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained('marketing_automations')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('marketing_contacts')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('marketing_whatsapp_conversations')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->foreignId('trigger_event_id')->nullable()->constrained('marketing_automation_events')->nullOnDelete();
            $table->string('status')->default('queued'); // queued, running, waiting, completed, stopped, failed, cancelled
            $table->string('current_step_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'automation_id'], 'mkt_auto_exec_org_auto_idx');
            $table->index(['organization_id', 'status'], 'mkt_auto_exec_org_stat_idx');
            $table->index('contact_id');
            $table->index('next_run_at');
        });

        // 4. Marketing Automation Execution Steps Detail
        Schema::create('marketing_automation_execution_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('marketing_automation_executions')->cascadeOnDelete();
            $table->string('automation_step_id');
            $table->string('step_type');
            $table->string('status')->default('pending'); // pending, running, completed, failed, skipped
            $table->integer('attempts')->default(0);
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['execution_id', 'status'], 'mkt_auto_step_exec_stat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_automation_execution_steps');
        Schema::dropIfExists('marketing_automation_executions');
        Schema::dropIfExists('marketing_automation_events');
        Schema::dropIfExists('marketing_automations');
    }
};
