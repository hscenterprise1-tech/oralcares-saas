<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four things that stop the CRM working at a hundred thousand accounts.
 *
 * 1. `teams.key` was UNIQUE on the key alone, on a table that carries
 *    `organization_id`. The second tenant to name a team "north" was refused,
 *    with an error that says nothing about tenancy. This is a correctness bug
 *    that only appears once there is more than one customer, which is why it
 *    survived. Rows with a null organization are left free to collide, since a
 *    unique index ignores nulls in MySQL and a team with no tenant is a
 *    pre-tenancy legacy row, not a claim on the name.
 *
 * 2. `commercial_payments.commercial_invoice_id` had neither a foreign key nor
 *    an index, while the two columns either side of it had both. A payment could
 *    name an invoice that does not exist, and "payments for this invoice" was a
 *    scan.
 *
 * 3. Every scoped CRM list filters `organization_id` and `status` and sorts by
 *    `updated_at`, but only `organization_id` alone was indexed, so the work
 *    inside a tenant was a scan of that tenant. `stage_entered_at` — the stalled
 *    predicate, and a whitelisted sort column — had no index at all.
 *
 * 4. The duplicate check ran `LOWER(email) = ?` and matched `company_name` with
 *    no index on either, so every keystroke in the create form scanned `leads`.
 *    MySQL 8.0.13+ indexes expressions directly, so the check keeps its
 *    case-insensitive semantics and gains an index rather than the application
 *    having to maintain a shadow lowercase column.
 *
 * `leads.normalized_phone` is already indexed (`leads_normalized_phone_index`)
 * and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Tenant-scoped team keys ──────────────────────────────────────
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_key_unique');
            $table->unique(['organization_id', 'key'], 'teams_organization_id_key_unique');
        });

        // ── 2. The missing invoice link on the Finance ledger ───────────────
        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->foreign('commercial_invoice_id', 'commercial_payments_invoice_foreign')
                ->references('id')
                ->on('commercial_invoices')
                ->nullOnDelete();
        });

        // ── 3. The composites every scoped list actually uses ───────────────
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['organization_id', 'status', 'updated_at'], 'leads_org_status_updated_index');
            $table->index('stage_entered_at', 'leads_stage_entered_at_index');
        });

        // ── 4. Sub-20ms duplicate checks ────────────────────────────────────
        /*
         * Functional indexes, so `LOWER(email) = ?` and `LOWER(company_name) = ?`
         * are index lookups instead of full scans. Expressed in raw SQL because
         * the schema builder has no expression-index API; guarded by a MySQL
         * version check so a non-MySQL connection (or an older server) degrades
         * to the previous behaviour rather than failing the migration.
         */
        if ($this->supportsFunctionalIndexes()) {
            DB::statement('CREATE INDEX leads_email_lower_index ON leads ((LOWER(email)))');
            DB::statement('CREATE INDEX leads_company_name_lower_index ON leads ((LOWER(company_name)))');
        }
    }

    public function down(): void
    {
        if ($this->supportsFunctionalIndexes()) {
            DB::statement('DROP INDEX leads_company_name_lower_index ON leads');
            DB::statement('DROP INDEX leads_email_lower_index ON leads');
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_stage_entered_at_index');
            $table->dropIndex('leads_org_status_updated_index');
        });

        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->dropForeign('commercial_payments_invoice_foreign');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_organization_id_key_unique');
            $table->unique('key', 'teams_key_unique');
        });
    }

    /**
     * Whether this connection can index an expression (MySQL 8.0.13+).
     */
    private function supportsFunctionalIndexes(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        $version = (string) DB::selectOne('SELECT VERSION() AS version')->version;

        // MariaDB reports through the same function but has no functional indexes.
        if (str_contains(strtolower($version), 'mariadb')) {
            return false;
        }

        return version_compare($version, '8.0.13', '>=');
    }
};
