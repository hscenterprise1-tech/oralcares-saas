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
        Schema::table('clinical_notes', function (Blueprint $table) {
            $table->foreignId('organization_id')->after('id')->nullable()->constrained('organizations')->cascadeOnDelete();

            // Refactor author_id to users
            Schema::disableForeignKeyConstraints();
            if (Schema::hasColumn('clinical_notes', 'author_id')) {
                $table->dropColumn('author_id');
            }
            Schema::enableForeignKeyConstraints();

            $table->foreignId('author_id')->after('patient_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->after('content')->default('draft');
            $table->timestamp('signed_at')->after('status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_notes', function (Blueprint $table) {
            $table->dropColumn(['status', 'signed_at']);
            $table->dropConstrainedForeignId('author_id');
            $table->foreignId('author_id')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
