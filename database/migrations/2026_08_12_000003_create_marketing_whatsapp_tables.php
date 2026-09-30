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
        // 1. WhatsApp Business Accounts
        Schema::create('marketing_whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('provider')->default('meta')->index();
            $table->string('business_account_id')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('display_phone_number')->nullable();
            $table->string('verified_name')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->text('webhook_verify_token_encrypted')->nullable();
            $table->string('status')->default('disconnected')->index(); // connected, disconnected, error
            $table->string('connection_status')->default('inactive')->index(); // active, inactive
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Webhook Event Log (Raw & Idempotent with composite unique)
        Schema::create('marketing_whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()->constrained('marketing_whatsapp_accounts')->nullOnDelete();
            $table->string('provider')->default('meta');
            $table->string('provider_event_id');
            $table->string('event_type')->index(); // message.received, message.delivered, message.read, message.failed
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status')->default('pending')->index(); // pending, processed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Composite uniqueness for multi-account safety
            $table->unique(['whatsapp_account_id', 'provider_event_id'], 'mktg_wa_evt_acct_evt_unique');
        });

        // 3. WhatsApp Conversations Thread (Scoped by organization_id + whatsapp_account_id + normalized_phone)
        Schema::create('marketing_whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('marketing_whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('marketing_contacts')->nullOnDelete();
            $table->string('phone');
            $table->string('normalized_phone')->index();
            $table->string('status')->default('open')->index(); // open, closed, archived
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->integer('unread_count')->default(0);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Composite unique constraint per account & phone
            $table->unique(['whatsapp_account_id', 'normalized_phone'], 'mktg_wa_conv_acct_phone_unique');
        });

        // 4. Normalized WhatsApp Messages (Scoped by whatsapp_account_id + provider_message_id)
        Schema::create('marketing_whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('marketing_whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('marketing_whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('marketing_contacts')->nullOnDelete();
            $table->string('provider_message_id');
            $table->string('direction')->index(); // inbound, outbound
            $table->string('message_type')->default('text')->index(); // text, template, image, document
            $table->text('body')->nullable();
            $table->string('template_name')->nullable();
            $table->string('status')->default('queued')->index(); // queued, sent, delivered, read, failed, received
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            // Composite uniqueness for provider message ID per account
            $table->unique(['whatsapp_account_id', 'provider_message_id'], 'mktg_wa_msg_acct_msg_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_whatsapp_messages');
        Schema::dropIfExists('marketing_whatsapp_conversations');
        Schema::dropIfExists('marketing_whatsapp_webhook_events');
        Schema::dropIfExists('marketing_whatsapp_accounts');
    }
};
