<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trial's extension window is a promise about somebody else's access — the
 * live route already moves `end_date`, `grace_ends_at`, and the tenant's own
 * subscription window together (`TrialLifecycleService::extend()`), but
 * anyone holding `trials.provision` could pull that lever unilaterally, with
 * nothing recording who asked or why beyond a free-text reason on the trial
 * itself. This table is the audit trail and the gate: every extension, rep-
 * requested or manager-applied, is a row here before `extend()` ever runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_extension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trial_id')->constrained('trials')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('additional_days');
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            /*
             * The Deal 360 banner asks "is there a pending request for this
             * trial" on every page load — one indexed lookup rather than a
             * scan of every extension a long-running trial has ever had.
             */
            $table->index(['trial_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_extension_requests');
    }
};
