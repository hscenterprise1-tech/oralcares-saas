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
        Schema::create('entity_activities', function (Blueprint $table) {
            $table->id();
            $table->morphs('entity'); // entity_type, entity_id
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // e.g., 'created', 'updated', 'status_changed', 'email_sent'
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('properties')->nullable(); // For diffs or metadata
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_activities');
    }
};
