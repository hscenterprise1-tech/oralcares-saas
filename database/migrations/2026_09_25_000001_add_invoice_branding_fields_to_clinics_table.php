<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema-only invoice branding, ahead of the Billing module itself.
 *
 * `legal_name` is deliberately its own column rather than a reuse of
 * `organizations.legal_name` — that field belongs to Company HQ's CRM
 * account record, not something a clinic admin can edit, and the name a
 * clinic wants printed on its own invoices is a clinic-level concern.
 *
 * Nothing reads these yet: no Billing/Invoice controller or page exists
 * (only an unrelated `Invoice` JSON endpoint on `Patient360Controller`, see
 * `Invoice` vs `PatientBilling` — that model choice is a decision for
 * whenever the Billing module itself is built, not this migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('display_name');
            $table->string('invoice_logo_path')->nullable()->after('legal_name');
            $table->boolean('use_clinic_logo_for_invoice')->default(true)->after('invoice_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'invoice_logo_path', 'use_clinic_logo_for_invoice']);
        });
    }
};
