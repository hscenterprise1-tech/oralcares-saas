<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinic patient WhatsApp, on the platform's one WhatsApp transport.
 *
 * - `whatsapp_connections`: a business number OralCares operates for clinics.
 *   `company_shared` (clinic_id NULL) serves every clinic in Common mode;
 *   `clinic_owned` belongs to exactly one clinic. Credentials are encrypted
 *   and only OralCares staff configure them.
 * - `clinic_whatsapp_settings`: what the clinic owner decides (on/off, mode,
 *   automations, reminder timing, opt-in policy).
 * - `whatsapp_templates`: the approved templates per connection, optionally
 *   a clinic's own copy.
 * - `clinic_whatsapp_messages`: the WhatsApp delivery of a patient message
 *   (`notifications`, produced by `PatientMessaging`), always clinic-scoped,
 *   with an idempotency key, delivery state and failure reason; inbound
 *   replies too, once their clinic is known.
 * - `clinic_whatsapp_webhook_events`: raw delivery callbacks.
 *
 * None of it touches the HQ marketing WhatsApp tables (`marketing_whatsapp_*`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20);
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->restrictOnDelete();
            $table->string('label', 120);
            $table->string('display_phone_number', 30)->nullable();
            $table->string('phone_number_id', 64)->nullable()->unique();
            $table->string('business_account_id', 64)->nullable();
            $table->text('access_token')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('health_message', 255)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mode', 'status']);
            $table->index(['clinic_id', 'status']);
        });

        Schema::create('clinic_whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->unique()->constrained('clinics')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 10)->default('common');
            $table->timestamp('own_requested_at')->nullable();
            $table->string('own_request_note', 500)->nullable();
            $table->json('automations')->nullable();
            $table->unsignedSmallInteger('reminder_lead_hours')->default(24);
            $table->boolean('require_opt_in')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['enabled', 'clinic_id']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_connection_id')->constrained('whatsapp_connections')->cascadeOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->restrictOnDelete();
            $table->string('automation_key', 60);
            $table->string('name', 120);
            $table->string('language', 10)->default('en');
            $table->string('category', 20)->default('UTILITY');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();

            $table->index(['whatsapp_connection_id', 'automation_key', 'clinic_id'], 'wa_templates_lookup');
        });

        Schema::create('clinic_whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('whatsapp_connection_id')->nullable()->constrained('whatsapp_connections')->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            // The patient message (`notifications`) this WhatsApp delivery carries.
            $table->foreignId('notification_id')->nullable()->unique()->constrained('notifications')->nullOnDelete();
            $table->string('direction', 10)->default('outbound');
            $table->string('automation_key', 60)->nullable();
            $table->string('event_key', 60)->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->string('recipient_phone', 30)->nullable();
            $table->json('variables')->nullable();
            $table->text('preview')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('provider_message_id', 128)->nullable()->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_reason', 255)->nullable();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'id'], 'cwa_msg_clinic');
            $table->index(['clinic_id', 'status', 'id'], 'cwa_msg_clinic_status');
            $table->index(['clinic_id', 'automation_key', 'created_at'], 'cwa_msg_clinic_automation');
            $table->index(['patient_id', 'id'], 'cwa_msg_patient');
        });

        Schema::create('clinic_whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_connection_id')->nullable()->constrained('whatsapp_connections')->nullOnDelete();
            $table->string('provider_event_id', 191)->nullable()->unique();
            $table->string('provider_message_id', 128)->nullable()->index();
            $table->string('event_type', 40);
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_whatsapp_webhook_events');
        Schema::dropIfExists('clinic_whatsapp_messages');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('clinic_whatsapp_settings');
        Schema::dropIfExists('whatsapp_connections');
    }
};
