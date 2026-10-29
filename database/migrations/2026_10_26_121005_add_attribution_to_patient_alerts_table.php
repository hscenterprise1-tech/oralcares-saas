<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who recorded a safety alert, and — instead of deleting it — when and by
 * whom it was removed (`MedicalHistoryService::syncManualAlerts`). Existing
 * alerts keep a null author: who recorded them was never stored, and is not
 * guessed now. Only alerts with no `resolved_at` are current.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_alerts', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('severity')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('created_by');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users')->nullOnDelete();
            $table->index(['patient_id', 'resolved_at'], 'patient_alerts_patient_current_index');
        });
    }

    public function down(): void
    {
        Schema::table('patient_alerts', function (Blueprint $table) {
            $table->dropIndex('patient_alerts_patient_current_index');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn('resolved_at');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
