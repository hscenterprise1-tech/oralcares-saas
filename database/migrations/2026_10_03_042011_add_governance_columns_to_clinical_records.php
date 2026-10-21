<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How governed records leave the working record without being destroyed:
 *
 * - a document is removed (soft-deleted; the file stays for retention);
 * - a signed consultation is voided ("entered in error"), never deleted;
 * - a tooth-chart entry is voided, and the tooth falls back to its previous
 *   entry;
 * - a patient is archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attached_documents', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('removed_by')->nullable()->after('scanner')->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 500)->nullable()->after('removed_by');
        });

        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
        });

        Schema::table('patient_tooth_status_histories', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });

        Schema::table('patient_tooth_status_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        Schema::table('clinical_encounters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        Schema::table('attached_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn(['removal_reason', 'deleted_at']);
        });
    }
};
