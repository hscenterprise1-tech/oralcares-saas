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
        // 1. Harden appointments with clinic scoping and decoupled practitioner tracking
        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                if (! Schema::hasColumn('appointments', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('organization_id')->constrained('clinics')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('appointments', 'practitioner_id')) {
                    $table->foreignId('practitioner_id')->nullable()->after('patient_id')->constrained('clinic_employees')->nullOnDelete();
                }
                if (! Schema::hasColumn('appointments', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('appointments', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
            });

            // Backfill clinic_id on appointments from branch or patient where possible
            DB::statement('
                UPDATE appointments
                SET clinic_id = (
                    SELECT branches.clinic_id
                    FROM branches
                    WHERE branches.id = appointments.branch_id
                    LIMIT 1
                )
                WHERE clinic_id IS NULL AND branch_id IS NOT NULL
            ');
        }

        // 2. Add clinic_id to xray_records
        if (Schema::hasTable('xray_records')) {
            Schema::table('xray_records', function (Blueprint $table) {
                if (! Schema::hasColumn('xray_records', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('xray_records', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });

            DB::statement('
                UPDATE xray_records
                SET clinic_id = (
                    SELECT patients.clinic_id
                    FROM patients
                    WHERE patients.id = xray_records.patient_id
                    LIMIT 1
                )
                WHERE clinic_id IS NULL AND patient_id IS NOT NULL
            ');
        }

        // 3. Add clinic_id to patient_dental_charts
        if (Schema::hasTable('patient_dental_charts')) {
            Schema::table('patient_dental_charts', function (Blueprint $table) {
                if (! Schema::hasColumn('patient_dental_charts', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                }
            });

            DB::statement('
                UPDATE patient_dental_charts
                SET clinic_id = (
                    SELECT patients.clinic_id
                    FROM patients
                    WHERE patients.id = patient_dental_charts.patient_id
                    LIMIT 1
                )
                WHERE clinic_id IS NULL AND patient_id IS NOT NULL
            ');
        }

        // 4. Add clinic_id to lab_orders
        if (Schema::hasTable('lab_orders')) {
            Schema::table('lab_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('lab_orders', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('lab_orders', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });

            DB::statement('
                UPDATE lab_orders
                SET clinic_id = (
                    SELECT patients.clinic_id
                    FROM patients
                    WHERE patients.id = lab_orders.patient_id
                    LIMIT 1
                )
                WHERE clinic_id IS NULL AND patient_id IS NOT NULL
            ');
        }

        // 5. Add clinic_id to diagnostic_results if present
        if (Schema::hasTable('diagnostic_results')) {
            Schema::table('diagnostic_results', function (Blueprint $table) {
                if (! Schema::hasColumn('diagnostic_results', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                }
            });
        }

        // 6. Enrich doctor_profiles (practitioners) with clinic_id, extensible specialty and fee
        if (Schema::hasTable('doctor_profiles')) {
            Schema::table('doctor_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('doctor_profiles', 'clinic_id')) {
                    $table->foreignId('clinic_id')->nullable()->after('id')->constrained('clinics')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('doctor_profiles', 'specialty')) {
                    $table->string('specialty')->nullable()->after('clinic_employee_id');
                }
                if (! Schema::hasColumn('doctor_profiles', 'consultation_fee')) {
                    $table->decimal('consultation_fee', 10, 2)->nullable()->after('qualifications');
                }
            });

            DB::statement('
                UPDATE doctor_profiles
                SET clinic_id = (
                    SELECT clinic_employees.clinic_id
                    FROM clinic_employees
                    WHERE clinic_employees.id = doctor_profiles.clinic_employee_id
                    LIMIT 1
                )
                WHERE clinic_id IS NULL AND clinic_employee_id IS NOT NULL
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('doctor_profiles')) {
            Schema::table('doctor_profiles', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropColumn(['specialty', 'consultation_fee']);
            });
        }

        if (Schema::hasTable('diagnostic_results')) {
            Schema::table('diagnostic_results', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
            });
        }

        if (Schema::hasTable('lab_orders')) {
            Schema::table('lab_orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropConstrainedForeignId('created_by');
            });
        }

        if (Schema::hasTable('patient_dental_charts')) {
            Schema::table('patient_dental_charts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
            });
        }

        if (Schema::hasTable('xray_records')) {
            Schema::table('xray_records', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropConstrainedForeignId('created_by');
            });
        }

        if (Schema::hasTable('appointments')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('clinic_id');
                $table->dropConstrainedForeignId('practitioner_id');
                $table->dropConstrainedForeignId('created_by');
                $table->dropConstrainedForeignId('updated_by');
            });
        }
    }
};
