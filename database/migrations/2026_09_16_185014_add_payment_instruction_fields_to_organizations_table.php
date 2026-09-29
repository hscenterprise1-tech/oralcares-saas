<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer's money should go, and the seller's own tax identity.
 *
 * `organizations` already carries `gst`, `address`, `city`, `state`, `country`
 * for every organisation — customer and provider alike — via
 * `OrganizationRole`. The one organisation with `role = provider` is
 * OralCares itself; this adds the fields only that row has any use for, so
 * the quotation PDF and tax invoice can read the seller's own bank/UPI
 * details and GSTIN from the same place they already read its address,
 * rather than a parallel config file duplicating identity the schema already
 * models.
 *
 * All nullable: a provider organisation with none of this filled in still
 * works exactly as before, and the document layer renders an honest
 * "not configured" state rather than inventing a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('upi_id')->nullable()->after('gst');
            $table->string('bank_account_name')->nullable()->after('upi_id');
            $table->string('bank_account_number')->nullable()->after('bank_account_name');
            $table->string('bank_ifsc')->nullable()->after('bank_account_number');
            $table->string('bank_name')->nullable()->after('bank_ifsc');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['upi_id', 'bank_account_name', 'bank_account_number', 'bank_ifsc', 'bank_name']);
        });
    }
};
