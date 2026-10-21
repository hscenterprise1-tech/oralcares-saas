<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company-set switch for one module at one clinic (queue today; any plan
 * module later). It wins over the plan and add-ons: OralCares can enable a
 * module a clinic has not bought (a pilot, a goodwill grant) or switch off
 * one it has, without a code change. No row: the plan, its add-ons and the
 * module's default decide (`ClinicModules`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_module_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->boolean('enabled');
            $table->string('reason', 255)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'module_key'], 'clinic_module_overrides_clinic_module_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_module_overrides');
    }
};
