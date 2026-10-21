<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consents a clinic asks patients for, and the exact wording of each
 * version. A version is never edited: changing the wording makes the next
 * version, so every signed `consent_records` row points at the text the
 * patient actually agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_consent_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->string('key', 60);
            $table->string('label', 120);
            // Null: every clinic; otherwise the specialty (registry key) it belongs to.
            $table->string('specialty_key', 40)->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'key']);
        });

        Schema::create('clinic_consent_type_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_consent_type_id')->constrained('clinic_consent_types')->restrictOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('text');
            $table->char('text_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['clinic_consent_type_id', 'version'], 'consent_type_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_consent_type_versions');
        Schema::dropIfExists('clinic_consent_types');
    }
};
