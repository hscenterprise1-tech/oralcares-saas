<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ClinicEmployee;
use Illuminate\Support\Facades\DB;

/**
 * What clinical, operational or financial history hangs off a branch or a
 * staff record — the records that must outlive it.
 *
 * A branch or staff record with any of this history is never hard-deleted:
 * deleting it used to cascade away (or null out the author of) appointments,
 * visits, encounters, queues and bills. Such a record is deactivated instead;
 * only a record created by mistake, with no history yet, may be deleted. The
 * database enforces the same rule with restricting foreign keys.
 */
class RetainedClinicalHistory
{
    /**
     * Branch-owned history, as `label => [table, column]`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BRANCH_HISTORY = [
        'appointments' => ['appointments', 'branch_id'],
        'appointment series' => ['appointment_series', 'branch_id'],
        'visits' => ['patient_visits', 'branch_id'],
        'encounters' => ['clinical_encounters', 'branch_id'],
        'reception queues' => ['clinic_queues', 'branch_id'],
        'queue history' => ['queue_histories', 'branch_id'],
        'bills' => ['patient_billings', 'branch_id'],
        'stock records' => ['inventory_transactions', 'branch_id'],
        'stock batches' => ['inventory_batches', 'branch_id'],
        'purchase orders' => ['inventory_purchase_orders', 'branch_id'],
        'number series' => ['numbering_sequences', 'branch_id'],
    ];

    /**
     * Staff-authored history, as `label => [table, column]`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const STAFF_HISTORY = [
        'appointments' => ['appointments', 'practitioner_id'],
        'appointment series' => ['appointment_series', 'practitioner_id'],
        'visits' => ['patient_visits', 'doctor_id'],
        'encounters' => ['clinical_encounters', 'doctor_id'],
        'consultations' => ['clinical_consultations', 'doctor_id'],
        'queue entries' => ['clinic_queue_items', 'doctor_id'],
        'patient assignments' => ['patient_doctor_assignments', 'to_practitioner_id'],
        'bills' => ['patient_billings', 'doctor_id'],
        'payments recorded' => ['patient_billing_payments', 'recorded_by'],
        'refunds recorded' => ['patient_billing_refunds', 'refunded_by'],
        'queue actions' => ['queue_histories', 'performed_by'],
        'leave records' => ['leaves', 'employee_id'],
        'staff documents' => ['staff_documents', 'clinic_employee_id'],
    ];

    /**
     * The kinds of history this branch holds; empty when it has none.
     *
     * @return list<string>
     */
    public function forBranch(Branch $branch): array
    {
        return $this->present(self::BRANCH_HISTORY, $branch->id);
    }

    /**
     * The kinds of history this staff record authored or owns; empty when it has none.
     *
     * @return list<string>
     */
    public function forEmployee(ClinicEmployee $employee): array
    {
        return $this->present(self::STAFF_HISTORY, $employee->id);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $sources
     * @return list<string>
     */
    private function present(array $sources, int $id): array
    {
        $found = [];

        foreach ($sources as $label => [$table, $column]) {
            if (DB::table($table)->where($column, $id)->exists()) {
                $found[] = $label;
            }
        }

        return $found;
    }
}
