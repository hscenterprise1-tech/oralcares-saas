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
        if (! Schema::hasTable('patient_clinical_access_audits')) {
            Schema::create('patient_clinical_access_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
                $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('accessing_branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('resource_type', 40);
                $table->unsignedBigInteger('resource_id');
                $table->foreignId('attendance_visit_id')->nullable()->constrained('patient_visits')->restrictOnDelete();
                $table->string('access_reason', 40);
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('accessed_at')->useCurrent();

                $table->index(['clinic_id', 'accessed_at'], 'clinical_access_audits_clinic_time_idx');
                $table->index(['patient_id', 'accessed_at'], 'clinical_access_audits_patient_time_idx');
                $table->index(['actor_user_id', 'accessed_at'], 'clinical_access_audits_actor_time_idx');
                $table->index(['resource_type', 'resource_id'], 'clinical_access_audits_resource_idx');
            });
        }

        // MySQL 8.0.46 triggers for database-level immutability
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $dbName = DB::getDatabaseName();

            $updateTriggerExists = DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', $dbName)
                ->where('TRIGGER_NAME', 'prevent_clinical_access_audit_upd')
                ->exists();

            if (! $updateTriggerExists) {
                DB::unprepared("
                    CREATE TRIGGER `prevent_clinical_access_audit_upd`
                    BEFORE UPDATE ON `patient_clinical_access_audits`
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Immutability violation: patient_clinical_access_audits records cannot be updated.';
                ");
            }

            $deleteTriggerExists = DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', $dbName)
                ->where('TRIGGER_NAME', 'prevent_clinical_access_audit_del')
                ->exists();

            if (! $deleteTriggerExists) {
                DB::unprepared("
                    CREATE TRIGGER `prevent_clinical_access_audit_del`
                    BEFORE DELETE ON `patient_clinical_access_audits`
                    FOR EACH ROW
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Immutability violation: patient_clinical_access_audits records cannot be deleted.';
                ");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('patient_clinical_access_audits')) {
            return;
        }

        if (DB::table('patient_clinical_access_audits')->count() > 0) {
            throw new RuntimeException('Cannot drop patient_clinical_access_audits table: audit records exist.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS `prevent_clinical_access_audit_upd`');
            DB::unprepared('DROP TRIGGER IF EXISTS `prevent_clinical_access_audit_del`');
        }

        Schema::dropIfExists('patient_clinical_access_audits');
    }
};
