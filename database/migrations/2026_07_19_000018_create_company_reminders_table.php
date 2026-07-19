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
        Schema::create('company_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('company_opportunities')->cascadeOnDelete();
            $table->string('reminder_type');
            $table->timestamp('reminder_date');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('reminder_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_reminders');
    }
};
