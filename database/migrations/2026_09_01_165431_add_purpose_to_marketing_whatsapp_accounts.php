<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give each WhatsApp number a purpose and a name people recognise.
 *
 * Several numbers were already supported, but nothing said what any of them was
 * for. That made two things impossible: routing a message out of the right line,
 * and telling — at a glance in the inbox — whether an incoming reply is a
 * marketing enquiry or a clinic asking for help.
 *
 * `purpose` drives behaviour. `label` is only for humans: "Main sales line"
 * reads better in a dropdown than a verified name Meta assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_whatsapp_accounts', function (Blueprint $table) {
            /*
             * Defaults to marketing because that is what every existing number
             * has in fact been used for — this table predates the idea of any
             * other purpose, so backfilling anything else would be inventing
             * history.
             */
            $table->string('purpose', 32)->default('marketing')->after('provider');
            $table->string('label')->nullable()->after('purpose');

            // Every lookup is "the number for this purpose, in this org".
            $table->index(['organization_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_whatsapp_accounts', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'purpose']);
            $table->dropColumn(['purpose', 'label']);
        });
    }
};
