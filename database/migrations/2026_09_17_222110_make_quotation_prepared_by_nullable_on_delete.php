<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two foreign keys cascade-delete financial records they should instead
 * survive: deleting the `company_employees` row of a rep who once prepared a
 * quotation would wipe every quotation they prepared, and deleting a lead
 * would wipe every commercial document (including invoices and credit notes)
 * raised against it. Every sibling column on both tables already uses
 * `nullOnDelete()` — this brings these two in line rather than introducing a
 * new rule.
 *
 * The FK is dropped before the column is widened to nullable, in a separate
 * `Schema::table()` call each — MySQL refuses to alter a column that is
 * still bound by a foreign key in the same ALTER statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropForeign(['prepared_by_id']);
        });

        Schema::table('company_quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('prepared_by_id')->nullable()->change();
            $table->foreign('prepared_by_id')->references('id')->on('company_employees')->nullOnDelete();
        });

        Schema::table('commercial_documents', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('commercial_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable()->change();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropForeign(['prepared_by_id']);
        });

        Schema::table('company_quotations', function (Blueprint $table) {
            $table->unsignedBigInteger('prepared_by_id')->nullable(false)->change();
            $table->foreign('prepared_by_id')->references('id')->on('company_employees')->cascadeOnDelete();
        });

        Schema::table('commercial_documents', function (Blueprint $table) {
            $table->dropForeign(['lead_id']);
        });

        Schema::table('commercial_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id')->nullable(false)->change();
            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
        });
    }
};
