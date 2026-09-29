<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of line a quotation item is, and — for an add-on-sourced one —
 * which catalogue row it came from.
 *
 * This is metadata only, never a live price source: `title`/`unit_price`/
 * `quantity`/`total` on the item itself remain the frozen snapshot exactly as
 * they were before this migration. `company_package_addon_id` lets a report
 * ask "how many quotations included Cloud Backup" without ever being joined
 * at render time — a quotation's PDF/printable output already reads only the
 * item's own columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_quotation_items', function (Blueprint $table) {
            /* 'package_base' | 'additional_users' | 'addon' | 'custom' */
            $table->string('item_type')->default('custom')->after('company_quotation_id');
            $table->foreignId('company_package_addon_id')->nullable()->after('item_type')
                ->constrained('company_package_addons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_quotation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_package_addon_id');
            $table->dropColumn('item_type');
        });
    }
};
