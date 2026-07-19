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
        Schema::create('company_demos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('company_opportunities')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->foreignId('presenter_id')->nullable()->constrained('company_employees')->nullOnDelete();
            $table->string('meeting_link')->nullable();
            $table->string('outcome')->default('scheduled');
            $table->text('notes')->nullable();
            $table->text('feedback')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->timestamps();

            $table->index('outcome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_demos');
    }
};
