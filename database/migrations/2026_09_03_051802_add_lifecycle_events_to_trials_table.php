<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which lifecycle milestones have already fired.
 *
 * The old reminder pass asked `end_date->isTomorrow()`. That is only ever true
 * on one calendar day, so a scheduler that missed its 08:15 slot — a deploy, an
 * outage, a queue backlog — skipped the "ending tomorrow" warning permanently
 * and nobody found out. Run it twice in a day and the client got two WhatsApp
 * messages and the rep got two tasks instead.
 *
 * Recording each milestone as it fires fixes both halves: a missed run catches
 * up on the next pass because the marker is absent, and a repeated run does
 * nothing because it is present.
 *
 * Nullable and written by nothing else, so this is additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->json('lifecycle_events')->nullable()->after('onboarding_checklist');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropColumn(['lifecycle_events', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
