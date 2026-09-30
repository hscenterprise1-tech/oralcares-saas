<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Two CRM tables nothing reads.
 *
 * `quotations` predates `company_quotations` and has had no model since the
 * commercial rebuild — no relation, no query, no factory. Its rows were
 * migrated across by MigrateLegacyQuotationsSeeder and are present in
 * `company_quotations` under the same quotation numbers, so the table is a
 * second, stale copy of settled commercial history.
 *
 * `lead_activities` predates the polymorphic `activities` table. Its model and
 * its writer went with this change; every CRM surface, and Marketing's
 * automation actions, write to `activities`. Two jobs used to write here and
 * were moved off it precisely because nothing read it back.
 *
 * `down()` recreates neither. Restoring an empty table would not restore what
 * the drop removed, and pretending otherwise is worse than saying so: the
 * reversal for this migration is a database restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('lead_activities');
    }

    public function down(): void
    {
        // Irreversible by design — see the class docblock.
    }
};
