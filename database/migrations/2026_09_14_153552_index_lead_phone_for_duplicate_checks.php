<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other half of the duplicate check.
 *
 * `findDuplicateLead()` matches a phone against `normalized_phone` *and* the raw
 * `phone` column, because `normalized_phone` is null on most rows and, where it
 * is set, does not always hold what `PhoneNormalizer::normalize()` produces
 * today. `normalized_phone` has been indexed since the CRM rebuild; `phone`
 * never was, so the fallback branch — the one that actually matches most of the
 * time — was the scan.
 *
 * This is a stopgap that pays for itself immediately. The real fix is to
 * backfill `normalized_phone` for every lead and make the raw-column branch
 * unnecessary; that is a data migration with its own verification and does not
 * belong bundled with an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index('phone', 'leads_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_phone_index');
        });
    }
};
