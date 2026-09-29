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
        // 1. Marketing WhatsApp Templates
        Schema::create('marketing_whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('marketing_whatsapp_accounts')->cascadeOnDelete();
            $table->string('provider_template_id')->nullable();
            $table->string('name')->index();
            $table->string('language')->default('en');
            $table->string('category')->default('MARKETING')->index(); // MARKETING, UTILITY, AUTHENTICATION
            $table->string('status')->default('draft')->index(); // draft, pending, approved, rejected, paused
            $table->text('body');
            $table->string('header_type')->nullable(); // text, image, document
            $table->text('header_content')->nullable();
            $table->string('footer')->nullable();
            $table->json('variables')->nullable();
            $table->json('provider_payload')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['whatsapp_account_id', 'name', 'language'], 'mktg_wa_tmpl_acct_name_lang_unique');
        });

        // 2. Marketing Campaigns
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->constrained('marketing_whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('audience_id')->constrained('marketing_audiences')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('marketing_whatsapp_templates')->cascadeOnDelete();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index(); // draft, scheduled, preparing, sending, paused, completed, cancelled, failed
            $table->json('variable_mapping')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 3. Marketing Campaign Recipients (Deterministic Recipient Snapshot)
        Schema::create('marketing_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('marketing_contacts')->nullOnDelete();
            $table->string('phone');
            $table->string('normalized_phone')->index();
            $table->string('status')->default('pending')->index(); // pending, queued, sent, delivered, read, failed, skipped, replied
            $table->json('personalized_payload')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->unsignedBigInteger('crm_lead_id')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id'], 'mktg_cmp_rcpt_cmp_cntct_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_recipients');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_whatsapp_templates');
    }
};
