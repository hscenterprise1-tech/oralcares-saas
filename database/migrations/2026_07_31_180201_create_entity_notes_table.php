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
        Schema::create('entity_notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('entity');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Author
            $table->text('content');
            $table->json('mentions')->nullable(); // Track users mentioned
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_notes');
    }
};
