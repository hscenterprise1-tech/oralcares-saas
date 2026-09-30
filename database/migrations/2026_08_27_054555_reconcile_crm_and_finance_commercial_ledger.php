<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the CRM and Finance money chains.
 *
 * Two disconnected representations of the same commercial event existed:
 *
 *   CRM      Lead → CommercialDocument(type=invoice) → Payment
 *   Finance  CompanySubscription → CommercialInvoice → CommercialPayment
 *
 * with no foreign key between them, so an invoice raised against a deal never
 * appeared in Finance and the two could never be reconciled.
 *
 * Finance is the ledger. This adds the back-references that let a CRM
 * commercial event be projected into it exactly once, and relaxes `clinic_id`
 * to nullable because a deal is legitimately invoiced *before* the tenant
 * exists — the normal sequence is invoice → payment → won → provision.
 * CustomerProvisioningEngine backfills the tenant when it appears.
 *
 * Additive and reversible. No CRM table or page is removed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->foreignId('commercial_document_id')
                ->nullable()
                ->after('company_subscription_id')
                ->constrained('commercial_documents')
                ->nullOnDelete();

            $table->unique('commercial_document_id', 'commercial_invoices_document_unique');
        });

        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->foreignId('payment_id')
                ->nullable()
                ->after('commercial_invoice_id')
                ->constrained('payments')
                ->nullOnDelete();

            $table->unique('payment_id', 'commercial_payments_payment_unique');
        });

        // A CRM-originated invoice has no tenant until the deal is won.
        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->change();
        });

        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->dropUnique('commercial_payments_payment_unique');
            $table->dropForeign(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->dropUnique('commercial_invoices_document_unique');
            $table->dropForeign(['commercial_document_id']);
            $table->dropColumn('commercial_document_id');
        });

        // Restoring NOT NULL is only safe once tenantless rows are gone.
        Schema::table('commercial_invoices', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable(false)->change();
        });

        Schema::table('commercial_payments', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable(false)->change();
        });
    }
};
