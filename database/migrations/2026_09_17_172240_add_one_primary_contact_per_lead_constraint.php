<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "One primary contact per lead" has only ever been enforced in PHP
 * (`DealContactController::promote()`'s demote-then-set, and
 * `LeadWorkspaceService::syncPrimaryContact()`'s non-transactional
 * `updateOrCreate`) — never at the database, so two concurrent requests
 * could each pass the "no primary yet" check and both insert one.
 *
 * MySQL has no partial/filtered unique index (`WHERE is_primary = true`),
 * which is exactly why `lead_branches`' own equivalent invariant was
 * documented as "enforced in the model" rather than in the schema. The
 * standard MySQL workaround is used instead: a generated column that is the
 * lead id only when this row is that lead's live primary contact, and NULL
 * for every other row — MySQL treats each NULL as distinct, so any number of
 * non-primary contacts per lead still coexist freely, while two primary rows
 * for the same lead collide on the same non-null value and the second write
 * fails at the database, not just in application code that forgot to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Virtual, not stored: MySQL 8's INPLACE algorithm refuses to add
            // a STORED generated column to a table that already carries a
            // foreign key (a misleading "Cannot add foreign key constraint"
            // error). A virtual column carries no rebuild cost here either,
            // since it is only ever read through the index, never selected.
            $table->unsignedBigInteger('primary_contact_lead_id')
                ->virtualAs('CASE WHEN is_primary = 1 AND lead_id IS NOT NULL AND deleted_at IS NULL THEN lead_id ELSE NULL END')
                ->nullable()
                ->after('is_primary');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique('primary_contact_lead_id', 'contacts_one_primary_per_lead');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_one_primary_per_lead');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('primary_contact_lead_id');
        });
    }
};
