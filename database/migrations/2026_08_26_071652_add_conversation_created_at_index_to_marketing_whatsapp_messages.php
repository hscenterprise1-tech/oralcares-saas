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
        // Every conversation open paginates this table by conversation_id,
        // newest-first. The existing conversation_id FK index filters the
        // rows but leaves the ORDER BY to a filesort — fine at today's row
        // counts, real cost once a thread accumulates thousands of messages.
        Schema::table('marketing_whatsapp_messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'created_at'], 'mktg_wa_msg_conv_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex('mktg_wa_msg_conv_created_idx');
        });
    }
};
