<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('enabled_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->string('module_name');
            $table->timestamps();

            $table->unique(['license_id', 'module_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enabled_modules');
    }
};
