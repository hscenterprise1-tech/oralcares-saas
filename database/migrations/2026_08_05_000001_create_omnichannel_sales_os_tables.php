<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
            $table->string('message_sid')->nullable()->index();
            $table->enum('direction', ['inbound', 'outbound'])->default('inbound');
            $table->string('sender_phone');
            $table->text('message_body');
            $table->string('media_url')->nullable();
            $table->string('template_name')->nullable();
            $table->enum('sentiment', ['positive', 'neutral', 'negative'])->default('neutral');
            $table->enum('buying_intent', ['high', 'medium', 'low'])->default('medium');
            $table->enum('status', ['sent', 'delivered', 'read'])->default('sent');
            $table->timestamps();
        });

        Schema::create('lead_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('call_duration_seconds')->default(0);
            $table->enum('disposition', [
                'interested',
                'follow_up',
                'busy',
                'not_interested',
                'wrong_number',
                'demo_scheduled',
            ])->default('interested');
            $table->text('notes')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->onDelete('cascade');
            $table->string('message_id')->nullable()->index();
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->string('subject');
            $table->text('body_html');
            $table->boolean('is_opened')->default(false);
            $table->integer('open_count')->default(0);
            $table->integer('click_count')->default(0);
            $table->boolean('has_attachment')->default(false);
            $table->timestamps();
        });

        Schema::create('lead_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_name');
            $table->string('event_trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_automation_rules');
        Schema::dropIfExists('lead_email_threads');
        Schema::dropIfExists('lead_call_logs');
        Schema::dropIfExists('lead_whatsapp_messages');
    }
};
