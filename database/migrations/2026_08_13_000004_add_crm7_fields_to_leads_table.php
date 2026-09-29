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
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('lost_at')->nullable()->after('lost_reason');
            $table->string('lost_competitor')->nullable()->after('lost_at');
            $table->date('winback_scheduled_at')->nullable()->after('lost_competitor');
            $table->index('lost_at');
            $table->index('winback_scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['lost_at']);
            $table->dropIndex(['winback_scheduled_at']);
            $table->dropColumn(['lost_at', 'lost_competitor', 'winback_scheduled_at']);
        });
    }
};
