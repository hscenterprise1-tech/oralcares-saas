<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tamper evidence for the security trail. Each entry carries an HMAC of its
     * own content plus the previous entry's hash, so editing, deleting or
     * reordering a row directly in the database breaks the chain from that
     * point on, and `security:verify-audit-chain` reports where. `request_id`
     * ties an entry to the request that caused it.
     */
    public function up(): void
    {
        Schema::table('security_audit_logs', function (Blueprint $table) {
            $table->string('request_id', 64)->nullable()->after('user_agent');
            $table->char('prev_hash', 64)->nullable()->after('occurred_at');
            $table->char('entry_hash', 64)->nullable()->after('prev_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['request_id', 'prev_hash', 'entry_hash']);
        });
    }
};
