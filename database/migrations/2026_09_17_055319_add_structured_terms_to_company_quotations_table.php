<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured commercial terms, alongside the existing free-text `terms`
 * column (kept as the general/"other" terms — legacy quotations already used
 * it that way and nothing here needs to move).
 *
 * All nullable and never rendered with a fabricated default in the Blade
 * template itself — `LeadWorkspaceService::createQuotation()` fills a
 * sensible starting value from `config('commercial.default_terms')` the same
 * way it already did for `terms`, so the content lives in one config file
 * today rather than a hardcoded PDF string, ready to become tenant-configurable
 * whenever the Settings module gets built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->text('payment_terms')->nullable()->after('terms');
            $table->text('implementation_terms')->nullable()->after('payment_terms');
            $table->text('support_terms')->nullable()->after('implementation_terms');
            $table->text('cancellation_terms')->nullable()->after('support_terms');
        });
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropColumn(['payment_terms', 'implementation_terms', 'support_terms', 'cancellation_terms']);
        });
    }
};
