<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The practice-area capability a catalogue treatment needs (e.g. braces need
 * `braces_treatment`, an Orthodontics capability), from
 * `config/clinic_specialties.php`. Null: any practitioner of the treatment's
 * specialty may start and record it, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_treatments', function (Blueprint $table) {
            $table->string('required_capability', 60)->nullable()->after('specialty_key');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_treatments', fn (Blueprint $table) => $table->dropColumn('required_capability'));
    }
};
