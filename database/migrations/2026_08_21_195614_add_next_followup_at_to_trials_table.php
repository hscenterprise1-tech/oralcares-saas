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
        Schema::table('trials', function (Blueprint $table) {
            if (! Schema::hasColumn('trials', 'next_followup_at')) {
                $table->timestamp('next_followup_at')->nullable()->after('next_action');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropColumn('next_followup_at');
        });
    }
};
