<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM final gap closure — leads could only ever be created one at a time.
 * Mirrors Marketing's `marketing_contact_imports` shape (the only import
 * pattern this codebase already has), scoped down to what a lead import
 * actually needs: no batch name, no tag pivot, CSV only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename')->nullable();
            /** pending | processing | completed | failed */
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};
