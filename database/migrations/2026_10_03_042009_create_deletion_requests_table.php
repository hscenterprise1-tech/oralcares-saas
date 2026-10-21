<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destructive-action governance: a request to remove, void or archive a
 * record, decided by the clinic owner. Nothing is removed until the owner
 * approves; the request is the permanent record of who asked, why, who
 * decided and what was done.
 *
 * `open_key` is set only while a request is undecided, so the unique index
 * allows one open request per record and action without blocking history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action_type', 60);
            $table->string('target_type', 60);
            $table->unsignedBigInteger('target_id');
            $table->string('target_label', 255);
            $table->string('target_fingerprint', 64);
            $table->text('reason');
            $table->text('note')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('open_key', 191)->nullable()->unique();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->json('thread')->nullable();
            $table->boolean('direct')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'status', 'created_at'], 'deletion_requests_queue_index');
            $table->index(['clinic_id', 'target_type', 'target_id'], 'deletion_requests_target_index');
            $table->index(['clinic_id', 'patient_id', 'status'], 'deletion_requests_patient_index');
            $table->index(['clinic_id', 'requester_user_id', 'status'], 'deletion_requests_requester_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_requests');
    }
};
