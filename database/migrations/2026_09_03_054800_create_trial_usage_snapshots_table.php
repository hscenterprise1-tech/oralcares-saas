<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a trial tenant actually did, counted nightly.
 *
 * `trials` already carries `usage_metrics`, `usage_percent`, `users_created`,
 * `last_login` and `inactive_days` — and every one of them was writable through
 * the trial update endpoint and written by nothing else. A rep could type a
 * usage figure in. Numbers nobody measured are worse than no numbers, because
 * they get believed.
 *
 * These are measured, immutable and dated. Keeping them in their own table
 * rather than overwriting columns on `trials` means the trend is visible — "they
 * were busy in week one and stopped" is the single most useful thing to know
 * about a trial, and it is invisible if each night overwrites the last.
 *
 * Everything here is an aggregate. There is no column in this table that could
 * hold a patient name, a clinical note, or the text of a message; that is a
 * deliberate property of the schema, not just of the code that fills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_usage_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trial_id')->constrained('trials')->cascadeOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->timestamp('captured_at');

            /* People */
            $table->unsignedInteger('users_total')->default(0);
            $table->unsignedInteger('users_active_7d')->default(0);
            $table->unsignedInteger('logins_7d')->default(0);
            $table->timestamp('last_login_at')->nullable();

            /* Work done — counts only, never contents */
            $table->unsignedInteger('patients_count')->default(0);
            $table->unsignedInteger('appointments_count')->default(0);
            $table->unsignedInteger('appointments_7d')->default(0);
            $table->unsignedInteger('encounters_count')->default(0);
            $table->unsignedInteger('prescriptions_count')->default(0);
            $table->unsignedInteger('invoices_count')->default(0);

            /* Footprint */
            $table->unsignedInteger('branches_count')->default(0);
            $table->unsignedBigInteger('storage_bytes')->default(0);
            $table->unsignedInteger('storage_limit_mb')->nullable();

            /*
             * Which modules the plan grants, and which of them show any
             * activity. The difference between the two is the most commercially
             * useful thing in this table: it is what the customer is paying for
             * and not using.
             */
            $table->json('modules_enabled')->nullable();
            $table->json('modules_used')->nullable();

            /* Setup progress, measured rather than self-reported */
            $table->unsignedTinyInteger('onboarding_readiness')->default(0);
            $table->json('onboarding_checks')->nullable();

            $table->unsignedInteger('whatsapp_sent_count')->default(0);

            /*
             * Nullable and always null for now. No plan, account or licence
             * column defines a WhatsApp allowance, so the honest report is that
             * the quota is unavailable — not a number we made up.
             */
            $table->unsignedInteger('whatsapp_quota')->nullable();

            $table->timestamps();

            $table->index(['trial_id', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_usage_snapshots');
    }
};
