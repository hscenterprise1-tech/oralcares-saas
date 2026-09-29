<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give lead email threads a delivery lifecycle.
 *
 * The table recorded that a message existed but not whether it ever left the
 * building, so a failed send was indistinguishable from a delivered one. A rep
 * has to be able to tell those apart before they follow up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_email_threads', function (Blueprint $table) {
            $table->string('status')->default('queued')->after('direction')->index();
            $table->string('to_email')->nullable()->after('status');
            $table->foreignId('sent_by_user_id')->nullable()->after('to_email')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('sent_by_user_id');
            $table->timestamp('failed_at')->nullable()->after('sent_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
            $table->unsignedInteger('attempts')->default(0)->after('failure_reason');
        });

        // Rows that predate this column were written by the inbound/outbound
        // logger, which only ever recorded messages that had already happened.
        DB::table('lead_email_threads')->update(['status' => 'sent']);
    }

    public function down(): void
    {
        Schema::table('lead_email_threads', function (Blueprint $table) {
            $table->dropForeign(['sent_by_user_id']);
            $table->dropColumn([
                'status', 'to_email', 'sent_by_user_id',
                'sent_at', 'failed_at', 'failure_reason', 'attempts',
            ]);
        });
    }
};
