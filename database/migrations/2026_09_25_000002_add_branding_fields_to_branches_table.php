<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-branch identity override — a branch can show its own name
 * and logo instead of the clinic's default, for whichever surface already
 * knows which branch is active (the authenticated portal header, via
 * `active_branch`). Both nullable: leaving them blank falls back to the
 * clinic's own `display_name`/`logo_url`, so this is a pure override, not a
 * second required identity per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('clinical_establishment_registration_number');
            $table->string('logo_path')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'logo_path']);
        });
    }
};
