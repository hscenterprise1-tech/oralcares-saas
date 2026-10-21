<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A planned treatment's estimate is the clinic's own price for the service.
 * When the clinic has not priced it, the estimate is unknown — null — not a
 * zero that reads as "free".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_records', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_records', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0.00)->change();
        });
    }
};
