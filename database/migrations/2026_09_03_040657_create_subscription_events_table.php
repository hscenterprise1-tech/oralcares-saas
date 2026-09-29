<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What changed about a subscription, and why.
 *
 * `company_subscriptions` records the agreement as it stands right now: a plan
 * change overwrites the old plan and nothing remembers there was one. That is
 * survivable while the only transition is trial → paid, and unworkable the
 * moment upgrades, extra seats and storage top-ups exist — which is the module
 * this build is asked to leave room for.
 *
 * So the log is created now and written by the two events this build genuinely
 * causes. Billing, proration and invoicing stay out of scope; when they arrive
 * they inherit a true history rather than a column that was overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            /* `trial_started`, `converted_to_paid`, and later upgrade/downgrade/expansion. */
            $table->string('event');

            $table->foreignId('from_plan_id')->nullable()->constrained('company_plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('company_plans')->nullOnDelete();

            /*
             * The before and after of whatever moved — limits, window, modules.
             * Kept as a snapshot rather than a column per entitlement so a new
             * entitlement does not need a migration to become loggable.
             */
            $table->json('changes')->nullable();

            /* Who, why, and against which commercial document. */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('commercial_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['company_subscription_id', 'created_at']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
