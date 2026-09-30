<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The platform's security trail: sign-ins, failures, lockouts, password
     * and invitation events, access to patient documents, and changes to a
     * clinic's or member's standing. Rows are only ever inserted; the model
     * refuses updates and deletes. The actor, clinic and branch columns carry
     * no foreign keys on purpose, so deleting a user, clinic or branch can
     * never cascade into, or be blocked by, the evidence of what they did.
     */
    public function up(): void
    {
        Schema::create('security_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['clinic_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_audit_logs');
    }
};
