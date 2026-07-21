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
        Schema::create('ent_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Context Scoping (preferences can be set globally or per tenant)
            $table->foreignId('clinic_id')->nullable()->constrained()->cascadeOnDelete();
            
            // JSON fields for fine-grained preferences
            $table->json('disabled_channels')->nullable(); // e.g., ["email", "sms"]
            $table->json('disabled_categories')->nullable(); // e.g., ["crm", "billing"]
            
            // Quiet Hours
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('timezone')->default('UTC');
            
            $table->timestamps();

            // A user should have unique preferences per context combination
            $table->unique(['user_id', 'clinic_id'], 'ent_pref_unique_context');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ent_notification_preferences');
    }
};
