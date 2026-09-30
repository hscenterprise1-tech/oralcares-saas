<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number you can actually message.
 *
 * WhatsApp is the channel that carries this CRM's traffic — the timeline holds
 * twenty-five WhatsApp messages and no emails — and until now there was
 * nowhere to record which number to use. It is frequently the same as the
 * mobile, so the form offers a "same as mobile" shortcut, but it is often a
 * different line at a reception desk and guessing wrong means messaging a
 * number nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('whatsapp', 32)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('whatsapp');
        });
    }
};
