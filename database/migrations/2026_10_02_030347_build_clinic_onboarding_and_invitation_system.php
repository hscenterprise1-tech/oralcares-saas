<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deal Won → provisioned clinic → owner invitation → resumable onboarding.
 *
 * - `clinic_provisioning_runs`: one row per provisioning request, keyed by an
 *   idempotency key, with per-stage checkpoints so a retry resumes rather
 *   than repeats.
 * - `clinic_owner_invitations`: the owner's lifecycle from created to active.
 * - `onboarding_sessions` / `onboarding_session_steps` /
 *   `onboarding_step_submissions`: the reusable onboarding engine. A session
 *   belongs to a clinic and a subject (the clinic itself, or one staff
 *   member); submissions make step saves idempotent.
 * - `clinics.specialties` / `branches.specialties`: the specialty set CRM
 *   sold, which picks the onboarding template.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clinic configuration lives in JSON columns on `clinics` (as
        // appointment_config does); `clinic_settings` was retired.
        Schema::table('clinics', function (Blueprint $table) {
            $table->json('specialties')->nullable()->after('status');
            $table->string('contact_email')->nullable()->after('legal_name');
            $table->string('contact_phone', 20)->nullable()->after('contact_email');
            $table->json('billing_config')->nullable()->after('appointment_config');
            $table->json('communication_config')->nullable()->after('billing_config');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->json('specialties')->nullable()->after('is_main');
        });

        Schema::create('clinic_provisioning_runs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 120)->unique();
            $table->string('source', 40);
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->json('completed_stages')->nullable();
            $table->string('current_stage', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'status']);
        });

        Schema::create('clinic_owner_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provisioning_run_id')->nullable()->constrained('clinic_provisioning_runs')->nullOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 30)->index();
            $table->unsignedSmallInteger('send_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamp('onboarding_started_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One owner invitation per clinic and person; resending rotates the
            // token on the same row instead of creating a second invitation.
            $table->unique(['clinic_id', 'email']);
        });

        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('subject_key', 60);
            $table->string('kind', 20);
            $table->foreignId('clinic_employee_id')->nullable()->constrained('clinic_employees')->cascadeOnDelete();
            $table->string('template_key', 60);
            $table->unsignedSmallInteger('template_version')->default(1);
            $table->string('status', 20);
            $table->string('current_step', 60)->nullable();
            $table->string('blocked_reason')->nullable();
            $table->string('status_before_block', 20)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'subject_key']);
            $table->index(['clinic_id', 'kind', 'status']);
        });

        Schema::create('onboarding_session_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_session_id')->constrained('onboarding_sessions')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('step_key', 60);
            $table->unsignedSmallInteger('position');
            $table->boolean('is_required')->default(true);
            $table->string('status', 20);
            $table->json('draft')->nullable();
            $table->json('data')->nullable();
            $table->unsignedInteger('submission_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('draft_saved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();

            $table->unique(['onboarding_session_id', 'step_key']);
            $table->index(['clinic_id', 'status']);
        });

        Schema::create('onboarding_step_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_session_step_id')->constrained('onboarding_session_steps')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('idempotency_key', 80);
            $table->string('payload_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['onboarding_session_step_id', 'idempotency_key'], 'onboarding_submission_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_step_submissions');
        Schema::dropIfExists('onboarding_session_steps');
        Schema::dropIfExists('onboarding_sessions');
        Schema::dropIfExists('clinic_owner_invitations');
        Schema::dropIfExists('clinic_provisioning_runs');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('specialties');
        });

        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn(['specialties', 'contact_email', 'contact_phone', 'billing_config', 'communication_config']);
        });
    }
};
