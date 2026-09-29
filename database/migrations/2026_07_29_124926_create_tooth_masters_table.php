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
        Schema::create('tooth_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('universal_number', 5)->nullable();
            $table->string('fdi_number', 5)->nullable();
            $table->string('tooth_type')->default('adult'); // adult, primary
            $table->string('quadrant', 5)->nullable(); // UR, UL, LR, LL
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tooth_masters');
    }
};
