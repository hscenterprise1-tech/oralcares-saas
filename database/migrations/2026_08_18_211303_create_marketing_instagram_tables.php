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
        // 1. Instagram Business Account connection (mirrors marketing_whatsapp_accounts)
        Schema::create('marketing_instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('instagram_business_account_id')->nullable();
            $table->string('facebook_page_id')->nullable();
            $table->string('username')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->string('status')->default('disconnected')->index(); // connected, disconnected, error
            $table->timestamp('last_connected_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. AI generation provider settings (org-scoped, reusable beyond Instagram later)
        Schema::create('marketing_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('provider')->default('openai');
            $table->text('api_key_encrypted')->nullable();
            $table->string('image_model')->default('dall-e-3');
            $table->string('text_model')->default('gpt-4o-mini');
            $table->timestamps();

            $table->unique(['organization_id', 'provider']);
        });

        // 3. Instagram post queue: AI-drafted -> human review -> approved -> published
        Schema::create('marketing_instagram_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('instagram_account_id')->nullable()->constrained('marketing_instagram_accounts')->nullOnDelete();
            $table->string('topic')->nullable(); // what the admin asked the AI to create a post about
            $table->text('caption')->nullable();
            $table->string('image_path')->nullable(); // local storage disk path
            $table->string('image_url')->nullable(); // publicly reachable URL used for publishing
            $table->string('status')->default('generating')->index();
            // generating, pending_approval, approved, scheduled, publishing, published, rejected, failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('provider_media_id')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_instagram_posts');
        Schema::dropIfExists('marketing_ai_settings');
        Schema::dropIfExists('marketing_instagram_accounts');
    }
};
