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
        Schema::create('entity_tasks', function (Blueprint $table) {
            $table->id();
            $table->morphs('entity');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed, cancelled
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id', 'status']);
            $table->index('assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_tasks');
    }
};
