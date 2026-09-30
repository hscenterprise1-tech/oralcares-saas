<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Centralized Search Documents Index
        Schema::create('ent_search_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module')->index(); // e.g. 'patient', 'billing'
            $table->string('model_type')->index(); // App\Models\Patient
            $table->unsignedBigInteger('model_id')->index();

            // Tenant isolation
            $table->unsignedBigInteger('clinic_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();

            // Display & Routing
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('url')->nullable();

            // Searchable Data
            $table->text('searchable_text'); // For Full-Text Search
            $table->json('metadata')->nullable(); // For advanced filtering (status, priority, etc)

            $table->timestamps();

            // Foreign keys
            $table->foreign('clinic_id')->references('id')->on('clinics')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // Uniqueness to prevent duplicates of the same model
            $table->unique(['model_type', 'model_id']);
        });

        // Add Full-Text index on MySQL natively (SQLite does not support FULLTEXT)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ent_search_documents ADD FULLTEXT fulltext_index(title, subtitle, searchable_text)');
        }

        // 2. Search History
        Schema::create('ent_search_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('query_string');
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('searched_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // 3. Saved Searches
        Schema::create('ent_saved_searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name');
            $table->string('query_string')->nullable();
            $table->json('filters')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ent_saved_searches');
        Schema::dropIfExists('ent_search_history');
        Schema::dropIfExists('ent_search_documents');
    }
};
