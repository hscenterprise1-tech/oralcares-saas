<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dental images (radiographs, intraoral photos) filed against the teeth
 * they show, so a tooth's detail lists its imaging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attached_documents', function (Blueprint $table) {
            $table->json('tooth_numbers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attached_documents', function (Blueprint $table) {
            $table->dropColumn('tooth_numbers');
        });
    }
};
