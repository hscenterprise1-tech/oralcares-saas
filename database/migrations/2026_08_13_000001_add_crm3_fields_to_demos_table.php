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
            $table->integer('duration_minutes')->default(45)->after('scheduled_at');
            $table->string('demo_mode')->default('online')->after('duration_minutes');
            $table->string('location')->nullable()->after('demo_mode');
            $table->string('confirmation_status')->default('pending')->after('status');
            $table->string('outcome')->nullable()->after('feedback');
            $table->text('next_action')->nullable()->after('outcome');
            $table->text('objections')->nullable()->after('next_action');
            $table->foreignId('rescheduled_from_id')->nullable()->after('objections')->constrained('demos')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('demos', function (Blueprint $table) {
            $table->dropForeign(['rescheduled_from_id']);
            $table->dropColumn([
                'duration_minutes',
                'demo_mode',
                'location',
                'confirmation_status',
                'outcome',
                'next_action',
                'objections',
                'rescheduled_from_id',
            ]);
        });
    }
};
