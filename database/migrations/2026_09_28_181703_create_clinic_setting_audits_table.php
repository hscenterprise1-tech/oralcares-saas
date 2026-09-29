<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 — who changed which clinic setting, when, from what, to what.
 * Append-only: rows are never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_setting_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('section', 64);
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['clinic_id', 'section', 'created_at'], 'csa_clinic_section_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_setting_audits');
    }
};
