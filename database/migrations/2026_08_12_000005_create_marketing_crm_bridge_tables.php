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
        // 1. Add crm_lead_id to marketing_whatsapp_conversations
        Schema::table('marketing_whatsapp_conversations', function (Blueprint $table) {
            $table->foreignId('crm_lead_id')->nullable()->after('contact_id')->constrained('leads')->nullOnDelete();
        });

        // 2. Add marketing_contact_id, marketing_campaign_id, and normalized_phone to leads
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('marketing_contact_id')->nullable()->after('organization_id')->constrained('marketing_contacts')->nullOnDelete();
            $table->foreignId('marketing_campaign_id')->nullable()->after('marketing_contact_id')->constrained('marketing_campaigns')->nullOnDelete();
            $table->string('normalized_phone')->nullable()->index()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['marketing_contact_id']);
            $table->dropForeign(['marketing_campaign_id']);
            $table->dropColumn(['marketing_contact_id', 'marketing_campaign_id', 'normalized_phone']);
        });

        Schema::table('marketing_whatsapp_conversations', function (Blueprint $table) {
            $table->dropForeign(['crm_lead_id']);
            $table->dropColumn(['crm_lead_id']);
        });
    }
};
