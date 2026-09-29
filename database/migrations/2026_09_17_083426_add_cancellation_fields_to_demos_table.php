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
        Schema::table('demos', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            $table->foreignId('cancelled_by_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['cancellation_reason', 'cancelled_at']);
        });
    }
};
