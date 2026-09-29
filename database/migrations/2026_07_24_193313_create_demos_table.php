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
        Schema::create('demos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('presenter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('status')->default('scheduled');
            $table->string('meeting_link')->nullable();
            $table->text('notes')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demos');
    }
};
