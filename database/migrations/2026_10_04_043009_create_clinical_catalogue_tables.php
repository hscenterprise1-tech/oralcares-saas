<?php

use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared clinical catalogue and each clinic's sparse configuration of it.
 *
 * `clinical_catalogue_items` is global reference data (conditions, allergies,
 * alert types, medications, visit types), one row per item for every clinic.
 * `clinic_catalogue_settings` holds only what a clinic changed: a rename, a
 * new order, an item switched off or made required, a specialty limit, or an
 * item of its own. A clinic on the defaults has no rows.
 *
 * The existing allergy list is copied in; `allergy_masters` itself is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('key', 64);
            $table->string('name', 120);
            $table->string('category', 40)->nullable();
            $table->string('default_severity', 10)->nullable();
            // Null: every specialty. Otherwise the specialty registry keys it applies to.
            $table->json('specialty_keys')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'key']);
            $table->index(['kind', 'is_active', 'position']);
        });

        Schema::create('clinic_catalogue_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('key', 64);
            // Null for an item the clinic added itself.
            $table->foreignId('catalogue_item_id')->nullable()->constrained('clinical_catalogue_items')->restrictOnDelete();
            // Every column below: null means "as the catalogue has it".
            $table->string('name', 120)->nullable();
            $table->string('category', 40)->nullable();
            $table->string('severity', 10)->nullable();
            $table->json('specialty_keys')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            $table->boolean('is_enabled')->nullable();
            $table->boolean('is_required')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'kind', 'key']);
        });

        $now = now();
        $rows = array_map(fn (array $item) => $item + ['is_active' => true, 'created_at' => $now, 'updated_at' => $now], DefaultClinicalCatalogue::items());

        if (Schema::hasTable('allergy_masters')) {
            foreach (DB::table('allergy_masters')->orderBy('category')->orderBy('position')->get() as $allergy) {
                $rows[] = [
                    'kind' => DefaultClinicalCatalogue::KIND_ALLERGY,
                    'key' => $allergy->key,
                    'name' => $allergy->name,
                    'category' => $allergy->category,
                    // Every allergy alert was "high" before; keep that.
                    'default_severity' => 'high',
                    'position' => (int) $allergy->position,
                    'is_active' => (bool) $allergy->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('clinical_catalogue_items')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_catalogue_settings');
        Schema::dropIfExists('clinical_catalogue_items');
    }
};
