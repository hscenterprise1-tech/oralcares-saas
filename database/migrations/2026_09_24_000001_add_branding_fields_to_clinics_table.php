<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-clinic branding, shown before login (subdomain resolves the clinic
 * ahead of authentication) and in the authenticated portal header.
 *
 * `display_name` is separate from `name`: `name` is the legal/CRM-facing
 * name used across Company HQ, `display_name` is what the clinic wants its
 * own staff and patients to see and may differ (shorter, a trading name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('status');
            $table->string('display_name')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'display_name']);
        });
    }
};
