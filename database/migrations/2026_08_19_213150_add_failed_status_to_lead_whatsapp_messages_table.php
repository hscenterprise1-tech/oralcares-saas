<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Widened from a MySQL enum('sent','delivered','read') to a plain string
     * so a real send failure (e.g. no approved WhatsApp template configured
     * yet) can be recorded honestly as 'failed' instead of being forced into
     * a fake 'sent' status.
     */
    public function up(): void
    {
        Schema::table('lead_whatsapp_messages', function (Blueprint $table) {
            $table->string('status', 20)->default('sent')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lead_whatsapp_messages', function (Blueprint $table) {
            $table->enum('status', ['sent', 'delivered', 'read'])->default('sent')->change();
        });
    }
};
