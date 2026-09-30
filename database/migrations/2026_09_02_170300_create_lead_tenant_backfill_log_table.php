<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the tenant backfill actually changed.
 *
 * The backfill derives a tenant per row rather than applying one value, so
 * rolling it back cannot be a single UPDATE. This records the rows it touched
 * so `down()` restores exactly those and nothing else — a deal that already
 * had a tenant is never handed a null by a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_tenant_backfill_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_tenant_backfill_log');
    }
};
