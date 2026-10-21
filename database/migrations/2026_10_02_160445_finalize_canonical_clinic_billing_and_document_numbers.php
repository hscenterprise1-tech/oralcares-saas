<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clinic billing on the `patient_billings` family (ADR-001 §G), and
 * tenant-safe document numbers (ADR-001, audit P2-3).
 *
 * Billing:
 * - a bill records its tax, what has been paid and refunded, its financial
 *   year, and when and why it was cancelled;
 * - lines snapshot their description and tax (a consultation or registration
 *   fee is a line with no catalogue procedure), and may point at the
 *   clinical record they bill (`source`);
 * - payments and refunds carry a receipt / refund number from their branch's
 *   series;
 * - every child row carries its clinic, tied to its bill's clinic by a
 *   composite key, so no line, payment or refund can cross clinics.
 *
 * Numbers: document numbers were unique across every tenant (and generated
 * at random, or from row counts). They are unique per clinic now (per
 * branch for financial documents) and issued from locked sequences
 * (`ClinicDocumentNumbers`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_billings', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('paid_amount', 12, 2)->default(0)->after('final_amount');
            $table->decimal('refunded_amount', 12, 2)->default(0)->after('paid_amount');
            $table->string('financial_year', 7)->nullable()->after('billing_number');
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->string('cancellation_reason')->nullable();
        });

        Schema::table('patient_billings', function (Blueprint $table) {
            $table->dropUnique('patient_billings_billing_number_unique');
            $table->unique(['branch_id', 'billing_number'], 'patient_billings_branch_number_unique');
            $table->unique(['id', 'clinic_id'], 'patient_billings_id_clinic_unique');
        });

        DB::table('patient_billings')->update([
            'paid_amount' => DB::raw('final_amount - outstanding_balance'),
            'issued_at' => DB::raw('created_at'),
        ]);

        $this->addClinicColumn('patient_billing_items');
        Schema::table('patient_billing_items', function (Blueprint $table) {
            $table->string('item_type', 30)->default('service')->after('clinic_id');
            $table->string('description')->nullable()->after('item_type');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_rate');
            $table->nullableMorphs('source');
            $table->dropForeign(['clinical_procedure_id']);
        });
        Schema::table('patient_billing_items', function (Blueprint $table) {
            $table->unsignedBigInteger('clinical_procedure_id')->nullable()->change();
            $table->foreign('clinical_procedure_id')->references('id')->on('clinical_procedures')->restrictOnDelete();
        });
        DB::table('patient_billing_items')->whereNull('description')->update([
            'description' => DB::raw('(select clinical_procedures.name from clinical_procedures where clinical_procedures.id = patient_billing_items.clinical_procedure_id)'),
        ]);

        $this->addClinicColumn('patient_billing_payments', withBranch: true);
        Schema::table('patient_billing_payments', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->after('branch_id');
            $table->timestamp('paid_at')->nullable()->after('transaction_reference');
            $table->text('notes')->nullable();
            $table->unique(['branch_id', 'receipt_number'], 'patient_billing_payments_branch_receipt_unique');
        });
        DB::table('patient_billing_payments')->whereNull('paid_at')->update(['paid_at' => DB::raw('created_at')]);

        $this->addClinicColumn('patient_billing_refunds', withBranch: true);
        Schema::table('patient_billing_refunds', function (Blueprint $table) {
            $table->string('refund_number')->nullable()->after('branch_id');
            $table->foreignId('patient_billing_payment_id')->nullable()->after('patient_billing_id')->constrained('patient_billing_payments')->restrictOnDelete();
            $table->string('payment_method')->nullable()->after('amount');
            $table->unique(['branch_id', 'refund_number'], 'patient_billing_refunds_branch_refund_unique');
        });

        foreach (self::CLINIC_NUMBERS as $table => [$column, $globalIndex]) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $globalIndex) {
                $blueprint->dropUnique($globalIndex);
                $blueprint->unique(['clinic_id', $column], "{$table}_clinic_number_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::CLINIC_NUMBERS as $table => [$column, $globalIndex]) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $globalIndex) {
                $blueprint->unique([$column], $globalIndex);
                $blueprint->dropUnique("{$table}_clinic_number_unique");
            });
        }

        Schema::table('patient_billing_refunds', function (Blueprint $table) {
            $table->dropUnique('patient_billing_refunds_branch_refund_unique');
            $table->dropConstrainedForeignId('patient_billing_payment_id');
            $table->dropColumn(['refund_number', 'payment_method']);
        });
        $this->dropClinicColumn('patient_billing_refunds', withBranch: true);

        Schema::table('patient_billing_payments', function (Blueprint $table) {
            $table->dropUnique('patient_billing_payments_branch_receipt_unique');
            $table->dropColumn(['receipt_number', 'paid_at', 'notes']);
        });
        $this->dropClinicColumn('patient_billing_payments', withBranch: true);

        Schema::table('patient_billing_items', function (Blueprint $table) {
            $table->dropForeign(['clinical_procedure_id']);
            $table->dropMorphs('source');
            $table->dropColumn(['item_type', 'description', 'tax_rate', 'tax_amount']);
        });
        Schema::table('patient_billing_items', function (Blueprint $table) {
            $table->unsignedBigInteger('clinical_procedure_id')->nullable(false)->change();
            $table->foreign('clinical_procedure_id')->references('id')->on('clinical_procedures')->cascadeOnDelete();
        });
        $this->dropClinicColumn('patient_billing_items');

        Schema::table('patient_billings', function (Blueprint $table) {
            $table->dropUnique('patient_billings_id_clinic_unique');
            $table->dropUnique('patient_billings_branch_number_unique');
            $table->unique('billing_number', 'patient_billings_billing_number_unique');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['tax_amount', 'paid_amount', 'refunded_amount', 'financial_year', 'notes', 'issued_at', 'cancelled_at', 'cancellation_reason']);
        });
    }

    /**
     * Clinical documents whose number becomes unique per clinic:
     * `table => [number column, the global unique index it replaces]`.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CLINIC_NUMBERS = [
        'clinical_encounters' => ['encounter_number', 'clinical_encounters_encounter_number_unique'],
        'prescriptions' => ['prescription_number', 'prescriptions_prescription_number_unique'],
        'clinical_orders' => ['order_number', 'clinical_orders_order_number_unique'],
        'lab_orders' => ['lab_order_number', 'lab_orders_lab_order_number_unique'],
        'xray_records' => ['xray_number', 'xray_records_xray_number_unique'],
    ];

    /**
     * Add `clinic_id` (and optionally `branch_id`) to a bill's child table,
     * filled from the bill, NOT NULL, and tied to the bill's own clinic.
     */
    private function addClinicColumn(string $table, bool $withBranch = false): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($withBranch) {
            $blueprint->foreignId('clinic_id')->nullable()->after('patient_billing_id')->constrained('clinics')->cascadeOnDelete();
            if ($withBranch) {
                $blueprint->foreignId('branch_id')->nullable()->after('clinic_id')->constrained('branches')->restrictOnDelete();
            }
        });

        DB::table($table)->update(array_filter([
            'clinic_id' => DB::raw("(select patient_billings.clinic_id from patient_billings where patient_billings.id = {$table}.patient_billing_id)"),
            'branch_id' => $withBranch ? DB::raw("(select patient_billings.branch_id from patient_billings where patient_billings.id = {$table}.patient_billing_id)") : null,
        ]));

        Schema::table($table, function (Blueprint $blueprint) use ($table, $withBranch) {
            $blueprint->unsignedBigInteger('clinic_id')->nullable(false)->change();
            if ($withBranch) {
                $blueprint->unsignedBigInteger('branch_id')->nullable(false)->change();
            }
            $blueprint->foreign(['patient_billing_id', 'clinic_id'], "{$table}_same_clinic_fk")
                ->references(['id', 'clinic_id'])->on('patient_billings')->cascadeOnDelete();
        });
    }

    private function dropClinicColumn(string $table, bool $withBranch = false): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table, $withBranch) {
            $blueprint->dropForeign("{$table}_same_clinic_fk");
            $blueprint->dropConstrainedForeignId('clinic_id');
            if ($withBranch) {
                $blueprint->dropConstrainedForeignId('branch_id');
            }
        });
    }
};
