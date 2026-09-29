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
        // 1. Harden attached_documents with clinic_id & uploaded_by
        if (Schema::hasTable('attached_documents')) {
            Schema::table('attached_documents', function (Blueprint $table) {
                if (! Schema::hasColumn('attached_documents', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('attached_documents', 'uploaded_by')) {
                    $table->foreignId('uploaded_by')->nullable()->after('file_path')->constrained('users')->nullOnDelete();
                }
            });
        }

        // 2. Harden patient_timeline_events with clinic_id
        if (Schema::hasTable('patient_timeline_events')) {
            Schema::table('patient_timeline_events', function (Blueprint $table) {
                if (! Schema::hasColumn('patient_timeline_events', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('organization_id')->constrained('clinics')->cascadeOnDelete();
                }
            });
        }

        // 3. Make encounter_id nullable on clinic_queue_items
        if (Schema::hasTable('clinic_queue_items')) {
            Schema::table('clinic_queue_items', function (Blueprint $table) {
                if (Schema::hasColumn('clinic_queue_items', 'encounter_id')) {
                    $table->unsignedBigInteger('encounter_id')->nullable()->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('patient_timeline_events')) {
            Schema::table('patient_timeline_events', function (Blueprint $table) {
                if (Schema::hasColumn('patient_timeline_events', 'clinic_id')) {
                    $table->dropConstrainedForeignId('clinic_id');
                }
            });
        }

        if (Schema::hasTable('attached_documents')) {
            Schema::table('attached_documents', function (Blueprint $table) {
                if (Schema::hasColumn('attached_documents', 'uploaded_by')) {
                    $table->dropConstrainedForeignId('uploaded_by');
                }
                if (Schema::hasColumn('attached_documents', 'clinic_id')) {
                    $table->dropConstrainedForeignId('clinic_id');
                }
            });
        }
    }
};
