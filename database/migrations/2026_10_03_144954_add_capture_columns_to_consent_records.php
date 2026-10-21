<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A consent is evidence: which text (version + hash) the patient agreed to,
 * how it was captured, by whom and when, and whether it was later revoked.
 * `signed_at` stays for existing rows; new rows set `captured_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->string('consent_version', 40)->nullable()->after('consent_type');
            $table->char('text_hash', 64)->nullable()->after('consent_version');
            $table->string('capture_method', 20)->nullable()->after('status');
            $table->foreignId('captured_by')->nullable()->after('capture_method')->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable()->after('captured_by');
            $table->timestamp('revoked_at')->nullable()->after('signed_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable()->after('revoked_by');

            $table->index(['clinic_id', 'patient_id', 'consent_type'], 'consent_records_patient_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('consent_records', function (Blueprint $table) {
            $table->dropIndex('consent_records_patient_type_index');
            $table->dropConstrainedForeignId('captured_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['consent_version', 'text_hash', 'capture_method', 'captured_at', 'revoked_at', 'revocation_reason']);
        });
    }
};
