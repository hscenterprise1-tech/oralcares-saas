<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the lookup that resolves a contact's current campaign status.
 *
 * That status is not stored — it is the `status` of the contact's most recent
 * `marketing_campaign_recipients` row, resolved by a correlated sub-select
 * ordering on `(contact_id, updated_at DESC, id DESC)`. The table only had an
 * index on `contact_id` alone, so every lookup found the contact's rows and
 * then sorted them.
 *
 * One contact usually has few campaign rows, so the sort was cheap — but the
 * contacts list runs this once per row and the batch summary runs it across a
 * whole import, which at a thousand contacts a batch is where it starts to
 * show. The composite index lets the engine take the first row and stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->index(['contact_id', 'updated_at', 'id'], 'mcr_contact_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('mcr_contact_latest_idx');
        });
    }
};
