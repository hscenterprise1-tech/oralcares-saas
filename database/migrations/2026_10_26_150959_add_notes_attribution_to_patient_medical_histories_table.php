<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who last changed the medical-history notes and when
 * (`PatientSafetyController::updateNotes`). Each change also writes a
 * timeline event holding the previous text, so earlier versions are kept.
 * Existing notes keep null: their author was never stored and is not guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->foreignId('notes_updated_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('notes_updated_at')->nullable()->after('notes_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notes_updated_by');
            $table->dropColumn('notes_updated_at');
        });
    }
};
