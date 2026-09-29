<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record whether an inbound WhatsApp webhook proved it came from Meta.
     *
     * The endpoint is public and unauthenticated and nothing verified the
     * signature, so every event already stored arrived unchecked. Those rows
     * stay null: null means "arrived before this was recorded", which is the
     * truth about them, and is not the same as `unconfigured`.
     */
    public function up(): void
    {
        Schema::table('marketing_whatsapp_webhook_events', function (Blueprint $table) {
            $table->string('signature_result')->nullable()->after('payload');

            /* The Integrations surface lists newest-first and counts failures. */
            $table->index(['created_at'], 'mktg_wa_evt_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_whatsapp_webhook_events', function (Blueprint $table) {
            $table->dropIndex('mktg_wa_evt_created_idx');
            $table->dropColumn('signature_result');
        });
    }
};
