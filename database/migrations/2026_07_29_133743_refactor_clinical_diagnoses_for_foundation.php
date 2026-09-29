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
        Schema::table('clinical_diagnoses', function (Blueprint $table) {
            $table->foreignId('organization_id')->after('id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('diagnosis_master_id')->after('patient_id')->nullable()->constrained('diagnosis_masters')->nullOnDelete();

            $table->string('coding_system')->after('diagnosis_master_id')->nullable();

            // `code` and `description` already exist. We change `notes` to `details`
            $table->renameColumn('notes', 'details');

            $table->string('status')->after('description')->default('active');

            $table->foreignId('diagnosed_by')->after('details')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clinical_diagnoses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagnosed_by');
            $table->dropColumn(['status', 'coding_system']);
            $table->renameColumn('details', 'notes');
            $table->dropConstrainedForeignId('diagnosis_master_id');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
