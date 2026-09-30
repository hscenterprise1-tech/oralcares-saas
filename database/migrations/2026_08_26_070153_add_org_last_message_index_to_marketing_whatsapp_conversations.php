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
        // The conversation list orders by last_message_at, scoped to one
        // organization, on every load. Without this, that ORDER BY sorts
        // the full table for the tenant rather than walking an index —
        // invisible at the row counts this table has today, real cost once
        // a tenant's conversation history grows.
        Schema::table('marketing_whatsapp_conversations', function (Blueprint $table) {
            $table->index(['organization_id', 'last_message_at'], 'mktg_wa_conv_org_last_msg_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_whatsapp_conversations', function (Blueprint $table) {
            $table->dropIndex('mktg_wa_conv_org_last_msg_idx');
        });
    }
};
