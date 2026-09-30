<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link between a trial and the tenant it stands up.
 *
 * A trial has never had one, because it has never created anything: the row
 * recorded an intention and a human went and built the environment by hand.
 * Once provisioning is real, the trial needs to know which organisation and
 * clinic it produced — to send a login, to measure usage, and to hand the same
 * tenant over on Won rather than building a second one.
 *
 * `provisioned_at` is deliberately separate from `created_at`. "Somebody
 * started a trial in the CRM" and "the customer can log in" are different
 * moments, and the onboarding checklist has been conflating them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->foreignId('customer_organization_id')->nullable()->after('lead_id')
                ->constrained('organizations')->nullOnDelete();

            $table->foreignId('clinic_id')->nullable()->after('customer_organization_id')
                ->constrained('clinics')->nullOnDelete();

            $table->timestamp('provisioned_at')->nullable()->after('start_date');

            /*
             * Provisioning can fail. A trial stuck in `preparing` with no
             * reason is the failure mode worth designing out first, so the
             * reason is stored on the row a rep is already looking at.
             */
            $table->text('provisioning_error')->nullable()->after('provisioned_at');

            /* The trial's own copy, so CRM lists can sort by it without a join. */
            $table->timestamp('grace_ends_at')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_organization_id');
            $table->dropConstrainedForeignId('clinic_id');
            $table->dropColumn(['provisioned_at', 'provisioning_error', 'grace_ends_at']);
        });
    }
};
