<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each uploaded row actually did — created | duplicate | invalid.
 * `lead_id` is null for a duplicate or invalid row: there is no lead to
 * point at, and that absence is itself the record of what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_id')->constrained('lead_imports')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->unsignedInteger('row_number')->nullable();

            /** created | duplicate | invalid */
            $table->string('outcome', 20);

            /*
             * A snapshot of what the file said — an invalid row has no lead
             * to read it back from, and a later edit to the lead should not
             * rewrite what the file actually contained.
             */
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            /** Why an invalid row was rejected, or which existing lead a duplicate matched. */
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['import_id', 'outcome'], 'lir_import_outcome_idx');
            $table->index(['lead_id'], 'lir_lead_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_rows');
    }
};
