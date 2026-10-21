<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A governed action that involves a second record (a patient merge names the
 * patient to keep) carries it here, validated by the action when asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('deletion_requests', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
