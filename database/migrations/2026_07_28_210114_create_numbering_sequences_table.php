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
        Schema::create('numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('entity_type', 50); // e.g. patient, case, invoice
            $table->string('prefix', 20)->nullable();
            $table->string('suffix', 20)->nullable(); // Might be useful in future
            $table->unsignedTinyInteger('padding')->default(0);
            $table->unsignedBigInteger('current_number')->default(0);
            $table->string('reset_frequency', 20)->nullable(); // e.g. yearly, monthly
            $table->date('last_reset_date')->nullable();
            $table->timestamps();

            // A sequence is unique per organization, branch, and entity type
            $table->unique(['organization_id', 'branch_id', 'entity_type'], 'num_seq_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
    }
};
