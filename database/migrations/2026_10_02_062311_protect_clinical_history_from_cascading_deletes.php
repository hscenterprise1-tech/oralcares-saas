<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical, operational and financial history must outlive the branch, staff
 * record or login it hangs off.
 *
 * These foreign keys cascaded (or nulled the author): deleting a branch from
 * Clinic Admin removed its appointments, visits, encounters — and through the
 * encounters their prescriptions, diagnoses and notes — queues and bills;
 * deleting a staff record removed their consultations and the patients'
 * assignment history and blanked the doctor on encounters, appointments and
 * bills. Each now restricts, so the database refuses the delete and the record
 * must be deactivated instead (the controllers already refuse first, see
 * `RetainedClinicalHistory`). Existing rows are untouched.
 */
return new class extends Migration
{
    /**
     * `table => [column => [parent table, rule before this migration]]`.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const KEYS = [
        'appointments' => ['branch_id' => ['branches', 'cascade'], 'practitioner_id' => ['clinic_employees', 'set null']],
        'appointment_series' => ['branch_id' => ['branches', 'cascade'], 'practitioner_id' => ['clinic_employees', 'set null']],
        'clinical_encounters' => ['branch_id' => ['branches', 'cascade'], 'doctor_id' => ['clinic_employees', 'set null']],
        'patient_visits' => ['branch_id' => ['branches', 'cascade']],
        'patient_billings' => ['branch_id' => ['branches', 'cascade'], 'doctor_id' => ['clinic_employees', 'set null']],
        'patient_billing_payments' => ['recorded_by' => ['clinic_employees', 'set null']],
        'patient_billing_refunds' => ['refunded_by' => ['clinic_employees', 'set null']],
        'clinic_queues' => ['branch_id' => ['branches', 'cascade']],
        'clinic_queue_items' => ['doctor_id' => ['clinic_employees', 'set null']],
        'queue_histories' => ['branch_id' => ['branches', 'cascade'], 'performed_by' => ['clinic_employees', 'set null']],
        'clinical_consultations' => ['doctor_id' => ['clinic_employees', 'cascade']],
        'patient_doctor_assignments' => ['to_practitioner_id' => ['clinic_employees', 'cascade'], 'assigned_by' => ['users', 'cascade']],
        'inventory_batches' => ['branch_id' => ['branches', 'cascade']],
        'inventory_transactions' => ['branch_id' => ['branches', 'cascade']],
        'inventory_purchase_orders' => ['branch_id' => ['branches', 'cascade']],
        'numbering_sequences' => ['branch_id' => ['branches', 'cascade']],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => [$parent]) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->foreign($column)->references('id')->on($parent)->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => [$parent, $rule]) {
                    $blueprint->dropForeign([$column]);
                    $blueprint->foreign($column)->references('id')->on($parent)->onDelete($rule);
                }
            });
        }
    }
};
