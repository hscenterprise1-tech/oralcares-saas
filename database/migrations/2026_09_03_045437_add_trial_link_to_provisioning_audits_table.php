<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a failed provisioning run traceable.
 *
 * `provisioning_audits` keys only on `clinic_id`, which a failed run does not
 * have — the clinic is one of the things that failed to be created. So the one
 * row that matters most, the one recording why a customer never got their
 * environment, was the one row that could not be attached to anything.
 *
 * Both columns are nullable and neither is read by existing code, so this is
 * additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_audits', function (Blueprint $table) {
            $table->foreignId('trial_id')->nullable()->after('clinic_id')
                ->constrained('trials')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->after('trial_id')
                ->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_id');
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
