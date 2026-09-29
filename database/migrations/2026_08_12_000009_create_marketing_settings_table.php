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
        Schema::create('marketing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('default_whatsapp_account_id')->nullable()->constrained('marketing_whatsapp_accounts')->nullOnDelete();
            $table->integer('max_daily_messages')->default(5000);
            $table->integer('hourly_dispatch_rate')->default(500);
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_start')->default('21:00');
            $table->string('quiet_hours_end')->default('09:00');
            $table->boolean('emergency_freeze_enabled')->default(false);
            $table->json('opt_out_keywords')->nullable();
            $table->boolean('auto_suppress_on_failures')->default(true);
            $table->integer('max_consecutive_failures')->default(3);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_settings');
    }
};
